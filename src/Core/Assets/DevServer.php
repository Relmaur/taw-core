<?php

declare(strict_types=1);

namespace TAW\Core\Assets;

// No ABSPATH guard: pure class (filesystem + a socket probe), shared by
// Assets\Vite and the classic TAW\Support\ViteLoader.

/**
 * Detects a project's own Vite dev server (ADR-0006).
 *
 * The project's vite.config.js writes its dev server origin to a "hot file"
 * (e.g. dist/hot) when the server starts and deletes it on shutdown. Only
 * that host:port is ever probed, and only a real Vite answer counts
 * (GET /@vite/client → 200). No hot file means "not running": there is no
 * fallback to a default port. Two real incidents are behind this:
 *
 *   1. A bare TCP probe matched an unrelated process on the port, so
 *      production pages pointed at dead localhost asset URLs.
 *   2. An HTTP probe of the default port matched ANOTHER project's Vite
 *      server (every Vite server answers /@vite/client the same way).
 *
 * Results are memoized per hot-file set for the rest of the request.
 */
final class DevServer
{
    /** @var array<string, string|null> */
    private static array $hotUrls = [];

    /** @var array<string, bool> */
    private static array $running = [];

    /**
     * The origin written to the first hot file that exists, or null.
     *
     * @param list<string> $hotFiles Absolute paths, checked in order.
     */
    public static function hotFileUrl(array $hotFiles): ?string
    {
        $key = implode('|', $hotFiles);
        if (array_key_exists($key, self::$hotUrls)) {
            return self::$hotUrls[$key];
        }

        $url = null;
        foreach ($hotFiles as $path) {
            if (file_exists($path)) {
                $contents = trim((string) file_get_contents($path));
                if ($contents !== '') {
                    $url = $contents;
                }
                break;
            }
        }

        return self::$hotUrls[$key] = $url;
    }

    /**
     * Whether the dev server named in the hot file answers like Vite.
     *
     * @param list<string> $hotFiles     Absolute paths, checked in order.
     * @param string       $defaultHost  Used when the hot URL has no host.
     * @param int          $defaultPort  Used when the hot URL has no port.
     */
    public static function isRunning(array $hotFiles, string $defaultHost = 'localhost', int $defaultPort = 5173): bool
    {
        $key = implode('|', $hotFiles);
        if (array_key_exists($key, self::$running)) {
            return self::$running[$key];
        }

        $url = self::hotFileUrl($hotFiles);
        if ($url === null) {
            return self::$running[$key] = false;
        }

        $parts = parse_url($url);
        $host  = is_array($parts) && isset($parts['host']) ? $parts['host'] : $defaultHost;
        $port  = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : $defaultPort;

        return self::$running[$key] = self::probe($host, $port);
    }

    /**
     * TCP-connect and confirm a Vite dev server answers GET /@vite/client
     * with 200. Only ever called with a host:port from a hot file.
     */
    public static function probe(string $host, int $port): bool
    {
        $handle = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if (!$handle) {
            return false;
        }

        stream_set_timeout($handle, 0, 200000); // 200ms

        fwrite($handle, "GET /@vite/client HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $statusLine = fgets($handle, 1024);
        fclose($handle);

        if ($statusLine === false) {
            return false;
        }

        return (bool) preg_match('#^HTTP/\d\.\d\s+200\b#', $statusLine);
    }

    /**
     * Forget memoized results (tests only).
     */
    public static function reset(): void
    {
        self::$hotUrls = [];
        self::$running = [];
    }
}
