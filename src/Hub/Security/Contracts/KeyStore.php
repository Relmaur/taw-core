<?php

declare(strict_types=1);

namespace TAW\Hub\Security\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistence for Hub credentials that were registered at runtime (via the
 * enrolment handshake) rather than hard-coded in `wp-config.php`.
 *
 * Entries hold the same shape as `TAW_HUB_KEYS` values — but in practice only
 * ever an Ed25519 `public_key` plus `capabilities`, never a shared secret, so
 * the backing store doesn't need to be encrypted.
 */
interface KeyStore
{
    /**
     * @return array<string, array<string, mixed>> Raw specs keyed by key id.
     */
    public function all(): array;

    /**
     * @param array<string, mixed> $spec
     */
    public function put(string $keyId, array $spec): void;

    public function forget(string $keyId): void;
}
