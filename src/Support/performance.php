<?php

declare(strict_types=1);

namespace TAW\Support;

use TAW\Helpers\Framework;

/**
 * Performance — configurable WordPress optimizations.
 *
 * All settings have sensible defaults. Override any of them in your theme's
 * functions.php (after requiring the autoloader) before WordPress fires its hooks:
 *
 *   TAW\Support\Performance::configure([
 *       'preconnect_origins' => ['https://fonts.googleapis.com', 'https://fonts.gstatic.com'],
 *       'preload_fonts'      => ['resources/fonts/MyFont-Regular.woff2'],
 *       'preload_images'     => [[$hero_id, 'full']],
 *       'remove_emoji'       => false,
 *   ]);
 *
 * Only the keys you supply are changed — unspecified keys keep their defaults.
 */
class Performance
{
    /** Tracks whether wp_head has already fired. */
    private static bool $head_fired = false;

    private static array $config = [
        /**
         * Strip Gutenberg block library CSS, classic-theme-styles, and global-styles
         * (theme.json / FSE) from the frontend.
         * Disable if your theme relies on any of these stylesheets.
         */
        'remove_bloat' => true,

        /**
         * Remove the emoji detection script and its companion CSS.
         * Saves ~20 KB of inline JS + one CSS request per page.
         */
        'remove_emoji' => true,

        /**
         * Strip legacy <head> meta tags:
         *   rsd_link, wlwmanifest_link, wp_shortlink_wp_head, rest_output_link_wp_head.
         */
        'remove_meta_tags' => true,

        /**
         * Disable oEmbed discovery links and the host JS.
         * Set to false if you use auto-embeds (tweets, YouTube) in post content.
         */
        'remove_oembed' => true,

        /**
         * External origins to preconnect.
         * The browser starts the TCP/TLS handshake before it discovers the actual
         * resource requests, cutting perceived load time.
         *
         * Example: ['https://fonts.googleapis.com', 'https://fonts.gstatic.com']
         */
        'preconnect_origins' => [],

        /**
         * Self-hosted font files to preload (resolved via ViteLoader::assetUrl()).
         * crossorigin is required for font preloads, even for same-origin files.
         * Only preload fonts used above the fold — over-preloading wastes bandwidth.
         *
         * Example: ['resources/fonts/Roboto-Regular.woff2', 'resources/fonts/Roboto-Bold.woff2']
         */
        'preload_fonts' => [],

        /**
         * WordPress attachment images to preload, as [$attachment_id, $size] tuples.
         * Limit to 1-2 above-the-fold images (hero, banner) — over-preloading is counterproductive.
         *
         * Example: [[$hero_id, 'full'], [$banner_id, 'large']]
         */
        'preload_images' => [],

        /**
         * Inject long-lived cache headers for font files into the root .htaccess
         * on theme activation via after_switch_theme.
         *
         * Vite content-hashes font filenames, so `immutable` is safe. Set to false
         * on managed hosts (WPMUDEV, WP Engine, Kinsta) that handle caching at the
         * server/CDN level — the block is harmless there but unnecessary.
         */
        'font_cache_htaccess' => true,

        /**
         * Inject long-lived, immutable cache headers for Vite's entire hashed
         * build-output directory (JS, CSS, fonts, and anything else Vite emits)
         * via a .htaccess scoped to that directory alone — not the site root.
         *
         * Deliberately scoped rather than matched by extension in the root
         * .htaccess: every file Vite writes there is content-hashed, so
         * `immutable` is provably safe for the whole directory, but
         * wp-content/uploads/ images share the same extensions and are NOT
         * hashed — an extension-based root rule would risk serving a stale
         * image after a re-upload. This key is independent of
         * `font_cache_htaccess` above (which still writes its own root-level,
         * font-only block); on Apache both can run without conflict.
         *
         * No effect on nginx — .htaccess is an Apache-only mechanism. See
         * README.md § Performance for the nginx equivalent.
         */
        'build_asset_cache_htaccess' => true,

        /**
         * Generate newly-uploaded JPEG/PNG image subsizes as AVIF or WebP
         * instead of the original format, using WordPress core's own
         * `image_editor_output_format` filter (added 5.8) — no plugin.
         *
         * Prefers AVIF, falls back to WebP, and does nothing at all if the
         * host's image library (Imagick/GD) can't actually encode either —
         * checked at runtime via `wp_image_editor_supports()`, never assumed.
         * This is a REPLACE, not a dual-format generation: core has no
         * built-in mechanism to save both the original and a modern-format
         * sibling side by side, so the resulting subsizes simply ARE
         * AVIF/WebP — `wp_get_attachment_image_src()` and everything built on
         * it (including `TAW\Helpers\Image::render()`) picks this up
         * automatically, no template changes needed. Safe without a
         * fallback: AVIF/WebP support has been universal in shipping
         * browsers for years.
         *
         * Only affects sizes generated going forward (new uploads, or
         * `Regenerate Thumbnails`-style regeneration) — does not retroactively
         * touch existing media library files.
         *
         * Deliberately excludes animated GIFs (never in scope — this filter
         * only ever sees `image/jpeg` and `image/png`).
         */
        'modern_image_formats' => true,

    ];

    /**
     * Override one or more default settings.
     *
     * Call this in your theme's functions.php after requiring the Composer autoloader.
     * Timing is safe: WordPress hooks fire well after autoload, so the updated config
     * is always in place when the callbacks run.
     *
     * @param array<string, mixed> $config Partial config — only supplied keys are changed.
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Register all WordPress hooks.
     *
     * Called automatically at the bottom of this file via the Composer `files` entry.
     * Do not call this yourself.
     */
    public static function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('wp_head', [self::class, 'renderPreconnects'], 1);
        add_action('wp_enqueue_scripts', [self::class, 'removeBloat'], 100);
        add_action('init', [self::class, 'removeEmoji']);
        add_action('after_setup_theme', [self::class, 'removeMeta']);
        add_action('wp_head', [self::class, 'renderFontPreloads'], 1);
        add_action('wp_head', [self::class, 'renderImagePreloads'], 1);
        add_action('wp_head', static function () { self::$head_fired = true; }, PHP_INT_MAX);
        add_action('after_switch_theme', [self::class, 'injectFontCacheHtaccess']);
        add_action('after_switch_theme', [self::class, 'injectBuildAssetCacheHtaccess']);
        add_action('admin_notices', [self::class, 'maybeShowNginxCacheNotice']);
        add_action('admin_init', [self::class, 'maybeDismissNginxCacheNotice']);
        add_filter('image_editor_output_format', [self::class, 'preferModernImageFormat'], 10, 3);
    }

    /**
     * Preload a WordPress attachment image.
     *
     * Safe to call from anywhere — including block render callbacks:
     *  - Before wp_head fires: queued and output in <head>.
     *  - After wp_head fires (block templates): output inline in the body,
     *    which is valid HTML5 and still triggers an early browser fetch.
     *
     * @param int    $attachment_id WordPress attachment ID.
     * @param string $size          WordPress image size. Default 'full'.
     */
    public static function preloadImage(int $attachment_id, string $size = 'full'): void
    {
        if (!self::$head_fired) {
            self::$config['preload_images'][] = [$attachment_id, $size];
            return;
        }

        // wp_head already fired — output inline (valid HTML5, browser fetches immediately)
        $tag = \TAW\Helpers\Image::preload_tag($attachment_id, $size);

        if ($tag) {
            echo $tag; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    // -------------------------------------------------------------------------
    // Hook callbacks
    // -------------------------------------------------------------------------

    /** @internal */
    public static function renderPreconnects(): void
    {
        foreach (self::$config['preconnect_origins'] as $origin) {
            printf(
                '<link rel="preconnect" href="%s" crossorigin>' . "\n",
                esc_url($origin)
            );
        }
    }

    /** @internal */
    public static function removeBloat(): void
    {
        if (!self::$config['remove_bloat']) {
            return;
        }

        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('wp-block-library-theme');
        wp_dequeue_style('classic-theme-styles');
        wp_dequeue_style('global-styles');
    }

    /** @internal */
    public static function removeEmoji(): void
    {
        if (!self::$config['remove_emoji']) {
            return;
        }

        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
    }

    /** @internal */
    public static function removeMeta(): void
    {
        if (self::$config['remove_meta_tags']) {
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'wp_shortlink_wp_head');
            remove_action('wp_head', 'rest_output_link_wp_head');
        }

        if (self::$config['remove_oembed']) {
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_oembed_add_host_js');
        }
    }

    /**
     * $filename and $mime_type are typed nullable to match WordPress core's
     * own actual calling convention — WP_Image_Editor::get_output_format()
     * declares both params nullable and passes them straight through
     * unchecked; some real subsize-generation call sites (an already-loaded
     * Imagick object with no filename reference) genuinely pass null for
     * $filename. Confirmed by a real fatal TypeError on a real media import
     * before this was made nullable — strict_types=1 does not forgive a
     * signature that's narrower than what core actually calls it with.
     *
     * @param array<string, string> $formats
     * @return array<string, string>
     * @internal
     */
    public static function preferModernImageFormat(array $formats, ?string $filename, ?string $mime_type): array
    {
        if (!self::$config['modern_image_formats']) {
            return $formats;
        }

        if (!in_array($mime_type, ['image/jpeg', 'image/png'], true)) {
            return $formats;
        }

        if (wp_image_editor_supports(['mime_type' => 'image/avif'])) {
            $formats[$mime_type] = 'image/avif';
        } elseif (wp_image_editor_supports(['mime_type' => 'image/webp'])) {
            $formats[$mime_type] = 'image/webp';
        }

        return $formats;
    }

    /** @internal */
    public static function renderImagePreloads(): void
    {
        foreach (self::$config['preload_images'] as [$id, $size]) {
            echo \TAW\Helpers\Image::preload_tag((int) $id, $size); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /** @internal */
    public static function injectFontCacheHtaccess(): void
    {
        if (!self::$config['font_cache_htaccess']) {
            return;
        }

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $htaccess = ABSPATH . '.htaccess';

        // Bail only when we genuinely can't write: an existing file that
        // isn't writable, or a not-yet-existing file whose parent directory
        // isn't writable either (insert_with_markers() creates the file
        // itself when it's missing, so a missing-but-creatable file must NOT
        // bail here — the original `!is_writable && !file_exists` check did,
        // silently skipping every fresh install with no pre-existing
        // .htaccess, which is the common case this feature most needs to
        // cover).
        if (file_exists($htaccess) && !is_writable($htaccess)) {
            return;
        }

        if (!file_exists($htaccess) && !is_writable(dirname($htaccess))) {
            return;
        }

        $rules = [
            '<IfModule mod_expires.c>',
            '    <FilesMatch "\.(woff2|woff|ttf|otf|eot)$">',
            '        ExpiresActive On',
            '        ExpiresDefault "access plus 1 year"',
            '    </FilesMatch>',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            '    <FilesMatch "\.(woff2|woff|ttf|otf|eot)$">',
            '        Header set Cache-Control "max-age=31536000, public, immutable"',
            '    </FilesMatch>',
            '</IfModule>',
        ];

        insert_with_markers($htaccess, 'TAW Font Cache', $rules);
    }

    /** @internal */
    public static function injectBuildAssetCacheHtaccess(): void
    {
        if (!self::$config['build_asset_cache_htaccess']) {
            return;
        }

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        $assetsDir = Framework::themePath(ViteLoader::distDir() . '/assets');

        // Nothing to protect yet — e.g. the theme was activated before
        // `npm run build` ever ran. Silently no-op; there's no error state
        // here, just nothing to do until the directory exists.
        if (!is_dir($assetsDir)) {
            return;
        }

        $htaccess = $assetsDir . '/.htaccess';

        if (file_exists($htaccess) && !is_writable($htaccess)) {
            return;
        }

        if (!file_exists($htaccess) && !is_writable($assetsDir)) {
            return;
        }

        // No FilesMatch needed: unlike the root .htaccess above, every file
        // in this directory IS Vite's hashed output by construction — the
        // whole directory can safely get one unconditional rule.
        $rules = [
            '<IfModule mod_expires.c>',
            '    ExpiresActive On',
            '    ExpiresDefault "access plus 1 year"',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            '    Header set Cache-Control "max-age=31536000, public, immutable"',
            '</IfModule>',
        ];

        insert_with_markers($htaccess, 'TAW Build Asset Cache', $rules);
    }

    /**
     * Warn a logged-in admin when the .htaccess-based cache headers above
     * can't possibly do anything — nginx never reads .htaccess at all, and
     * a PHP process has no equivalent mechanism to write nginx's own config.
     * The only thing the framework can actually do on that stack is tell a
     * human exactly what to paste into their server block.
     *
     * @internal
     */
    public static function maybeShowNginxCacheNotice(): void
    {
        if (!self::$config['build_asset_cache_htaccess']) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (get_option('taw_nginx_cache_notice_dismissed')) {
            return;
        }

        $serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        if (!str_contains($serverSoftware, 'nginx')) {
            return;
        }

        $assetsPath = str_replace(ABSPATH, '/', Framework::themePath(ViteLoader::distDir() . '/assets'));
        $dismissUrl = wp_nonce_url(
            add_query_arg('taw_dismiss_nginx_cache_notice', '1'),
            'taw_dismiss_nginx_cache_notice'
        );

        $snippet = sprintf(
            "location ^~ %s/ {\n    expires 1y;\n    add_header Cache-Control \"public, immutable\";\n}",
            $assetsPath
        );

        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>TAW:</strong> %s</p><pre style="background:#f0f0f1;padding:12px;overflow:auto;">%s</pre><p><a href="%s">%s</a></p></div>',
            esc_html__("Your build assets aren't getting cache headers — this site appears to run nginx, which doesn't read .htaccess. Add this to your nginx server block:", 'taw-theme'),
            esc_html($snippet),
            esc_url($dismissUrl),
            esc_html__('Dismiss', 'taw-theme')
        );
    }

    /** @internal */
    public static function maybeDismissNginxCacheNotice(): void
    {
        if (!isset($_GET['taw_dismiss_nginx_cache_notice'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('taw_dismiss_nginx_cache_notice');

        update_option('taw_nginx_cache_notice_dismissed', true);
    }

    /** @internal */
    public static function renderFontPreloads(): void
    {
        foreach (self::$config['preload_fonts'] as $font_path) {
            printf(
                '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
                esc_url(ViteLoader::assetUrl($font_path))
            );
        }
    }

}

// Bootstrap — runs once when Composer loads this file via the `files` autoload entry.
// The class is already defined above, so calling register() here is safe.
if (defined('ABSPATH')) {
    Performance::register();
}
