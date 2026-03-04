<?php

declare(strict_types=1);

namespace TAW\Core;

use TAW\Core\Rest\VisualEditorEndpoint;
use TAW\Core\Rest\SearchEndpoints;
use TAW\Support\Performance;

/**
 * Theme — the single entry point for wiring TAW Core into a WordPress theme.
 *
 * Drop one line in your functions.php (after requiring the Composer autoloader)
 * and the entire framework boots itself:
 *
 *   TAW\Core\Theme::boot();
 *
 * Optionally tune performance settings before or after boot():
 *
 *   TAW\Core\Theme::performance([
 *       'preconnect_origins' => ['https://fonts.googleapis.com'],
 *       'preload_fonts'      => ['resources/fonts/MyFont-Regular.woff2'],
 *       'remove_emoji'       => false,
 *   ]);
 */
class Theme
{
    /**
     * Guard against being booted more than once.
     */
    private static bool $booted = false;

    /**
     * Boot the TAW Core framework.
     *
     * Wires up, in order:
     *   1. Block auto-discovery from the theme's /Blocks directory
     *   2. Theme asset pipeline (Vite HMR in dev, hashed manifest in prod)
     *   3. Queued block asset enqueuing (for FAUC-free above-the-fold blocks)
     *   4. Visual Editor (admin bar button + frontend editing shell)
     *   5. REST API endpoints (visual editor save, post search)
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        // ── 1. Blocks ──────────────────────────────────────────────────────────
        // Auto-discover and register every block found under the theme's /Blocks
        // directory. Runs on after_setup_theme so get_template_directory() is set.
        add_action('after_setup_theme', [BlockLoader::class, 'loadAll']);

        // ── 2. Theme assets ────────────────────────────────────────────────────
        // Enqueue the theme's main JS/CSS bundle.
        // Dev: served live from the Vite dev server with HMR.
        // Prod: resolved from /public/build/manifest.json with cache-busted hashes.
        add_action('wp_enqueue_scripts', 'vite_enqueue_theme_assets');

        // ── 3. Block assets ────────────────────────────────────────────────────
        // Enqueue per-block CSS/JS for every block that was queued via
        // BlockRegistry::queue('hero', 'cta', ...) before get_header().
        // This puts block styles in <head> and prevents flash of unstyled content.
        add_action('wp_enqueue_scripts', [BlockRegistry::class, 'enqueueQueuedAssets']);

        // ── 4. Visual Editor ───────────────────────────────────────────────────
        // Registers the "Edit Visually" admin bar button and, when the
        // ?taw_visual_edit=1 query param is present, injects the editor
        // shell + assets into the frontend.
        VisualEditor::init();

        // ── 5. REST endpoints ──────────────────────────────────────────────────
        // Each class registers its routes via rest_api_init in its constructor.
        new VisualEditorEndpoint();
        new SearchEndpoints();
    }

    /**
     * Configure performance optimizations.
     *
     * Convenience pass-through to TAW\Support\Performance::configure().
     * Safe to call before or after boot() — settings are merged before
     * any WordPress hooks fire.
     *
     * Available options (all optional, shown with their defaults):
     *
     *   'remove_bloat'       => true,   // Strip Gutenberg/FSE stylesheets
     *   'remove_emoji'       => true,   // Remove ~20 KB emoji detection script
     *   'remove_meta_tags'   => true,   // Strip legacy <head> meta tags
     *   'remove_oembed'      => true,   // Disable oEmbed discovery
     *   'preconnect_origins' => [],     // External origins to preconnect
     *   'preload_fonts'      => [],     // Self-hosted font paths to preload
     *   'preload_hero_image' => true,   // Preload front-page hero image
     *
     * @param array<string, mixed> $config Partial config — unspecified keys keep their defaults.
     */
    public static function performance(array $config): void
    {
        Performance::configure($config);
    }
}
