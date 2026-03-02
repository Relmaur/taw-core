<?php

declare(strict_types=1);

namespace TAW\Support;

/**
 * Performance — configurable WordPress optimizations.
 *
 * All settings have sensible defaults. Override any of them in your theme's
 * functions.php (after requiring the autoloader) before WordPress fires its hooks:
 *
 *   TAW\Support\Performance::configure([
 *       'preconnect_origins' => ['https://fonts.googleapis.com', 'https://fonts.gstatic.com'],
 *       'preload_fonts'      => ['resources/fonts/MyFont-Regular.woff2'],
 *       'remove_emoji'       => false,
 *   ]);
 *
 * Only the keys you supply are changed — unspecified keys keep their defaults.
 */
class Performance
{
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
         * Self-hosted font files to preload (resolved via vite_asset_url()).
         * crossorigin is required for font preloads, even for same-origin files.
         * Only preload fonts used above the fold — over-preloading wastes bandwidth.
         *
         * Example: ['resources/fonts/Roboto-Regular.woff2', 'resources/fonts/Roboto-Bold.woff2']
         */
        'preload_fonts' => [],

        /**
         * Preload the hero image on the front page using the hero_image_url meta field.
         * Set to false if your theme manages hero image preloading itself.
         */
        'preload_hero_image' => true,
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
        add_action('wp_head', [self::class, 'renderHeroPreload'], 2);
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

    /** @internal */
    public static function renderFontPreloads(): void
    {
        foreach (self::$config['preload_fonts'] as $font_path) {
            printf(
                '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
                esc_url(vite_asset_url($font_path))
            );
        }
    }

    /** @internal */
    public static function renderHeroPreload(): void
    {
        if (!self::$config['preload_hero_image'] || !is_front_page()) {
            return;
        }

        $hero_id = (int) \TAW\Core\Metabox\Metabox::get(get_the_ID(), 'hero_image_url', '_taw_');

        if ($hero_id) {
            echo \TAW\Helpers\Image::preload_tag($hero_id, 'full');
        }
    }
}

// Bootstrap — runs once when Composer loads this file via the `files` autoload entry.
// The class is already defined above, so calling register() here is safe.
if (defined('ABSPATH')) {
    Performance::register();
}
