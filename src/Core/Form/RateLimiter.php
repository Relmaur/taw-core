<?php

declare(strict_types=1);

namespace TAW\Core\Form;

/**
 * Fixed-window rate limiter backed by WordPress transients — no external
 * cache dependency (Redis/Memcached), works on any host.
 *
 * The window starts on the first hit and doesn't slide: once the max is
 * reached, further hits are rejected until the original window expires,
 * then the count resets. This is a deliberate simplification, not a
 * sliding-window limiter — accurate enough for a contact-form threat
 * model (bursty scripted floods), not meant for high-precision API
 * throttling. Transient reads/writes aren't atomic against concurrent
 * requests, so a determined attacker sending truly simultaneous requests
 * could exceed the count by a small margin — an accepted tradeoff for a
 * dependency-free implementation.
 */
final class RateLimiter
{
    /**
     * @param string $bucket        Logical identifier for what's being limited (e.g. a form id).
     * @param string $identity      Per-client identifier (e.g. an IP address) — hashed before use as a cache key.
     * @param int    $maxAttempts   Attempts allowed within the window.
     * @param int    $windowSeconds Window length in seconds.
     * @return bool True if this attempt should be rejected (limit already reached).
     */
    public static function tooManyAttempts(string $bucket, string $identity, int $maxAttempts, int $windowSeconds): bool
    {
        $key = self::transientKey($bucket, $identity);
        $data = get_transient($key);

        if (!is_array($data) || !isset($data['count'], $data['first'])) {
            set_transient($key, ['count' => 1, 'first' => time()], $windowSeconds);
            return false;
        }

        if ($data['count'] >= $maxAttempts) {
            return true;
        }

        $elapsed = time() - (int) $data['first'];
        $remaining = max($windowSeconds - $elapsed, 1);

        set_transient($key, [
            'count' => (int) $data['count'] + 1,
            'first' => (int) $data['first'],
        ], $remaining);

        return false;
    }

    /**
     * Clear a bucket/identity's counter early — mainly useful for tests.
     */
    public static function reset(string $bucket, string $identity): void
    {
        delete_transient(self::transientKey($bucket, $identity));
    }

    private static function transientKey(string $bucket, string $identity): string
    {
        return 'taw_rl_' . $bucket . '_' . md5($identity);
    }
}
