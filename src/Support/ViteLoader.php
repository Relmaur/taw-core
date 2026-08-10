<?php

declare(strict_types=1);

namespace TAW\Support;

use TAW\Helpers\Framework;


if (!defined('ABSPATH')) {
    exit;
}

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

    /**
     * Tracks every style handle registered from Vite-extracted CSS so the
     * style_loader_tag filter can add the same optimizer-exclusion
     * attributes to exactly those <link> tags — see OPTIMIZER_EXCLUSION_ATTRS.
     *
     * @var string[]
     */
    public static array $styleHandles = [];

    /**
     * Standard exclusion signals WP Rocket, Autoptimize, Perfmatters, and
     * LiteSpeed Cache all document and honor when they hook WordPress's own
     * enqueue/tag filters — tells any optimizer playing by those rules to
     * leave Vite's already-minified, hashed, dependency-ordered output
     * alone. Applied to every module script, its preload/modulepreload
     * tags, and every Vite-extracted stylesheet.
     *
     * Doesn't help against a tool that rewrites the raw HTML output buffer
     * directly instead of hooking WordPress's filters — a real incident:
     * WPMUdev Hummingbird CDN-rehosted a client site's entry bundle
     * cross-origin with no CORS support, hard-failing the whole ES module
     * silently (Alpine.js and all core init never ran, zero visible page
     * errors). That class of tool needs its own exclusion-list
     * configuration in its own admin UI regardless — no marker on the tag
     * itself can prevent it. See taw-theme's AGENTS.md "Vite Integration"
     * section for the full incident writeup.
     */
    private const OPTIMIZER_EXCLUSION_ATTRS = ' data-no-optimize="1" data-cfasync="false" data-no-defer="1" data-no-minify="1"';

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
        add_filter('style_loader_tag', [self::class, 'addStyleExclusionAttrs'], 10, 2);

        // Output [x-cloak] as an inline rule at the very top of <head> so
        // Alpine-cloaked elements (mobile drawers, overlays) are never visible
        // before Alpine initialises — in both dev and prod.
        add_action('wp_head', static function (): void {
            echo '<style>[x-cloak]{display:none!important}</style>' . "\n";
        }, 1);

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
                wp_register_script('vite-client', self::devServerOrigin() . '/@vite/client', [], null, false);
                self::$moduleHandles[] = 'vite-client';
            }
            wp_enqueue_script('vite-client');

            self::$moduleHandles[] = 'theme-app';
            wp_enqueue_script('theme-app', self::devServerOrigin() . '/' . ltrim($entry_point, '/'), ['vite-client'], null, false);

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

        // ── Theme CSS (async — same media="print" swap as non-critical block
        // CSS, see BaseBlock::enqueueCssFile()) ──────────────────────────────
        // Safe to load async now that critical.scss carries real hand-authored
        // layout rules for the header/Hero/Button above-the-fold markup (see
        // critical.scss) — before that, this was deliberately synchronous to
        // avoid a flash of totally unstyled utility-first HTML. Collect CSS
        // from the JS entry bundle and any standalone SCSS companion.
        $css_urls = [];

        foreach ($manifest[$key]['css'] ?? [] as $css_file) {
            $css_urls[] = Framework::themeUrl($dist . '/' . $css_file);
        }

        // Convention: resources/js/app.js pairs with resources/scss/app.scss
        $scss_key = preg_replace('#^resources/js/(.+)\.js$#', 'resources/scss/$1.scss', $key);
        if ($scss_key !== $key && isset($manifest[$scss_key]['file'])) {
            $css_urls[] = Framework::themeUrl($dist . '/' . $manifest[$scss_key]['file']);
        }

        $css_urls = array_unique($css_urls);

        // Always scheduled via a late wp_head hook (priority 50, matching
        // BaseBlock's non-critical CSS) rather than printed inline here:
        // enqueueThemeAssets() runs on wp_enqueue_scripts, always before
        // wp_head fires, so there's no "head already done" branch to handle
        // the way BaseBlock needs one for assets enqueued after the fact.
        add_action('wp_head', static function () use ($css_urls): void {
            foreach ($css_urls as $url) {
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

        // ── JS bundle ──────────────────────────────────────────────────────────
        if (isset($manifest[$key]['file'])) {
            self::$moduleHandles[] = 'theme-app';
            wp_enqueue_script('theme-app', Framework::themeUrl($dist . '/' . $manifest[$key]['file']), [], null, true);
        }
    }

    // ── Dev-server detection ───────────────────────────────────────────────

    /**
     * Returns true when *this project's* Vite dev server is actually
     * reachable and responding — not just "something is listening on the
     * port," and not just "some Vite dev server is listening on the port."
     *
     * A bare TCP port probe (the old implementation: fsockopen($host, 5173))
     * is a false-positive trap: any unrelated process — a Docker container,
     * another dev tool, literally anything — can end up bound to port 5173
     * for reasons that have nothing to do with Vite. When that happens the
     * theme serves dead localhost:5173 asset URLs in production, with no
     * assets loading at all, even though `npm run build` succeeded and the
     * manifest on disk is completely correct. This is a real bug that has
     * happened on a real project (a stray Docker process squatting the port).
     *
     * Verifying at the HTTP level (GET /@vite/client → 200) fixes the
     * non-Vite-process case, but is *not* sufficient on its own: that
     * endpoint is generic — every Vite dev server, from any project on the
     * machine, answers it identically. A second real incident on a real
     * client project proved this: an unrelated, non-TAW Vite project
     * happened to be running its own dev server on port 5173 while this
     * project only ran `npm run build` (no dev server active at all) —
     * the HTTP check still passed, because *a* Vite server answered, just
     * not *this project's*.
     *
     * The fix: only ever probe a host:port sourced from this project's own
     * hot file (vite.config.js writing its actual dev server origin to
     * dist/hot or public/build/hot on startup, deleting it on shutdown —
     * see hotFileUrl()). No hot file is treated as definitive proof this
     * project's dev server isn't running — never fall back to guessing at
     * the hardcoded default port, which is exactly what let an unrelated
     * project's dev server be mistaken for this one's.
     *
     * Result is cached for the lifetime of the request via a static variable.
     */
    public static function isDevServerRunning(): bool
    {
        static $is_dev = null;

        if ($is_dev !== null) {
            return $is_dev;
        }

        $hot_url = self::hotFileUrl();

        if ($hot_url === null) {
            $is_dev = false;

            return $is_dev;
        }

        $parts = parse_url($hot_url);
        $host  = $parts['host'] ?? self::DEV_HOST;
        $port  = (int) ($parts['port'] ?? self::DEV_PORT);

        $is_dev = self::probeViteDevServer($host, $port);

        return $is_dev;
    }

    /**
     * TCP-connect to $host:$port (sourced from this project's own hot file
     * — see isDevServerRunning()) and confirm a real Vite dev server
     * answers by requesting GET /@vite/client and checking for an HTTP 200
     * response. A bare TCP connect alone isn't enough — see
     * isDevServerRunning()'s docblock. This check alone also isn't enough
     * to identify *whose* Vite dev server it is, which is why the caller
     * must only ever pass a host:port already known to belong to this
     * project (from the hot file), never a hardcoded guess.
     */
    private static function probeViteDevServer(string $host, int $port): bool
    {
        $handle = @fsockopen($host, $port, $errno, $errstr, 0.2);

        if (!$handle) {
            return false;
        }

        stream_set_timeout($handle, 0, 200000); // 200ms

        $request = "GET /@vite/client HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n";
        fwrite($handle, $request);

        $status_line = fgets($handle, 1024);
        fclose($handle);

        if ($status_line === false) {
            return false;
        }

        return (bool) preg_match('#^HTTP/\d\.\d\s+200\b#', $status_line);
    }

    /**
     * Read the dev server URL from the theme's hot file, if present.
     *
     * Convention (matching Laravel's Vite plugin): the theme's vite.config.js
     * writes its actual dev server origin (e.g. "http://localhost:5173") to
     * dist/hot or public/build/hot when the server starts, and deletes it on
     * shutdown. isDevServerRunning() treats absence as definitive proof this
     * project's dev server isn't running — a theme whose vite.config.js
     * doesn't implement this convention (very old/hand-rolled, predating the
     * canonical scaffold's hotFilePlugin) will simply never report dev mode
     * via this class; that's the deliberate tradeoff for eliminating the
     * false-positive risk of guessing at a hardcoded default port.
     *
     * Result is cached for the lifetime of the request via a static variable.
     */
    private static function hotFileUrl(): ?string
    {
        static $checked = false;
        static $url     = null;

        if ($checked) {
            return $url;
        }

        $checked = true;

        foreach (['dist/hot', 'public/build/hot'] as $candidate) {
            $path = Framework::themePath($candidate);
            if (file_exists($path)) {
                $contents = trim((string) file_get_contents($path));
                if ($contents !== '') {
                    $url = $contents;
                }
                break;
            }
        }

        return $url;
    }

    /**
     * The dev server origin to use for asset URLs — the hot file's actual
     * URL if available (correct even if Vite picked a non-default port),
     * otherwise the hardcoded default.
     *
     * Public: BaseBlock::enqueueDevAssets() (a different class) needs this
     * for per-block style.{css,scss}/script.js URLs too — every other dev
     * asset path in this class already goes through here; that block-level
     * path was the one spot still trusting the hardcoded DEV_SERVER
     * constant unconditionally, silently pointing at the wrong origin
     * whenever Vite fell back off the default port.
     */
    public static function devServerOrigin(): string
    {
        return self::hotFileUrl() ?? self::DEV_SERVER;
    }

    // ── Manifest ───────────────────────────────────────────────────────────

    /**
     * Parse and cache the Vite production manifest.
     *
     * Checks the WP object cache first (RAM), then falls back to disk. The
     * cache key includes the manifest file's own mtime, so a new deploy
     * (which necessarily rewrites manifest.json with a new mtime) always
     * misses whatever the previous deploy cached — a stale entry can't
     * survive a rebuild, structurally, rather than relying on a deploy hook
     * remembering to flush the object cache. A real incident on a real
     * client site is what this fixes: a site with a persistent object cache
     * (Redis/Memcached) served a previous build's hashed asset filenames —
     * both genuinely 404ing on disk — for as long as the old fixed-key,
     * time-based-TTL cache entry lived, because nothing had invalidated it
     * after the deploy that rotated the hashes.
     *
     * @return array<string, mixed>
     */
    public static function getManifest(): array
    {
        $path = self::manifestPath();

        if ($path === null) {
            return [];
        }

        $cache_key = self::MANIFEST_CACHE_KEY . '_' . filemtime($path);

        $manifest = wp_cache_get($cache_key, self::MANIFEST_CACHE_GROUP);

        if (false !== $manifest) {
            return $manifest;
        }

        $manifest = json_decode(file_get_contents($path), true) ?? [];

        wp_cache_set($cache_key, $manifest, self::MANIFEST_CACHE_GROUP, HOUR_IN_SECONDS * 24);

        return $manifest;
    }

    /**
     * Locate the manifest file on disk, checking every dist-directory
     * convention this class supports.
     *
     * @return string|null  Absolute path to manifest.json, or null if none exists.
     */
    private static function manifestPath(): ?string
    {
        // Vite 5+ writes to dist/.vite/manifest.json; older versions use dist/manifest.json
        $candidates = [
            Framework::themePath('dist/.vite/manifest.json'),
            Framework::themePath('dist/manifest.json'),
            Framework::themePath('public/build/.vite/manifest.json'),
            Framework::themePath('public/build/manifest.json'),
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
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
    public static function distDir(): string
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
            wp_register_script('vite-client', self::devServerOrigin() . '/@vite/client', [], null, true);
            self::$moduleHandles[] = 'vite-client';
        }

        $deps = array_merge(['vite-client'], $dependencies);

        wp_register_script($handle, self::devServerOrigin() . '/' . ltrim($entry_point, '/'), $deps, null, true);

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
                self::$styleHandles[] = $style_handle;

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
                printf('<link rel="modulepreload" href="%s"%s>' . "\n", esc_url($url), self::OPTIMIZER_EXCLUSION_ATTRS);
            } else {
                printf('<link rel="preload" href="%s" as="%s"%s>' . "\n", esc_url($url), esc_attr($type), self::OPTIMIZER_EXCLUSION_ATTRS);
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
     * Add type="module" to every script handle registered as an ES module,
     * plus OPTIMIZER_EXCLUSION_ATTRS so third-party JS optimizers that hook
     * this same filter leave the tag alone.
     *
     * Hooked to script_loader_tag at priority 10 by init().
     */
    public static function addModuleType(string $tag, string $handle, string $src): string
    {
        if (in_array($handle, self::$moduleHandles, true)) {
            return '<script type="module" src="' . esc_url($src) . '"' . self::OPTIMIZER_EXCLUSION_ATTRS . '></script>' . "\n";
        }

        return $tag;
    }

    /**
     * Add OPTIMIZER_EXCLUSION_ATTRS to every <link> tag for a style handle
     * registered from Vite-extracted CSS (see $styleHandles) — Vite's CSS
     * output is already minified and hashed; there's no legitimate reason
     * for a caching/optimization plugin to touch it further.
     *
     * Hooked to style_loader_tag at priority 10 by init().
     */
    public static function addStyleExclusionAttrs(string $tag, string $handle): string
    {
        if (!in_array($handle, self::$styleHandles, true)) {
            return $tag;
        }

        return str_replace('<link ', '<link' . self::OPTIMIZER_EXCLUSION_ATTRS . ' ', $tag);
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
            return self::devServerOrigin() . '/' . ltrim($path, '/');
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
        self::inlineCssFile($css_path, 'critical-css');
    }

    /**
     * Read a compiled CSS file, rewrite Vite's relative asset URLs to absolute
     * theme URLs, then echo a <style> block inline.
     *
     * Vite's `base: './'` produces `url('./assets/...')` in compiled CSS, which
     * resolves correctly from a linked stylesheet but breaks when CSS is inlined
     * into <head> (browser resolves against the page URL, not the CSS file location).
     *
     * @param string $css_path  Absolute filesystem path to the compiled CSS file.
     * @param string $id        Optional HTML id attribute for the <style> tag.
     */
    public static function inlineCssFile(string $css_path, string $id = ''): void
    {
        if (!file_exists($css_path)) {
            return;
        }

        $css = file_get_contents($css_path);
        if (!$css) {
            return;
        }

        $assets_url = Framework::themeUrl(self::distDir() . '/assets');
        $css = str_replace(
            ["url('./", 'url("./','url(./'],
            ["url('" . $assets_url . '/', 'url("' . $assets_url . '/','url(' . $assets_url . '/'],
            $css
        );

        $id_attr = $id ? ' id="' . esc_attr($id) . '"' : '';
        echo '<style' . $id_attr . '>' . $css . '</style>' . "\n";
    }
}
