<?php

declare(strict_types=1);

namespace TAW\Hub\Security\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Short-lived record of request nonces already spent, for replay protection.
 *
 * Entries only need to outlive the signature's acceptance window — that is,
 * at least twice the maximum timestamp drift (a request timestamped `t` stays
 * verifiable from `t − drift` to `t + drift`). {@see \TAW\Hub\Security\TransientNonceStore}
 * is the production implementation.
 */
interface NonceStore
{
    /**
     * Has this nonce already been spent (and not yet expired)?
     */
    public function seen(string $nonce): bool;

    /**
     * Mark the nonce spent. Idempotent. Called only after a request has
     * otherwise fully verified, so bogus traffic can't exhaust the store.
     */
    public function remember(string $nonce): void;
}
