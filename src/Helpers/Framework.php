<?php

declare(strict_types=1);

namespace TAW\Helpers;

/**
 * Taw Framework — the path resolver for the taw/core package.
 *
 * WHY THIS EXISTS:
 * ----------------
 * When taw/core lived inside the theme (at inc/Core/), every file could
 * use get_template_directory() to find theme files, or dirname(__DIR__)
 * to find sibling files. Both assumptions break when the code moves to
 * vendor/taw/core/.
 *
 * This class provides a single, reliable way to resolve paths:
 *   - Taw::path()  → filesystem path to the core package
 *   - Taw::url()   → URL to core package assets (for wp_enqueue_*)
 *   - Taw::themePath()  → filesystem path to the active theme
 *   - Taw::themeUrl()   → URL to the active theme
 *
 * HOW IT WORKS:
 * -------------
 * This file physically lives at: vendor/taw/core/src/Core/Taw.php
 * The package root is therefore: dirname(__DIR__, 2)
 *
 * For theme paths, we use WordPress's get_template_directory() when
 * available (i.e., during a normal web request). For CLI context,
 * the theme root must be set manually via Taw::setThemeRoot().
 *
 * USAGE:
 * ------
 *   Taw::path('assets/admin.css')
 *   // → /var/www/html/wp-content/themes/my-theme/vendor/taw/core/assets/admin.css
 *
 *   Taw::url('assets/admin.css')
 *   // → https://example.com/wp-content/themes/my-theme/vendor/taw/core/assets/admin.css
 *
 *   Taw::themePath('Blocks')
 *   // → /var/www/html/wp-content/themes/my-theme/Blocks
 */
class Framework
{
    /**
     * Theme root override — used in CLI context where
     * WordPress functions aren't available.
     */
    private static ?string $themeRoot = null;

    /**
     * Get the absolute filesystem path to the taw/core package root.
     *
     * This is the ONLY place in the entire core that uses __DIR__
     * to determine its own location. Everything else calls this method.
     *
     * @param string $relativePath  Optional path relative to package root
     * @return string               Absolute filesystem path
     */
    public static function path(string $relativePath = ''): string
    {
        // This file: src/Core/Framework.php
        // Package root: ../../ (two levels up)
        $base = dirname(__DIR__, 2);

        return $relativePath
            ? $base . '/' . ltrim($relativePath, '/')
            : $base;
    }

    /**
     * Get the URL to a file inside the taw/core package.
     *
     * This converts a package filesystem path into a URL that WordPress
     * can serve, by finding which web-served directory the package lives
     * under and swapping that directory's path for its URL.
     *
     * WHERE THE PACKAGE CAN LIVE (ADR-0003):
     * taw/core is installed by `composer require` into the TAW theme that
     * consumes it — the active parent theme's vendor/ (taw-theme,
     * taw-gutenberg), or a child theme's. taw/core is for TAW sites only
     * (ADR-0003 addendum); the plugin/mu-plugin roots below are kept because
     * they cost nothing, not because other consumers are supported.
     * Resolution order:
     *   1. The active parent theme — the original and most common case,
     *      checked first and resolved exactly as before, so existing sites
     *      get byte-identical URLs.
     *   2. Otherwise the longest matching known WordPress location: child
     *      theme (stylesheet dir), mu-plugins, plugins, wp-content, site root.
     *   3. If nothing matches (e.g. a symlinked plugin whose real path is
     *      outside the web root), the pre-ADR-0003 result is returned
     *      unchanged — use the `taw_core_package_url` filter to fix it for
     *      that setup.
     * Every result passes through `taw_core_package_url`.
     *
     * Only works in WordPress context (web requests), not CLI.
     *
     * @param string $relativePath  Path relative to package root
     * @return string               Full URL to the asset
     */
    public static function url(string $relativePath = ''): string
    {
        $url = self::resolveUrl($relativePath);

        return function_exists('apply_filters')
            ? (string) apply_filters('taw_core_package_url', $url, $relativePath)
            : $url;
    }

    /**
     * The unfiltered URL for a package file — see url() for the resolution order.
     */
    private static function resolveUrl(string $relativePath): string
    {
        $absPath   = self::path($relativePath);

        // realpath() matters here: self::path() is built from __DIR__, which
        // PHP resolves to this file's *real* location — following any
        // symlink in the chain. get_template_directory() does no such
        // resolution; it's a plain string built from WordPress's own
        // constants. Whenever the theme directory is reached through a
        // symlink (a supported, documented setup for this package — see
        // taw-theme's AGENTS.md on machine-specific real/symlink direction),
        // those two paths diverge textually even though they point at the
        // same files, and the str_replace() below would silently match
        // nothing — leaving the raw filesystem path concatenated onto a
        // URL. Resolving both sides through realpath() keeps them
        // comparable regardless of which side (if either) is a symlink.
        $themePath = realpath(get_template_directory()) ?: get_template_directory();

        // Strip the theme path prefix to get a theme-relative path
        // e.g., /var/www/.../themes/my-theme/vendor/taw/core/assets/admin.css
        //     → vendor/taw/core/assets/admin.css
        $legacyUrl = get_template_directory_uri() . '/' . str_replace($themePath . '/', '', $absPath);

        // 1. Inside the active parent theme: return exactly what this method
        //    always returned. Checked before anything else so the common case
        //    never touches another WordPress function.
        if (self::isInside($absPath, $themePath)) {
            return $legacyUrl;
        }

        // 2. Longest matching known location wins. Longest, because these
        //    roots nest (a plugin dir is inside wp-content, which is inside
        //    the site root) — the most specific one has the right base URL.
        $bestRoot = '';
        $bestUrl  = '';
        foreach (self::locationRoots() as [$rootPath, $rootUrl]) {
            foreach (array_unique([rtrim($rootPath, '/'), rtrim(realpath($rootPath) ?: $rootPath, '/')]) as $candidate) {
                if (strlen($candidate) > strlen($bestRoot) && self::isInside($absPath, $candidate)) {
                    $bestRoot = $candidate;
                    $bestUrl  = $rootUrl;
                }
            }
        }

        if ($bestRoot !== '') {
            return rtrim($bestUrl, '/') . '/' . ltrim(substr($absPath, strlen($bestRoot)), '/');
        }

        // 3. Unknown location — keep the old behavior rather than guess.
        return $legacyUrl;
    }

    /**
     * Web-served WordPress locations the package can be vendored under, as
     * [filesystem path, base URL] pairs. Each is read only if WordPress has
     * defined it, so this also works in partially-booted contexts.
     *
     * The parent theme isn't listed: url() checks it first, separately.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function locationRoots(): array
    {
        $roots = [];

        if (function_exists('get_stylesheet_directory') && function_exists('get_stylesheet_directory_uri')) {
            $roots[] = [get_stylesheet_directory(), get_stylesheet_directory_uri()];
        }
        // URLs come from WordPress's own functions, not the *_URL constants:
        // the functions apply the site's scheme (http/https) and URL filters.
        if (defined('WPMU_PLUGIN_DIR') && function_exists('plugins_url')) {
            // plugins_url() maps any file inside mu-plugins to the mu-plugins URL.
            $roots[] = [(string) WPMU_PLUGIN_DIR, plugins_url('', WPMU_PLUGIN_DIR . '/index.php')];
        }
        if (defined('WP_PLUGIN_DIR') && function_exists('plugins_url')) {
            $roots[] = [(string) WP_PLUGIN_DIR, plugins_url()];
        }
        if (defined('WP_CONTENT_DIR') && function_exists('content_url')) {
            $roots[] = [(string) WP_CONTENT_DIR, content_url()];
        }
        if (function_exists('site_url')) {
            $roots[] = [(string) ABSPATH, site_url()];
        }

        return $roots;
    }

    /**
     * Whether $path is $dir itself or somewhere underneath it.
     *
     * Compares with a trailing slash so /themes/foo-child never counts as
     * being inside /themes/foo.
     */
    private static function isInside(string $path, string $dir): bool
    {
        $dir = rtrim($dir, '/');

        return $dir !== '' && ($path === $dir || str_starts_with($path, $dir . '/'));
    }

    /**
     * Get the absolute filesystem path to the active theme root.
     *
     * In WordPress context: uses get_template_directory()
     * In CLI context: uses the value set via setThemeRoot()
     *
     * @param string $relativePath  Optional path relative to theme root
     * @return string               Absolute filesystem path
     */
    public static function themePath(string $relativePath = ''): string
    {
        // WordPress context — the standard way
        if (self::$themeRoot === null && function_exists('get_template_directory')) {
            $base = get_template_directory();
        } elseif (self::$themeRoot !== null) {
            // CLI context — manually set
            $base = self::$themeRoot;
        } else {
            throw new \RuntimeException(
                'Theme root not available. In CLI context, call Framework::setThemeRoot() first.'
            );
        }

        return $relativePath
            ? $base . '/' . ltrim($relativePath, '/')
            : $base;
    }

    /**
     * Get the URL to the active theme root.
     *
     * Only works in WordPress context (web requests).
     *
     * @param string $relativePath  Optional path relative to theme root
     * @return string               Full URL
     */
    public static function themeUrl(string $relativePath = ''): string
    {
        $base = get_template_directory_uri();

        return $relativePath
            ? $base . '/' . ltrim($relativePath, '/')
            : $base;
    }

    /**
     * Set the theme root directory manually.
     *
     * This is used by the CLI entry point (bin/taw) where WordPress
     * isn't loaded and get_template_directory() doesn't exist.
     *
     * @param string $path  Absolute path to the theme root
     */
    public static function setThemeRoot(string $path): void
    {
        self::$themeRoot = rtrim($path, '/');
    }

    /**
     * Get the core framework version.
     *
     * Reads from the package's composer.json. Useful for
     * cache-busting, admin footers, and compatibility checks.
     *
     * @return string  Version string (e.g., '1.0.0')
     */
    public static function version(): string
    {
        static $version = null;

        if ($version === null) {
            // Composer resolves this from git tags automatically —
            // no "version" field needed in composer.json
            $version = \Composer\InstalledVersions::getPrettyVersion('taw/core') ?? '0.0.0';
        }

        return $version;
    }
}
