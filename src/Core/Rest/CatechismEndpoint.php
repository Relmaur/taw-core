<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Corpus\Catechism\CatechismEditions;
use TAW\Core\Corpus\Catechism\CatechismReader;
use TAW\Core\Corpus\Catechism\CatechismReaderInterface;
use TAW\Core\Corpus\Catechism\MysqlCatechismReader;
use TAW\Core\Form\RateLimiter;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Core\Storage\ProtectedSqlite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /wp-json/taw/v1/catechism/*` — read-only REST surface over an
 * installed catechism corpus, for one edition at a time. Mirrors
 * {@see BibleEndpoint} (read that class's docblock for the full backend-
 * selection rationale — SQLite when `pdo_sqlite` is available, MySQL
 * fallback otherwise, same posture unchanged here) with routes one level
 * deeper to match the catechism's own part → section → chapter →
 * question/answer structure.
 *
 * Opt-in — no-op unless {@see self::enable()} was called.
 */
final class CatechismEndpoint
{
    private const NAMESPACE = 'taw/v1';
    private const READ_RATE_LIMIT_MAX = 120;
    private const READ_RATE_LIMIT_WINDOW = 600;
    private const SEARCH_RATE_LIMIT_MAX = 30;
    private const SEARCH_RATE_LIMIT_WINDOW = 600;

    private static bool $enabled = false;

    /**
     * Opt-in to the Catechism REST surface.
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
        register_rest_route(self::NAMESPACE, '/catechism/editions', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'editions'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/catechism/(?P<edition>[a-z0-9-]+)/parts', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'parts'],
            'permission_callback' => '__return_true',
            'args' => [
                'edition' => ['required' => true, 'sanitize_callback' => 'sanitize_title'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/catechism/(?P<edition>[a-z0-9-]+)/chapters/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'chapter'],
            'permission_callback' => '__return_true',
            'args' => [
                'edition' => ['required' => true, 'sanitize_callback' => 'sanitize_title'],
                'id' => [
                    'required' => true,
                    'validate_callback' => static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/catechism/(?P<edition>[a-z0-9-]+)/search', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'search'],
            'permission_callback' => '__return_true',
            'args' => [
                'edition' => ['required' => true, 'sanitize_callback' => 'sanitize_title'],
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
        return new \WP_REST_Response(CatechismEditions::all(), 200);
    }

    public function parts(\WP_REST_Request $request): \WP_REST_Response
    {
        $edition = (string) $request->get_param('edition');
        $notFound = $this->requireInstalledEdition($edition);
        if ($notFound !== null) {
            return $notFound;
        }
        if ($this->rateLimited('catechism_read', self::READ_RATE_LIMIT_MAX, self::READ_RATE_LIMIT_WINDOW)) {
            return self::tooManyRequests();
        }

        return new \WP_REST_Response(self::reader($edition)->parts($edition), 200);
    }

    public function chapter(\WP_REST_Request $request): \WP_REST_Response
    {
        $edition = (string) $request->get_param('edition');
        $notFound = $this->requireInstalledEdition($edition);
        if ($notFound !== null) {
            return $notFound;
        }
        if ($this->rateLimited('catechism_read', self::READ_RATE_LIMIT_MAX, self::READ_RATE_LIMIT_WINDOW)) {
            return self::tooManyRequests();
        }

        $result = self::reader($edition)->chapter($edition, (int) $request->get_param('id'));

        if ($result === null) {
            return new \WP_REST_Response(['error' => 'Chapter not found.'], 404);
        }

        return new \WP_REST_Response($result, 200);
    }

    public function search(\WP_REST_Request $request): \WP_REST_Response
    {
        $edition = (string) $request->get_param('edition');
        $notFound = $this->requireInstalledEdition($edition);
        if ($notFound !== null) {
            return $notFound;
        }
        if ($this->rateLimited('catechism_search', self::SEARCH_RATE_LIMIT_MAX, self::SEARCH_RATE_LIMIT_WINDOW)) {
            return self::tooManyRequests();
        }

        $results = self::reader($edition)->searchParagraphs(
            $edition,
            (string) $request->get_param('q'),
            (int) $request->get_param('limit')
        );

        return new \WP_REST_Response($results, 200);
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    private function requireInstalledEdition(string $edition): ?\WP_REST_Response
    {
        if (!CatechismEditions::exists($edition)) {
            return new \WP_REST_Response(['error' => 'Unknown catechism edition.'], 404);
        }
        if (!self::corpusInstalled($edition)) {
            return new \WP_REST_Response(['error' => 'This catechism edition is not installed.'], 404);
        }

        return null;
    }

    private function rateLimited(string $bucket, int $max, int $window): bool
    {
        return RateLimiter::tooManyAttempts($bucket, SubmissionsHandler::getUserIp(), $max, $window);
    }

    private static function tooManyRequests(): \WP_REST_Response
    {
        return new \WP_REST_Response(['error' => 'Too many requests. Please try again shortly.'], 429);
    }

    /**
     * Whether the given edition is installed under either backend — same
     * SQLite-checked-first posture as {@see BibleEndpoint::corpusInstalled()}.
     */
    private static function corpusInstalled(string $edition): bool
    {
        if (ProtectedSqlite::isAvailable() && CatechismReader::isInstalled($edition)) {
            return true;
        }

        return MysqlCatechismReader::isInstalled($edition);
    }

    /**
     * Resolves the reader this endpoint queries against, filterable so a
     * theme can swap in a differently-installed reader — same pattern as
     * {@see BibleEndpoint::reader()}, including that same class's care to
     * check *this edition* is actually installed under SQLite, not just
     * that `pdo_sqlite` is available on the host (an edition could exist
     * only via the MySQL path even on a host that otherwise has
     * `pdo_sqlite`, e.g. mid-migration) — `$edition` is required here for
     * exactly that reason, even though the reader instance returned is
     * itself edition-agnostic (every interface method takes `$edition` as
     * an argument).
     */
    private static function reader(string $edition): CatechismReaderInterface
    {
        $default = (ProtectedSqlite::isAvailable() && CatechismReader::isInstalled($edition))
            ? new CatechismReader()
            : new MysqlCatechismReader();

        $reader = apply_filters('taw_corpus_catechism_reader', $default);

        return $reader instanceof CatechismReaderInterface ? $reader : $default;
    }
}
