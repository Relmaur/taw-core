<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\NonceStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * {@see NonceStore} backed by WordPress transients — so replay protection
 * works on any host with no hard Redis/Memcached dependency (a persistent
 * object cache is used automatically when present).
 *
 * The TTL must comfortably exceed the signature acceptance window (2× the
 * verifier's max drift). With the default 60s drift the window is 120s wide,
 * so the default TTL here is 150s. The stored value is trivial; the key is a
 * SHA-256 of the nonce so a raw nonce never lands in the options table.
 *
 * Transient writes aren't atomic against truly simultaneous requests, so two
 * requests reusing one nonce in the same instant could both slip through —
 * the same accepted tradeoff {@see \TAW\Core\Form\RateLimiter} documents. The
 * signature + timestamp checks still stand; only the replay window widens
 * fractionally under that race.
 */
final class TransientNonceStore implements NonceStore
{
    public function __construct(private int $ttlSeconds = 150)
    {
    }

    public function seen(string $nonce): bool
    {
        return get_transient($this->key($nonce)) !== false;
    }

    public function remember(string $nonce): void
    {
        set_transient($this->key($nonce), 1, $this->ttlSeconds);
    }

    private function key(string $nonce): string
    {
        return 'taw_hub_nonce_' . hash('sha256', $nonce);
    }
}
