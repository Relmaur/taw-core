<?php

declare(strict_types=1);

namespace TAW\Core\Lens;

/**
 * The running Lens bridge for a theme (umbrella ADR-0006 § 8), as its run file records it.
 *
 * `taw lens` starts the bridge (resources/lens-bridge/bridge.mjs) in the theme folder; the bridge writes
 * `<theme>/.taw/lens/bridge.json` (its pid, port, per-run token and allowed origins; owner-only, in a folder that
 * git-ignores itself) and removes it when it stops. The site's PHP reads it here to hand the address and token
 * to the developer's page (Lens::bridgeRoute(), under the Lens gate), and `taw lens` reads it so a theme
 * never gets two bridges.
 *
 * No ABSPATH guard: `taw lens` autoloads this before (and without) booting WordPress.
 */
final class Bridge
{
    public const RUN_FILE = '.taw/lens/bridge.json';

    /** The last session's id, kept when the bridge stops, for `taw lens --resume`. */
    public const LAST_SESSION = '.taw/lens/last-session';

    /** The page protocol this taw/core speaks (resources/lens-bridge/lib/bridge.mjs PROTOCOL). */
    public const PROTOCOL = 1;

    /** @var (\Closure(int): bool)|null */
    private static ?\Closure $alive = null;

    /**
     * The bridge running for this theme, or null (none, a stale file, or one that doesn't parse).
     *
     * @return array{pid: int, port: int, token: string, origins: list<string>, protocol: int, started: string}|null
     */
    public static function current(string $themeDir): ?array
    {
        $file = rtrim($themeDir, '/') . '/' . self::RUN_FILE;
        if (!is_file($file)) {
            return null;
        }
        $info = json_decode((string) file_get_contents($file), true);
        if (!is_array($info)) {
            return null;
        }
        $pid = $info['pid'] ?? null;
        $port = $info['port'] ?? null;
        $token = $info['token'] ?? null;
        if (!is_int($pid) || $pid <= 0 || !is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }
        if (!is_string($token) || preg_match('/^[a-f0-9]{32,128}$/', $token) !== 1) {
            return null;
        }
        if (!self::alive($pid)) {
            return null;
        }
        $origins = array_values(array_filter((array) ($info['origins'] ?? []), 'is_string'));

        return [
            'pid'      => $pid,
            'port'     => $port,
            'token'    => $token,
            'origins'  => $origins,
            'protocol' => is_int($info['protocol'] ?? null) ? $info['protocol'] : 0,
            'started'  => is_string($info['started'] ?? null) ? $info['started'] : '',
        ];
    }

    /**
     * What the developer's page gets: where the bridge listens and its token, or that it isn't running.
     *
     * @return array{running: false}|array{running: true, url: string, token: string, protocol: int}
     */
    public static function forPage(string $themeDir): array
    {
        $info = self::current($themeDir);
        if ($info === null) {
            return ['running' => false];
        }

        return [
            'running'  => true,
            'url'      => 'http://127.0.0.1:' . $info['port'],
            'token'    => $info['token'],
            'protocol' => $info['protocol'],
        ];
    }

    /** The session the last bridge for this theme ended with, for `taw lens --resume`. */
    public static function lastSession(string $themeDir): ?string
    {
        $file = rtrim($themeDir, '/') . '/' . self::LAST_SESSION;
        $id = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $id) === 1 ? $id : null;
    }

    /**
     * @internal Tests stand in for the process check.
     * @param (\Closure(int): bool)|null $check
     */
    public static function useAliveCheck(?\Closure $check): void
    {
        self::$alive = $check;
    }

    private static function alive(int $pid): bool
    {
        if (self::$alive !== null) {
            return (self::$alive)($pid);
        }
        if (!function_exists('posix_kill')) {
            return true; // Can't tell: the page tries, and says "not connected" if nothing answers.
        }

        // Signal 0 only checks. EPERM (1) means it exists but belongs to another user.
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }
}
