<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Corpus\CanonLaw\CanonLawEditions;
use TAW\Core\Corpus\CanonLaw\CanonLawReader;
use TAW\Core\Corpus\CanonLaw\CanonLawReaderInterface;
use TAW\Core\Corpus\CanonLaw\MysqlCanonLawReader;
use TAW\Core\Form\RateLimiter;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Core\Storage\ProtectedSqlite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /wp-json/taw/v1/canon-law/*` — read-only REST surface over an
 * installed Code of Canon Law, one edition at a time. Same backend
 * selection (SQLite when this edition is installed there and `pdo_sqlite`
 * works, MySQL otherwise), rate limits, and opt-in posture as
 * {@see CatechismEndpoint}.
 *
 *  - `editions` — every registered edition, plus `installed` and the
 *    installed export's `release_channel` (a theme shows a "beta" note
 *    from it, and the note disappears on its own once a stable export is
 *    installed);
 *  - `{edition}/divisions` — the whole division tree;
 *  - `{edition}/divisions/{id}` — one division's own canons;
 *  - `{edition}/canons?numbers=1055,1056` — canons by number (≤ 50);
 *  - `{edition}/search?q=` — full-text search.
 *
 * Opt-in — no-op unless {@see self::enable()} was called.
 */
final class CanonLawEndpoint
{
    private const NAMESPACE = 'taw/v1';
    private const READ_RATE_LIMIT_MAX = 120;
    private const READ_RATE_LIMIT_WINDOW = 600;
    private const SEARCH_RATE_LIMIT_MAX = 30;
    private const SEARCH_RATE_LIMIT_WINDOW = 600;

    private static bool $enabled = false;

    /**
     * Opt-in to the Canon Law REST surface.
     * Call this in the theme's customizations.php before Theme::boot().
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        $edition = ['required' => true, 'sanitize_callback' => 'sanitize_title'];

        register_rest_route(self::NAMESPACE, '/canon-law/editions', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'editions'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/canon-law/(?P<edition>[a-z0-9-]+)/divisions', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'divisions'],
            'permission_callback' => '__return_true',
            'args' => ['edition' => $edition],
        ]);

        register_rest_route(self::NAMESPACE, '/canon-law/(?P<edition>[a-z0-9-]+)/divisions/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'division'],
            'permission_callback' => '__return_true',
            'args' => [
                'edition' => $edition,
                'id' => [
                    'required' => true,
                    'validate_callback' => static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/canon-law/(?P<edition>[a-z0-9-]+)/canons', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'canons'],
            'permission_callback' => '__return_true',
            'args' => [
                'edition' => $edition,
                'numbers' => [
                    'required' => true,
                    'validate_callback' => static fn (mixed $value): bool => is_string($value) && preg_match('/^\d+(,\d+)*$/', $value) === 1,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/canon-law/(?P<edition>[a-z0-9-]+)/search', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'search'],
            'permission_callback' => '__return_true',
            'args' => [
                'edition' => $edition,
                'q' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
                ],
                'limit' => [
                    'required' => false,
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public function editions(): \WP_REST_Response
    {
        $editions = [];
        foreach (CanonLawEditions::all() as $entry) {
            $installed = self::corpusInstalled($entry['slug']);
            $meta = $installed ? self::reader($entry['slug'])->meta($entry['slug']) : [];
            $editions[] = $entry + [
                'installed' => $installed,
                'release_channel' => $meta['release_channel'] ?? null,
                'source_revision' => $meta['source_revision'] ?? null,
                'generated_at' => $meta['generated_at'] ?? null,
            ];
        }

        return new \WP_REST_Response($editions, 200);
    }

    public function divisions(\WP_REST_Request $request): \WP_REST_Response
    {
        $edition = (string) $request->get_param('edition');
        $blocked = $this->guard($edition, 'canon_law_read', self::READ_RATE_LIMIT_MAX, self::READ_RATE_LIMIT_WINDOW);

        return $blocked ?? new \WP_REST_Response(self::reader($edition)->divisions($edition), 200);
    }

    public function division(\WP_REST_Request $request): \WP_REST_Response
    {
        $edition = (string) $request->get_param('edition');
        $blocked = $this->guard($edition, 'canon_law_read', self::READ_RATE_LIMIT_MAX, self::READ_RATE_LIMIT_WINDOW);
        if ($blocked !== null) {
            return $blocked;
        }

        $result = self::reader($edition)->division($edition, (int) $request->get_param('id'));

        return $result === null
            ? new \WP_REST_Response(['error' => 'Division not found.'], 404)
            : new \WP_REST_Response($result, 200);
    }

    public function canons(\WP_REST_Request $request): \WP_REST_Response
    {
        $edition = (string) $request->get_param('edition');
        $blocked = $this->guard($edition, 'canon_law_read', self::READ_RATE_LIMIT_MAX, self::READ_RATE_LIMIT_WINDOW);
        if ($blocked !== null) {
            return $blocked;
        }

        $numbers = array_map('intval', explode(',', (string) $request->get_param('numbers')));

        return new \WP_REST_Response(self::reader($edition)->canons($edition, $numbers), 200);
    }

    public function search(\WP_REST_Request $request): \WP_REST_Response
    {
        $edition = (string) $request->get_param('edition');
        $blocked = $this->guard($edition, 'canon_law_search', self::SEARCH_RATE_LIMIT_MAX, self::SEARCH_RATE_LIMIT_WINDOW);
        if ($blocked !== null) {
            return $blocked;
        }

        return new \WP_REST_Response(self::reader($edition)->searchCanons(
            $edition,
            (string) $request->get_param('q'),
            (int) $request->get_param('limit')
        ), 200);
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    private function guard(string $edition, string $bucket, int $max, int $window): ?\WP_REST_Response
    {
        if (!CanonLawEditions::exists($edition)) {
            return new \WP_REST_Response(['error' => 'Unknown canon law edition.'], 404);
        }
        if (!self::corpusInstalled($edition)) {
            return new \WP_REST_Response(['error' => 'This canon law edition is not installed.'], 404);
        }
        if (RateLimiter::tooManyAttempts($bucket, SubmissionsHandler::getUserIp(), $max, $window)) {
            return new \WP_REST_Response(['error' => 'Too many requests. Please try again shortly.'], 429);
        }

        return null;
    }

    /**
     * Whether the edition is installed under either backend — SQLite
     * checked first, same as {@see CatechismEndpoint::corpusInstalled()}.
     */
    public static function corpusInstalled(string $edition): bool
    {
        if (ProtectedSqlite::isAvailable() && CanonLawReader::isInstalled($edition)) {
            return true;
        }

        return MysqlCanonLawReader::isInstalled($edition);
    }

    /**
     * The reader for this edition, filterable via
     * `taw_corpus_canon_law_reader` — same per-edition SQLite check as
     * {@see CatechismEndpoint::reader()} (an edition may exist only in
     * MySQL even on a host with `pdo_sqlite`). Public so
     * {@see \TAW\Core\Rag\Tools\CanonLawLookupTool} resolves the same one.
     */
    public static function reader(string $edition): CanonLawReaderInterface
    {
        $default = (ProtectedSqlite::isAvailable() && CanonLawReader::isInstalled($edition))
            ? new CanonLawReader()
            : new MysqlCanonLawReader();

        $reader = apply_filters('taw_corpus_canon_law_reader', $default);

        return $reader instanceof CanonLawReaderInterface ? $reader : $default;
    }
}
