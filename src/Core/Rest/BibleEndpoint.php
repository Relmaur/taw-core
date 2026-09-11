<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Corpus\Bible\BibleReader;
use TAW\Core\Corpus\Bible\BibleReaderInterface;
use TAW\Core\Corpus\Bible\MysqlBibleReader;
use TAW\Core\Form\RateLimiter;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Core\Storage\ProtectedSqlite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /wp-json/taw/v1/bible/*` — read-only REST surface over an installed
 * Bible corpus. Transparently queries whichever backend is actually
 * installed: {@see BibleReader} (SQLite) when `pdo_sqlite` is available,
 * else {@see MysqlBibleReader} — some real managed hosting (confirmed:
 * WPMUdev) has no `pdo_sqlite` and declines to add it, while `$wpdb`
 * (`mysqli`) is guaranteed on every WordPress host since WP core itself
 * can't function without it.
 *
 * Opt-in — no-op unless {@see self::enable()} was called, same posture as
 * {@see \TAW\Core\Rag\RagSettings}. This is a client-specific feature (the
 * fsspx-taw Bible reader), not something every TAW site should get.
 *
 * Public Scripture text needs no auth, but every route is still rate
 * limited the same way {@see RagChatEndpoint} is — an unauthenticated
 * read endpoint is still scrapable/hammerable even when the content itself
 * carries no confidentiality concern.
 */
final class BibleEndpoint
{
    private const NAMESPACE = 'taw/v1';
    private const READ_RATE_LIMIT_MAX = 120;
    private const READ_RATE_LIMIT_WINDOW = 600;
    private const SEARCH_RATE_LIMIT_MAX = 30;
    private const SEARCH_RATE_LIMIT_WINDOW = 600;

    private static bool $enabled = false;

    /**
     * Opt-in to the Bible reader REST surface.
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
        register_rest_route(self::NAMESPACE, '/bible/books', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'books'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/bible/books/(?P<slug>[a-z0-9-]+)/chapters/(?P<chapter>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'chapter'],
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                ],
                'chapter' => [
                    'required' => true,
                    'validate_callback' => static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/bible/search', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'search'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
                ],
                'scope' => [
                    'required' => false,
                    'default' => 'verses',
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => static fn (mixed $value): bool => in_array($value, ['verses', 'notes'], true),
                ],
                'limit' => [
                    'required' => false,
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public function books(): \WP_REST_Response
    {
        if (!self::corpusInstalled()) {
            return new \WP_REST_Response(['error' => 'No Bible corpus is installed.'], 404);
        }
        if ($this->rateLimited('bible_read', self::READ_RATE_LIMIT_MAX, self::READ_RATE_LIMIT_WINDOW)) {
            return self::tooManyRequests();
        }

        return new \WP_REST_Response(self::reader()->books(), 200);
    }

    public function chapter(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!self::corpusInstalled()) {
            return new \WP_REST_Response(['error' => 'No Bible corpus is installed.'], 404);
        }
        if ($this->rateLimited('bible_read', self::READ_RATE_LIMIT_MAX, self::READ_RATE_LIMIT_WINDOW)) {
            return self::tooManyRequests();
        }

        $result = self::reader()->chapter(
            (string) $request->get_param('slug'),
            (int) $request->get_param('chapter')
        );

        if ($result === null) {
            return new \WP_REST_Response(['error' => 'Chapter not found.'], 404);
        }

        return new \WP_REST_Response($result, 200);
    }

    public function search(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!self::corpusInstalled()) {
            return new \WP_REST_Response(['error' => 'No Bible corpus is installed.'], 404);
        }
        if ($this->rateLimited('bible_search', self::SEARCH_RATE_LIMIT_MAX, self::SEARCH_RATE_LIMIT_WINDOW)) {
            return self::tooManyRequests();
        }

        $query = (string) $request->get_param('q');
        $limit = (int) $request->get_param('limit');
        $reader = self::reader();

        $results = $request->get_param('scope') === 'notes'
            ? $reader->searchNotes($query, $limit)
            : $reader->searchVerses($query, $limit);

        return new \WP_REST_Response($results, 200);
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    private function rateLimited(string $bucket, int $max, int $window): bool
    {
        return RateLimiter::tooManyAttempts($bucket, SubmissionsHandler::getUserIp(), $max, $window);
    }

    private static function tooManyRequests(): \WP_REST_Response
    {
        return new \WP_REST_Response(['error' => 'Too many requests. Please try again shortly.'], 429);
    }

    /**
     * Whether a corpus is installed under either backend. SQLite is
     * checked first — cheap (a file-existence check) and doesn't require
     * ever touching `$wpdb` on a host where the simpler path already
     * works.
     */
    private static function corpusInstalled(): bool
    {
        if (ProtectedSqlite::isAvailable() && BibleReader::isInstalled()) {
            return true;
        }

        return MysqlBibleReader::isInstalled();
    }

    /**
     * Resolves the reader this endpoint queries against — SQLite-backed
     * when `pdo_sqlite` is available on this host (unchanged default
     * behavior), MySQL-backed otherwise. Filterable so a theme can point
     * at a differently-installed corpus, or swap in an entirely different
     * reader implementation — anything implementing
     * {@see BibleReaderInterface} — without a taw-core fork.
     */
    private static function reader(): BibleReaderInterface
    {
        $default = (ProtectedSqlite::isAvailable() && BibleReader::isInstalled())
            ? new BibleReader()
            : new MysqlBibleReader();

        $reader = apply_filters('taw_corpus_bible_reader', $default);

        return $reader instanceof BibleReaderInterface ? $reader : $default;
    }
}
