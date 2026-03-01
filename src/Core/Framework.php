<?php

declare(strict_types=1);

namespace TAW\Core;

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
     * can serve. It works by calculating the relative path from the
     * theme root to the file, then prepending the theme URI.
     *
     * Only works in WordPress context (web requests), not CLI.
     *
     * @param string $relativePath  Path relative to package root
     * @return string               Full URL to the asset
     */
    public static function url(string $relativePath = ''): string
    {
        $absPath   = self::path($relativePath);
        $themePath = get_template_directory();

        // Strip the theme path prefix to get a theme-relative path
        // e.g., /var/www/.../themes/my-theme/vendor/taw/core/assets/admin.css
        //     → vendor/taw/core/assets/admin.css
        $relative = str_replace($themePath . '/', '', $absPath);

        return get_template_directory_uri() . '/' . $relative;
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
            $composerJson = self::path('composer.json');
            if (file_exists($composerJson)) {
                $data = json_decode(file_get_contents($composerJson), true);
                $version = $data['version'] ?? '0.0.0';
            } else {
                $version = '0.0.0';
            }
        }

        return $version;
    }
}
