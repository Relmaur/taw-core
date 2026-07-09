<?php

declare(strict_types=1);

namespace TAW\Core\Theme;

use TAW\Core\Block\BlockLoader;
use TAW\Core\Block\BlockRegistry;
use TAW\Core\Editor\VisualEditor;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Core\Metabox\MetaboxOrder;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Core\Rest\SearchEndpoints;
use TAW\Core\Rest\VisualEditorEndpoint;
use TAW\Helpers\Svg;
use TAW\Support\Performance;
use TAW\Support\ViteLoader;

/**
 * Theme — the single entry point for wiring TAW Core into a WordPress theme.
 *
 * Drop one line in your functions.php (after requiring the Composer autoloader)
 * and the entire framework boots itself:
 *
 *   TAW\Core\Theme\Theme::boot();
 *
 * Optionally tune performance settings before or after boot():
 *
 *   TAW\Core\Theme\Theme::performance([
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
     *   6. SVG support (upload allowlist + inline helper)
     *   7. Form submissions (taw_submission CPT + webhook settings page)
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

        // ── 2. Vite asset pipeline ─────────────────────────────────────────────
        // Wire up modulepreload and type="module" injection.
        // The theme enqueues its own entry points via ViteLoader::enqueueAsset().
        ViteLoader::init();

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

        // ── 6. SVG support ─────────────────────────────────────────────────────
        // Allow and sanitize SVG uploads, and provide a helper for inline SVG rendering.
        Svg::register();

        // ── 7. Form submissions ────────────────────────────────────────────────
        // Registers the taw_submission CPT so Form::process() can persist
        // every submission, and adds the Settings → Form Webhook admin page.
        new SubmissionsHandler();
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

    /**
     * One-call bootstrap for a fresh taw-theme scaffold's functions.php.
     *
     * Everything this does was previously hand-written, line by line, in
     * every theme's functions.php — which meant it was a mix of pure
     * framework boilerplate and site-specific customization living in the
     * same file, with no clean line between "safe to auto-update" and
     * "never touch this, it's the client's." This method is exactly that
     * line: functions.php now needs only:
     *
     *   require_once get_template_directory() . '/vendor/autoload.php';
     *   TAW\Core\Theme\Theme::bootstrapFullSite(get_template_directory());
     *
     * ...making functions.php itself 100% framework-owned and safe for
     * `update-theme` to overwrite unconditionally, with zero merge and
     * zero shared git history required. All site-specific configuration
     * moves to three theme-owned (never touched by updates) files, each
     * loaded automatically here if present:
     *
     *   inc/options.php        — OptionsPage field config (pre-existing convention)
     *   inc/performance.php    — returns the array passed to performance()
     *   inc/customizations.php — theme supports, nav menu registration,
     *                            textdomain loading, and any other hooks
     *                            a developer wants to add for this site
     *
     * @param string $themeDir Absolute path to the theme root, e.g. get_template_directory().
     */
    public static function bootstrapFullSite(string $themeDir): void
    {
        $optionsFile = $themeDir . '/inc/options.php';
        if (file_exists($optionsFile)) {
            require_once $optionsFile;
        }

        // customizations.php loads BEFORE boot() deliberately — some framework
        // opt-ins (e.g. VisualEditor::enable()) are plain synchronous flags that
        // boot() reads immediately (VisualEditor::init() no-ops unless enable()
        // already ran). Hook-registration-only customizations (add_action calls
        // that fire on a later event) work fine either order, but the flag-style
        // opt-ins only work if this runs first.
        $customizationsFile = $themeDir . '/inc/customizations.php';
        if (file_exists($customizationsFile)) {
            require_once $customizationsFile;
        }

        self::boot();

        // Lock each page's metabox order to match its template's
        // BlockRegistry::render() sequence — no drag-and-drop drift between
        // the edit screen and what actually renders on the front end.
        MetaboxOrder::lockFromTemplate();

        $performanceConfig = [];
        $performanceFile = $themeDir . '/inc/performance.php';
        if (file_exists($performanceFile)) {
            $performanceConfig = (array) require $performanceFile;
        }
        self::performance($performanceConfig);

        // CSS Studio — inject tawConfig so app.js can check the toggle.
        // Only emitted when the Vite dev server is active.
        add_action('wp_head', static function () {
            $is_dev = function_exists('vite_is_dev')
                ? vite_is_dev()
                : (bool) @fsockopen('localhost', 5173, $errno, $errstr, 0.1);

            if (!$is_dev) {
                return;
            }

            $enabled = (bool) OptionsPage::get('css_studio_enabled');
            echo '<script>window.tawConfig = window.tawConfig || {}; window.tawConfig.cssStudioEnabled = ' . ($enabled ? 'true' : 'false') . ';</script>' . PHP_EOL;
        }, 1);
    }
}
