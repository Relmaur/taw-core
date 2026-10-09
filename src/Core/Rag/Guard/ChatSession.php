<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Guard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A short-lived, signed "this visitor passed the human check" pass, so
 * Turnstile runs once per conversation instead of once per message.
 *
 * The token is `base64url(payload) . "." . base64url(HMAC-SHA256)`, keyed
 * with `wp_salt('auth')`: stateless to verify, impossible to mint without
 * the site's salts. The only server state is a per-session message counter
 * (a transient), which caps how much one pass can buy.
 *
 * Why not WordPress nonces: chat pages are served from the page cache
 * (Hummingbird on WPMU DEV), so a nonce baked into the HTML goes stale
 * for every visitor after 12–24 hours. This token is fetched by the browser
 * at the start of each conversation, so caching can never serve a stale one.
 *
 * Why the token isn't bound to the visitor's IP: mobile visitors change
 * address mid-conversation, and per-IP rate limits already apply to every
 * message independently of the session.
 *
 * Known race: two simultaneous messages on one session can both read the
 * same count and both pass, overshooting the cap by the number of
 * in-flight requests. That is bounded by the per-IP and site-wide rate
 * limits and the budget reserve, so it isn't worth a lock.
 */
final class ChatSession
{
    public const HEADER = 'X-TAW-Chat-Session';
    public const TTL_SECONDS = 1800;
    public const MAX_MESSAGES = 30;

    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const EXHAUSTED = 'exhausted';

    private const COUNTER_PREFIX = 'taw_chat_sess_';

    /**
     * @return array{token: string, expires_in: int, max_messages: int}
     */
    public static function issue(): array
    {
        $payload = self::base64UrlEncode((string) wp_json_encode([
            'sid' => bin2hex(random_bytes(16)),
            'exp' => time() + self::TTL_SECONDS,
        ]));

        return [
            'token' => $payload . '.' . self::sign($payload),
            'expires_in' => self::TTL_SECONDS,
            'max_messages' => self::MAX_MESSAGES,
        ];
    }

    /**
     * Check the token and spend one message from it.
     *
     * @return self::OK|self::INVALID|self::EXPIRED|self::EXHAUSTED
     */
    public static function consume(string $token): string
    {
        $claims = self::claims($token);
        if ($claims === null) {
            return self::INVALID;
        }

        $secondsLeft = $claims['exp'] - time();
        if ($secondsLeft <= 0) {
            return self::EXPIRED;
        }

        $key = self::COUNTER_PREFIX . $claims['sid'];
        $used = (int) get_transient($key);
        if ($used >= self::MAX_MESSAGES) {
            return self::EXHAUSTED;
        }

        set_transient($key, $used + 1, $secondsLeft);

        return self::OK;
    }

    /**
     * The verified claims, or null for anything malformed or forged.
     *
     * @return array{sid: string, exp: int}|null
     */
    private static function claims(string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        // Constant-time comparison: a byte-by-byte early exit would leak
        // how much of a forged signature was right.
        if (!hash_equals(self::sign($parts[0]), $parts[1])) {
            return null;
        }

        $claims = json_decode(self::base64UrlDecode($parts[0]), true);
        if (
            !is_array($claims)
            || !is_string($claims['sid'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $claims['sid']) !== 1
            || !is_int($claims['exp'] ?? null)
        ) {
            return null;
        }

        return ['sid' => $claims['sid'], 'exp' => $claims['exp']];
    }

    private static function sign(string $payload): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $payload, wp_salt('auth'), true));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
