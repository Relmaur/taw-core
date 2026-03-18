<?php

declare(strict_types=1);

namespace TAW\Support;

use TAW\Core\Framework;

/**
 * ViteLoader — OOP asset pipeline for Vite-powered WordPress themes.
 *
 * Handles dev-server detection, manifest parsing with object-cache,
 * script/style enqueuing, modulepreload, and critical CSS inlining.
 *
 * The main theme bundle is enqueued automatically — Theme::boot() is all you need:
 *
 *   Theme::boot(); // boots ViteLoader::init('resources/js/app.js') internally
 *
 * Custom entry point (call before boot):
 *
 *   ViteLoader::init('src/main.ts');
 *   Theme::boot();
 *
 * Additional entry points (e.g. a block script):
 *
 *   add_action('wp_enqueue_scripts', fn() =>
 *       ViteLoader::enqueueAsset('my-block', 'resources/js/my-block.js')
 *   );
 *
 * Resolve any asset URL (fonts, images, etc.):
 *
 *   ViteLoader::assetUrl('resources/fonts/Inter-Regular.woff2');
 */
class ViteLoader
{
    // ── Object-cache constants ──────────────────────────────────────────────
    private const MANIFEST_CACHE_KEY   = 'taw_vite_manifest';
    private const MANIFEST_CACHE_GROUP = 'taw_core';

    // ── Vite dev server defaults ────────────────────────────────────────────
    public const DEV_SERVER = 'http://localhost:5173';
    private const DEV_HOST  = 'localhost';
    private const DEV_PORT  = 5173;

    /**
     * Tracks every script handle registered as an ES module so the
     * script_loader_tag filter can add type="module" to exactly those tags.
     *
     * @var string[]
     */
    public static array $moduleHandles = [];

    // ── Boot ───────────────────────────────────────────────────────────────

    /**
     * Wire up the WordPress hooks needed by the asset pipeline and enqueue
     * the theme's main entry point — mirroring the old vite_enqueue_theme_assets().
     *
     * Called automatically from Theme::boot() with the default entry point.
     * Override before boot() if your theme uses a different entry:
     *
     *   ViteLoader::init('src/main.ts'); // must come before Theme::boot()
     *   Theme::boot();
     *
     * Subsequent calls are no-ops, so Theme::boot()'s internal call is safe.
     *
     * @param string $entry_point Theme-relative Vite entry, e.g. 'resources/js/app.js'.
     */
    public static function init(string $entry_point = 'resources/js/app.js'): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        add_action('wp_head', [self::class, 'preloadAssets'], 2);
        add_filter('script_loader_tag', [self::class, 'addModuleType'], 10, 3);

        add_action('wp_enqueue_scripts', function () use ($entry_point): void {
            self::enqueueThemeAssets($entry_point);
        });
    }

    /**
     * Full theme asset enqueue — mirrors the old vite_enqueue_theme_assets().
     *
     * Dev:  serves everything live from the Vite HMR server.
     * Prod: inlines critical CSS, loads full CSS asynchronously (non-render-blocking
     *       via media="print" + onload swap), and enqueues the JS bundle.
     *
     * Also picks up a standalone SCSS companion entry automatically:
     *   resources/js/app.js  →  resources/scss/app.scss (if present in manifest)
     */
    private static function enqueueThemeAssets(string $entry_point): void
    {
        if (self::isDevServerRunning()) {
            if (!wp_script_is('vite-client', 'registered')) {
                wp_register_script('vite-client', self::DEV_SERVER . '/@vite/client', [], null, false);
                self::$moduleHandles[] = 'vite-client';
            }
            wp_enqueue_script('vite-client');

            self::$moduleHandles[] = 'theme-app';
            wp_enqueue_script('theme-app', self::DEV_SERVER . '/' . ltrim($entry_point, '/'), ['vite-client'], null, false);

            return;
        }

        $manifest = self::getManifest();
        if (empty($manifest)) {
            return;
        }

        $dist = self::distDir();
        $key  = ltrim($entry_point, '/');

        // ── Critical CSS ────────────────────────────────────────────────────────
        // Called inline here (not via a separate wp_head hook) so it is
        // guaranteed to run in the same context as the rest of the enqueue,
        // mirroring how vite_inline_critical_css() worked in the old loader.
        self::inlineCriticalCss();

        // ── Async CSS (non-render-blocking) ────────────────────────────────────
        // Collect CSS from the JS entry bundle and any standalone SCSS companion.
        $async_css_urls = [];

        foreach ($manifest[$key]['css'] ?? [] as $css_file) {
            $async_css_urls[] = Framework::themeUrl($dist . '/' . $css_file);
        }

        // Convention: resources/js/app.js pairs with resources/scss/app.scss
        $scss_key = preg_replace('#^resources/js/(.+)\.js$#', 'resources/scss/$1.scss', $key);
        if ($scss_key !== $key && isset($manifest[$scss_key]['file'])) {
            $async_css_urls[] = Framework::themeUrl($dist . '/' . $manifest[$scss_key]['file']);
        }

        $async_css_urls = array_unique($async_css_urls);

        if (!empty($async_css_urls)) {
            add_action('wp_head', function () use ($async_css_urls): void {
                foreach ($async_css_urls as $url) {
                    // media="print" loads without blocking render; onload swaps to "all"
                    printf(
                        '<link rel="stylesheet" href="%s" media="print" onload="this.media=\'all\'">' . "\n",
                        esc_url($url)
                    );
                    printf(
                        '<noscript><link rel="stylesheet" href="%s"></noscript>' . "\n",
                        esc_url($url)
                    );
                }
            }, 50);
        }

        // ── JS bundle ──────────────────────────────────────────────────────────
        if (isset($manifest[$key]['file'])) {
            self::$moduleHandles[] = 'theme-app';
            wp_enqueue_script('theme-app', Framework::themeUrl($dist . '/' . $manifest[$key]['file']), [], null, true);
        }
    }

    // ── Dev-server detection ───────────────────────────────────────────────

    /**
     * Returns true when the Vite dev server is reachable on localhost.
     *
     * Result is cached for the lifetime of the request via a static variable.
     */
    public static function isDevServerRunning(): bool
    {
        static $is_dev = null;

        if ($is_dev !== null) {
            return $is_dev;
        }

        $is_dev  = false;
        $handle  = @fsockopen(self::DEV_HOST, self::DEV_PORT, $errno, $errstr, 0.1);

        if ($handle) {
            fclose($handle);
            $is_dev = true;
        }

        return $is_dev;
    }

    // ── Manifest ───────────────────────────────────────────────────────────

    /**
     * Parse and cache the Vite production manifest.
     *
     * Checks the WP object cache first (RAM), then falls back to disk.
     * The result is stored for 24 hours — deploy hooks should flush
     * the object cache on each deploy to pick up new asset hashes.
     *
     * @return array<string, mixed>
     */
    public static function getManifest(): array
    {
        $manifest = wp_cache_get(self::MANIFEST_CACHE_KEY, self::MANIFEST_CACHE_GROUP);

        if (false !== $manifest) {
            return $manifest;
        }

        // Vite 5+ writes to dist/.vite/manifest.json; older versions use dist/manifest.json
        $candidates = [
            Framework::themePath('dist/.vite/manifest.json'),
            Framework::themePath('dist/manifest.json'),
            Framework::themePath('public/build/.vite/manifest.json'),
            Framework::themePath('public/build/manifest.json'),
        ];

        $manifest = [];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                $manifest = json_decode(file_get_contents($path), true) ?? [];
                break;
            }
        }

        wp_cache_set(self::MANIFEST_CACHE_KEY, $manifest, self::MANIFEST_CACHE_GROUP, HOUR_IN_SECONDS * 24);

        return $manifest;
    }

    /**
     * Build a full URL to a file inside the Vite dist directory.
     *
     * Use this when you already have a resolved filename from the manifest
     * (e.g. $manifest[$key]['file']) and need a browser-ready URL.
     *
     * @param string $file  Manifest-resolved filename, e.g. 'assets/app-Bx7kZ3.js'
     * @return string       Full URL to the file inside the dist directory.
     */
    public static function distUrl(string $file): string
    {
        return Framework::themeUrl(self::distDir() . '/' . $file);
    }

    /**
     * Detect which dist directory Vite wrote to.
     *
     * @return string  Theme-relative path, e.g. 'dist' or 'public/build'
     */
    private static function distDir(): string
    {
        static $dir = null;

        if ($dir !== null) {
            return $dir;
        }

        $candidates = ['dist/.vite/manifest.json', 'dist/manifest.json', 'public/build/.vite/manifest.json', 'public/build/manifest.json'];

        foreach ($candidates as $candidate) {
            if (file_exists(Framework::themePath($candidate))) {
                $dir = str_starts_with($candidate, 'public/build') ? 'public/build' : 'dist';
                return $dir;
            }
        }

        $dir = 'dist';

        return $dir;
    }

    // ── Enqueue ────────────────────────────────────────────────────────────

    /**
     * Register (and optionally enqueue) a Vite entry point.
     *
     * In dev mode:   scripts are served live from the Vite dev server with HMR.
     * In prod mode:  the manifest is consulted for hashed filenames.
     *                CSS bundled with the entry is registered as a stylesheet.
     *
     * @param string   $handle       WordPress script handle.
     * @param string   $entry_point  Entry point relative to theme root, e.g. 'resources/js/app.js'.
     * @param string[] $dependencies Additional WP script handle dependencies.
     * @param bool     $enqueue      true = enqueue immediately; false = register only.
     */
    public static function enqueueAsset(string $handle, string $entry_point, array $dependencies = [], bool $enqueue = true): void
    {
        self::$moduleHandles[] = $handle;

        if (self::isDevServerRunning()) {
            self::enqueueDevAsset($handle, $entry_point, $dependencies, $enqueue);
        } else {
            self::enqueueProdAsset($handle, $entry_point, $dependencies, $enqueue);
        }
    }

    private static function enqueueDevAsset(string $handle, string $entry_point, array $dependencies, bool $enqueue): void
    {
        if (!wp_script_is('vite-client', 'registered')) {
            wp_register_script('vite-client', self::DEV_SERVER . '/@vite/client', [], null, true);
            self::$moduleHandles[] = 'vite-client';
        }

        $deps = array_merge(['vite-client'], $dependencies);

        wp_register_script($handle, self::DEV_SERVER . '/' . ltrim($entry_point, '/'), $deps, null, true);

        if ($enqueue) {
            wp_enqueue_script($handle);
        }
    }

    private static function enqueueProdAsset(string $handle, string $entry_point, array $dependencies, bool $enqueue): void
    {
        $manifest = self::getManifest();

        if (empty($manifest)) {
            return;
        }

        $key  = ltrim($entry_point, '/');
        $dist = self::distDir();

        if (!isset($manifest[$key])) {
            return;
        }

        $entry = $manifest[$key];

        // JS bundle
        wp_register_script(
            $handle,
            Framework::themeUrl($dist . '/' . $entry['file']),
            $dependencies,
            null,
            true
        );

        // CSS extracted by Vite from this entry
        if (!empty($entry['css'])) {
            foreach ($entry['css'] as $index => $css_file) {
                $style_handle = $index === 0 ? $handle . '-style' : $handle . '-style-' . $index;
                wp_register_style($style_handle, Framework::themeUrl($dist . '/' . $css_file));

                if ($enqueue) {
                    wp_enqueue_style($style_handle);
                }
            }
        }

        if ($enqueue) {
            wp_enqueue_script($handle);
        }
    }

    // ── Preload ────────────────────────────────────────────────────────────

    /**
     * Emit <link rel="modulepreload"> / <link rel="preload"> tags for
     * all registered module handles so the browser fetches them early.
     *
     * Hooked to wp_head at priority 2 by init().
     * Does nothing in dev mode — Vite's dev server handles everything.
     */
    public static function preloadAssets(): void
    {
        if (self::isDevServerRunning()) {
            return;
        }

        $manifest  = self::getManifest();
        $dist      = self::distDir();
        $preloaded = [];

        $emit = function (string $file, string $type) use ($dist, &$preloaded): void {
            $url = Framework::themeUrl($dist . '/' . $file);
            if (isset($preloaded[$url])) {
                return;
            }
            $preloaded[$url] = true;

            if ($type === 'module') {
                printf('<link rel="modulepreload" href="%s">' . "\n", esc_url($url));
            } else {
                printf('<link rel="preload" href="%s" as="%s">' . "\n", esc_url($url), esc_attr($type));
            }
        };

        foreach (self::$moduleHandles as $handle) {
            $key = self::handleToManifestKey($handle, $manifest);
            if ($key === null) {
                continue;
            }

            $entry = $manifest[$key];

            if (isset($entry['file'])) {
                $emit($entry['file'], 'module');
            }

            foreach ($entry['css'] ?? [] as $css_file) {
                $emit($css_file, 'style');
            }
        }
    }

    /**
     * Locate the manifest entry that corresponds to a WP script handle.
     *
     * WordPress handles don't map 1-to-1 to manifest keys, so we do a
     * best-effort search: pick the first entry whose 'file' basename
     * resembles the handle.
     *
     * @return string|null  Manifest key, or null if not found.
     */
    private static function handleToManifestKey(string $handle, array $manifest): ?string
    {
        foreach ($manifest as $key => $entry) {
            if (isset($entry['isEntry']) && $entry['isEntry']) {
                // Match by key basename, e.g. 'resources/js/app.js' ↔ 'app'
                $basename = pathinfo($key, PATHINFO_FILENAME);
                if ($basename === $handle || str_contains($handle, $basename)) {
                    return $key;
                }
            }
        }

        return null;
    }

    // ── script_loader_tag filter ───────────────────────────────────────────

    /**
     * Add type="module" to every script handle registered as an ES module.
     *
     * Hooked to script_loader_tag at priority 10 by init().
     */
    public static function addModuleType(string $tag, string $handle, string $src): string
    {
        if (in_array($handle, self::$moduleHandles, true)) {
            return '<script type="module" src="' . esc_url($src) . '"></script>' . "\n";
        }

        return $tag;
    }

    // ── Utility helpers ────────────────────────────────────────────────────

    /**
     * Resolve any theme asset to its URL, using the manifest for hashed paths.
     *
     * In dev mode: served live from the Vite dev server.
     * In prod mode: looks up the hashed filename in the manifest; falls back
     *               to the raw theme file URL if the asset isn't in the manifest.
     *
     * @param string $path  Theme-relative path, e.g. 'resources/fonts/Inter.woff2'
     * @return string       Full URL ready to use in HTML attributes.
     */
    public static function assetUrl(string $path): string
    {
        if (self::isDevServerRunning()) {
            return self::DEV_SERVER . '/' . ltrim($path, '/');
        }

        $manifest = self::getManifest();
        $key      = ltrim($path, '/');

        if (isset($manifest[$key]['file'])) {
            return Framework::themeUrl(self::distDir() . '/' . $manifest[$key]['file']);
        }

        return Framework::themeUrl($path);
    }

    /**
     * Inline a named SCSS/CSS entry directly into <head> as a <style> block.
     *
     * Useful for critical-path CSS — eliminates the network round-trip so
     * above-the-fold content paints immediately.
     *
     * Does nothing in dev mode (Vite HMR handles styles there).
     *
     * @param string $entry_key  Manifest key of the CSS entry, e.g. 'resources/scss/critical.scss'
     */
    public static function inlineCriticalCss(string $entry_key = 'resources/scss/critical.scss'): void
    {
        $manifest = self::getManifest();

        if (!isset($manifest[$entry_key]['file'])) {
            return;
        }

        $css_path = Framework::themePath(self::distDir() . '/' . $manifest[$entry_key]['file']);

        if (!file_exists($css_path)) {
            return;
        }

        $css = file_get_contents($css_path);
        if ($css) {
            // Vite's base: './' writes relative url(./...) references in compiled CSS.
            // These resolve correctly from a linked stylesheet but break when the CSS
            // is inlined into <head> — the browser resolves them against the page URL
            // instead of the CSS file's location in public/build/assets/.
            // Replace with absolute Theme URLs before inlining.
            $assets_url = Framework::themeUrl(self::distDir() . '/assets');
            $css = str_replace(
                ["url('./", 'url("./','url(./'],
                ["url('" . $assets_url . '/', 'url("' . $assets_url . '/','url(' . $assets_url . '/'],
                $css
            );

            echo '<style id="critical-css">' . $css . '</style>' . "\n";
        }
    }
}
