<?php

declare(strict_types=1);

/**
 * Performance Optimizations
 * 
 * Resource hints, script/style tweaks, and other PageSpeed improvements
 * 
 * @package TAW
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Resource Hints - Preconnect & DNS-Prefetch
 * 
 * Fires early in <head> so the browser starts connections
 * before it discovers the actual resource requests.
 * 
 * Add any external origins your theme depends on.
 */
add_action('wp_head', function () {
    $preconnects = [
        // Add external domains your theme uses, for example:
        // 'https://fonts.googleapis.com',
        // 'https://fonts.gstatic.com',
        // 'https://cdn.jsdelivr.net',
        // 'https://www.youtube.com',
    ];

    foreach ($preconnects as $origin) {
        printf(
            '<link rel="preconnect" href="%s" crossorigin>' . "\n",
            esc_url($origin)
        );
    }
}, 1); // Priority 1 = very early in <head>

/**
 * Remove WordPress Frontend Bloat
 * 
 * Strips out default scripts, styles, and meta tags that aren't needed
 * when the theme handles its own markup and components.
 */
add_action('wp_enqueue_scripts', function () {

    // Gutenberg block lubrary CSS - Not needed if we're building our own blocks and styles
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('classic-theme-styles');
    wp_dequeue_style('global-styles'); // Global styles generatef by theme.json / FSE
}, 100); // Priority 100 = runs AFTER everything is enqueued, so we can dequeue reliably

/**
 * Remove emoji detection script and styles.
 * Saves ~20KB of inline JS and a CSS request on every page load.
 */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
});

/**
 * Remove meta tags that serve no purpose for modern themes.
 *
 * - rsd_link: XML-RPC discovery (needed only for remote publishing clients)
 * - wlwmanifest: Windows Live Writer support (discontinued in 2017)
 * - wp_shortlink: shortlink meta tag (redundant with pretty permalinks)
 * - rest_output_link_wp_head: REST API discovery link
 */
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'rest_output_link_wp_head');

/**
 * Disable oEmbed discovery and scripts.
 * Only needed if you want auto-embeds (tweets, YouTube) in the_content().
 */
remove_action('wp_head', 'wp_oembed_add_discovery_links');
remove_action('wp_head', 'wp_oembed_add_host_js');

/**
 * Preload Critical Fonts
 *
 * Fonts are discovered late (HTML → CSS → @font-face → download).
 * Preloading tells the browser to start fetching the font file
 * immediately, cutting out the CSS-parsing delay.
 *
 * Only preload fonts used above the fold (typically 1-2 files).
 * Over-preloading wastes bandwidth and hurts performance.
 *
 * Works with both Vite-hashed fonts (resources/fonts/) and
 * static fonts (resources/static/fonts/) — the vite_asset_url()
 * helper resolves the correct path automatically.
 *
 * crossorigin is REQUIRED for font preloads, even same-origin.
 * Without it, the browser fetches the font twice.
 */
// Uncomment when you add self-hosted fonts:
add_action('wp_head', function () {
    $fonts = [
        'resources/fonts/Roboto-Regular.woff2',
        'resources/fonts/Roboto-Bold.woff2',
    ];

    foreach ($fonts as $font_path) {
        printf(
            '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
            esc_url(vite_asset_url($font_path))
        );
    }
}, 1);

/**
 * Preload images
 * 
 * An example of preloading the hero image on the homepage.
 * Add additional preloads for other critical images on other pages (preferably 1 per page).
 */
add_action('wp_head', function () {
    if (!is_front_page()) return;

    $hero_id = (int) \TAW\Core\Metabox\Metabox::get(get_the_ID(), 'hero_image_url', '_taw_');

    if ($hero_id) {
        echo \TAW\Helpers\Image::preload_tag($hero_id, 'full');
    }
}, 2);
