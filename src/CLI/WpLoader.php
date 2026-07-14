<?php

declare(strict_types=1);

namespace TAW\CLI;

/**
 * Shared helper for CLI commands that need WordPress fully booted
 * (InspectCommand, FieldsGetCommand, FieldsSetCommand, ExportStaticCommand,
 * SeoExtractCommand, SeoInjectCommand). Locates wp-load.php by walking up
 * from the theme directory — the theme is always at
 * wp-content/themes/<theme>, so this is normally 3 levels up, with a few
 * extra levels of headroom for unusual layouts.
 */
final class WpLoader
{
    public static function locate(string $themeDir): ?string
    {
        $dir = $themeDir;

        for ($i = 0; $i < 6; $i++) {
            $dir = dirname($dir);
            $candidate = $dir . '/wp-load.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Local by Flywheel runs a separate MySQL instance per site, on a
     * per-site Unix socket under its own app-data directory — not the
     * system default `mysqli.default_socket`/`pdo_mysql.default_socket`
     * PHP CLI otherwise uses. `wp-config.php`'s `DB_HOST` is just
     * `'localhost'`, which resolves to the *system* default socket, so a
     * bare `php bin/taw ...` run from an ordinary terminal (not Local's own
     * "Open Site Shell", which sets env vars this process doesn't have)
     * fails to connect — a recurring, easy-to-hit failure mode for any
     * command that boots WordPress under Local.
     *
     * This resolves it automatically: Local's own `sites.json` maps every
     * site's local path to a short site ID, and that ID is also the name of
     * its socket's containing directory
     * (`~/Library/Application Support/Local/run/<id>/mysql/mysqld.sock`
     * on macOS). Given the theme directory, this finds the matching site
     * entry (by path prefix) and points PHP's MySQL drivers at its actual
     * socket via `ini_set()` — before `wp-load.php` (and therefore `$wpdb`)
     * ever connects.
     *
     * A pure no-op everywhere this doesn't apply — real hosting, CI,
     * Docker/other local environments — since every step here bails out
     * silently the moment a expected file/entry isn't found. Call this
     * once, right before requiring the file WpLoader::locate() returned.
     */
    public static function autoConfigureLocalSocket(string $themeDir): void
    {
        $socket = self::resolveLocalSocket($themeDir);
        if ($socket !== null) {
            ini_set('mysqli.default_socket', $socket);
            ini_set('pdo_mysql.default_socket', $socket);
        }
    }

    /**
     * Same detection as autoConfigureLocalSocket(), without the ini_set()
     * side effect — for callers that need the raw socket path rather than
     * an in-process PHP MySQL connection, e.g. WpCliCommand building `-d`
     * flags for a separate `wp` process it shells out to.
     */
    public static function resolveLocalSocket(string $themeDir): ?string
    {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            return null;
        }

        // macOS is Local by Flywheel's primary supported platform (and the
        // only one this has been verified against); Windows uses a
        // differently-rooted app-data path, checked here too on a
        // best-effort basis even though it's unverified.
        $candidates = [
            $home . '/Library/Application Support/Local',
            $home . '/AppData/Roaming/Local',
        ];

        foreach ($candidates as $localDir) {
            $sitesJsonPath = $localDir . '/sites.json';
            if (!is_file($sitesJsonPath)) {
                continue;
            }

            $sites = json_decode((string) file_get_contents($sitesJsonPath), true);
            if (!is_array($sites)) {
                continue;
            }

            return self::findSiteSocket($sites, $localDir, $themeDir);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sites Decoded sites.json — keyed by site ID.
     */
    private static function findSiteSocket(array $sites, string $localDir, string $themeDir): ?string
    {
        $realThemeDir = realpath($themeDir) ?: $themeDir;

        foreach ($sites as $site) {
            if (!is_array($site)) {
                continue;
            }

            $sitePath = $site['path'] ?? null;
            $id = $site['id'] ?? null;
            if (!is_string($sitePath) || $sitePath === '' || !is_string($id) || $id === '') {
                continue;
            }

            $sitePath = self::expandHome($sitePath);
            $realSitePath = rtrim(realpath($sitePath) ?: $sitePath, '/');

            if (!str_starts_with($realThemeDir, $realSitePath . '/')) {
                continue;
            }

            $socket = $localDir . "/run/{$id}/mysql/mysqld.sock";

            // NOT is_file() — a Unix domain socket isn't a "regular file" by
            // is_file()'s definition (it explicitly excludes sockets, FIFOs,
            // device files), so it always returns false here even when the
            // socket exists and is live. file_exists() doesn't discriminate
            // by type and is what's actually needed for this check.
            return file_exists($socket) ? $socket : null;
        }

        return null;
    }

    private static function expandHome(string $path): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '' && str_starts_with($path, '~/')) {
            return $home . substr($path, 1);
        }

        return $path;
    }
}
