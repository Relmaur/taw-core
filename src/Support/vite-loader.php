<?php
// inc/vite-loader.php

define('VITE_SERVER', 'http://localhost:5173');
define('VITE_ENTRY_POINT', 'resources/js/app.js'); // Relative to theme root

function vite_is_dev()
{
    static $is_dev = null;
    if ($is_dev !== null) return $is_dev;
    $handle = @fsockopen('localhost', 5173, $errno, $errstr, 0.1);
    $is_dev = $handle !== false;
    if ($handle) fclose($handle);
    return $is_dev;
}

function vite_enqueue_theme_assets()
{
    $is_dev = vite_is_dev();
    $manifest_path = get_theme_file_path('/public/build/manifest.json');

    if ($is_dev) {
        // DEV MODE — unchanged, Vite HMR handles everything
        wp_enqueue_script('vite-client', VITE_SERVER . '/@vite/client', [], null, false);
        wp_enqueue_script('theme-app', VITE_SERVER . '/' . VITE_ENTRY_POINT, ['vite-client'], null, false);
    } elseif (file_exists($manifest_path)) {
        $manifest = json_decode(file_get_contents($manifest_path), true);

        // 1. Critical CSS — inlined in <head>, no network request
        vite_inline_critical_css();

        // 2. Full CSS — loaded asynchronously (non-render-blocking)
        //    We collect all CSS URLs and output them manually because
        //    wp_enqueue_style() always creates render-blocking <link> tags.
        $async_css_urls = [];

        // CSS extracted from JS entry
        if (isset($manifest['resources/js/app.js']['css'])) {
            foreach ($manifest['resources/js/app.js']['css'] as $css_file) {
                $async_css_urls[] = get_theme_file_uri('/public/build/' . $css_file);
            }
        }

        // Standalone SCSS entry
        if (isset($manifest['resources/scss/app.scss']['file'])) {
            $async_css_urls[] = get_theme_file_uri(
                '/public/build/' . $manifest['resources/scss/app.scss']['file']
            );
        }

        // Deduplicate
        $async_css_urls = array_unique($async_css_urls);

        // Output async CSS links — we hook into wp_head so they appear
        // in the right place, after critical CSS
        add_action('wp_head', function () use ($async_css_urls) {
            foreach ($async_css_urls as $url) {
                printf(
                    '<link rel="stylesheet" href="%s" media="print" onload="this.media=\'all\'">' . "\n",
                    esc_url($url)
                );
                // Fallback for users with JavaScript disabled
                printf(
                    '<noscript><link rel="stylesheet" href="%s"></noscript>' . "\n",
                    esc_url($url)
                );
            }
        }, 50);

        // 3. JS — modules are deferred by default, no changes needed
        if (isset($manifest['resources/js/app.js'])) {
            $js_file = $manifest['resources/js/app.js']['file'];
            wp_enqueue_script('theme-app', get_theme_file_uri('/public/build/' . $js_file), [], null, true);
        }
    }
}

/**
 * Preload critical assets from the Vite manifest.
 *
 * Emits <link rel="preload"> for production CSS and JS so the browser
 * begins fetching them before it reaches the actual enqueue tags.
 *
 * Tracks already-emitted URLs to avoid duplicate preload tags — this
 * matters because Vite's manifest can reference the same compiled CSS
 * file from multiple entry points (e.g., SCSS imported in JS AND as
 * a standalone entry).
 *
 * In dev mode this does nothing — Vite's dev server handles everything.
 */
function vite_preload_assets()
{
    if (vite_is_dev()) return;

    $manifest_path = get_theme_file_path('/public/build/manifest.json');
    if (!file_exists($manifest_path)) return;

    $manifest = json_decode(file_get_contents($manifest_path), true);
    $preloaded = [];

    $emit = function (string $file, string $type) use (&$preloaded) {
        $url = get_theme_file_uri('/public/build/' . $file);
        if (isset($preloaded[$url])) return;
        $preloaded[$url] = true;

        if ($type === 'module') {
            printf('<link rel="modulepreload" href="%s">' . "\n", esc_url($url));
        } else {
            printf('<link rel="preload" href="%s" as="%s">' . "\n", esc_url($url), esc_attr($type));
        }
    };

    // JS bundle
    if (isset($manifest['resources/js/app.js']['file'])) {
        $emit($manifest['resources/js/app.js']['file'], 'module');
    }

    // Main CSS files (preload so they download early despite media="print")
    if (isset($manifest['resources/js/app.js']['css'])) {
        foreach ($manifest['resources/js/app.js']['css'] as $css_file) {
            $emit($css_file, 'style');
        }
    }

    if (isset($manifest['resources/scss/app.scss']['file'])) {
        $emit($manifest['resources/scss/app.scss']['file'], 'style');
    }

    // NOTE: critical.scss is NOT preloaded — it's inlined, no request needed
}

add_action('wp_head', 'vite_preload_assets', 2);

// Add type="module" for Vite
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    // Dev: all Vite server scripts
    if (str_starts_with($src, VITE_SERVER)) {
        return '<script type="module" src="' . esc_url($src) . '"></script>';
    }
    // Prod: theme-app and any component scripts
    if ($handle === 'theme-app' || str_starts_with($handle, 'taw-component-')) {
        return '<script type="module" src="' . esc_url($src) . '"></script>';
    }
    return $tag;
}, 10, 3);

/**
 * Resolve a theme asset path, checking the Vite manifest first.
 *
 * In production, assets processed by Vite get hashed filenames for
 * cache-busting (e.g., Inter-Regular-Bx7kZ3.woff2). This function
 * checks the manifest for the hashed version and falls back to the
 * raw file path if the asset wasn't processed by Vite.
 *
 * This lets developers choose either approach:
 *
 *   1. Vite-processed (hashed):
 *      Place fonts in resources/fonts/ and reference in SCSS.
 *      Vite hashes them → manifest maps original → hashed path.
 *
 *   2. Static (unhashed):
 *      Place fonts in resources/static/fonts/.
 *      Not in the manifest → function returns the direct URI.
 *
 * @param string $path Relative path from theme root (e.g., 'resources/fonts/Inter-Regular.woff2')
 * @return string Full URL to the asset (hashed if available, raw otherwise)
 */
function vite_asset_url(string $path): string
{
    // In dev mode, serve directly from Vite
    if (vite_is_dev()) {
        return VITE_SERVER . '/' . ltrim($path, '/');
    }

    // In production, check the manifest for a hashed version
    static $manifest = null;

    if ($manifest === null) {
        $manifest_path = get_theme_file_path('/public/build/manifest.json');
        $manifest = file_exists($manifest_path)
            ? json_decode(file_get_contents($manifest_path), true)
            : [];
    }

    // If Vite processed this file, use the hashed version
    if (isset($manifest[$path]['file'])) {
        return get_theme_file_uri('/public/build/' . $manifest[$path]['file']);
    }

    // Otherwise, serve the file directly from the theme directory
    return get_theme_file_uri('/' . ltrim($path, '/'));
}

/**
 * Inline critical CSS directly into <head>.
 *
 * Reads the compiled critical CSS file and outputs it in a <style> tag.
 * This eliminates the network request — the CSS arrives with the HTML
 * itself, so the browser can paint the above-the-fold content immediately.
 *
 * In dev mode, we skip inlining because Vite's HMR handles everything.
 */
function vite_inline_critical_css(): void
{
    if (vite_is_dev()) {
        return; // Vite HMR handles styles in dev
    }

    $manifest_path = get_theme_file_path('/public/build/manifest.json');
    if (!file_exists($manifest_path)) {
        return;
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);

    // Look up the compiled critical CSS in the manifest
    $critical_key = 'resources/scss/critical.scss';
    if (!isset($manifest[$critical_key]['file'])) {
        return;
    }

    $css_file = get_theme_file_path('/public/build/' . $manifest[$critical_key]['file']);
    if (!file_exists($css_file)) {
        return;
    }

    $css = file_get_contents($css_file);
    if ($css) {
        echo '<style id="critical-css">' . $css . '</style>' . "\n";
    }
}
