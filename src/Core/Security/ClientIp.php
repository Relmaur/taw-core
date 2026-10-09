<?php

declare(strict_types=1);

namespace TAW\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The visitor's IP address, as far as this server can actually vouch for it.
 *
 * Forwarding headers (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`)
 * are plain request headers: any client can send them with any value. Read
 * unconditionally, they let a bot pose as a new visitor on every request
 * and walk straight past per-IP rate limits. So `REMOTE_ADDR` — the TCP
 * peer, which can't be forged — is the answer, unless that peer is a proxy
 * this site has declared trusted. Only then are the forwarding headers
 * read, and `X-Forwarded-For` from the right: each trusted hop appends the
 * address it received from, so the right-most entry that isn't itself a
 * trusted proxy is the first one nobody upstream could have injected.
 *
 * Private and loopback peers are trusted out of the box: a request can only
 * arrive *from* such an address through this site's own infrastructure (a
 * load balancer, a container network, a local reverse proxy), never
 * straight from the internet. Public proxies — Cloudflare, a CDN — must be
 * declared (IPs or CIDR ranges) in wp-config.php:
 *
 *   define('TAW_TRUSTED_PROXIES', '173.245.48.0/20, 103.21.244.0/22');
 *
 * or with the `taw_trusted_proxies` filter, which receives the whole list
 * (private ranges included, so a site can also remove them).
 */
final class ClientIp
{
    private const FALLBACK = '0.0.0.0';

    public const PRIVATE_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::1/128',
        'fc00::/7',
    ];

    public static function get(): string
    {
        return self::resolve($_SERVER, self::trustedProxies());
    }

    /**
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        $configured = defined('TAW_TRUSTED_PROXIES') ? (string) constant('TAW_TRUSTED_PROXIES') : '';
        // array_merge() renumbers the filtered entries, so this is a list.
        $proxies = array_merge(self::PRIVATE_RANGES, array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $entry): bool => $entry !== ''
        ));

        $filtered = apply_filters('taw_trusted_proxies', $proxies);

        if (!is_array($filtered)) {
            return $proxies;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => trim((string) $entry), $filtered),
            static fn (string $entry): bool => $entry !== ''
        ));
    }

    /**
     * Pure resolution step, separated from get() so it can be tested
     * without touching $_SERVER or constants.
     *
     * @param array<string, mixed> $server
     * @param list<string>         $trustedProxies
     */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remote = self::valid($server['REMOTE_ADDR'] ?? null) ?? self::FALLBACK;

        if ($trustedProxies === [] || !self::isTrusted($remote, $trustedProxies)) {
            return $remote;
        }

        $cloudflare = self::valid($server['HTTP_CF_CONNECTING_IP'] ?? null);
        if ($cloudflare !== null) {
            return $cloudflare;
        }

        $forwarded = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded !== '') {
            $hops = array_map('trim', explode(',', $forwarded));
            for ($i = count($hops) - 1; $i >= 0; $i--) {
                $hop = self::valid($hops[$i]);
                if ($hop === null) {
                    // A malformed hop means the chain can't be trusted any
                    // further left than this point.
                    return $remote;
                }
                if (!self::isTrusted($hop, $trustedProxies)) {
                    return $hop;
                }
            }
        }

        return self::valid($server['HTTP_X_REAL_IP'] ?? null) ?? $remote;
    }

    /**
     * @param list<string> $ranges
     */
    public static function isTrusted(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $ip falls inside $range — a single address or a CIDR block,
     * IPv4 or IPv6. Mismatched families never match.
     */
    public static function inRange(string $ip, string $range): bool
    {
        $ipBinary = self::binary($ip);
        if ($ipBinary === null) {
            return false;
        }

        if (!str_contains($range, '/')) {
            return $ipBinary === self::binary($range);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $subnetBinary = self::binary(trim($subnet));
        if ($subnetBinary === null || strlen($subnetBinary) !== strlen($ipBinary) || !ctype_digit(trim($bits))) {
            return false;
        }

        $bits = (int) trim($bits);
        if ($bits > strlen($ipBinary) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if (substr($ipBinary, 0, $wholeBytes) !== substr($subnetBinary, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }

    private static function valid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $ip = filter_var($value, FILTER_VALIDATE_IP);

        return is_string($ip) ? $ip : null;
    }

    private static function binary(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        return $packed === false ? null : $packed;
    }
}
