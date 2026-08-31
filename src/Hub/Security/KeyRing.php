<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\KeyStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The set of Hub credentials this site trusts, keyed by key id.
 *
 * Keys are configured out-of-band and read from the `TAW_HUB_KEYS` constant
 * (defined in `wp-config.php`, so the secrets never sit in the database):
 *
 *   define('TAW_HUB_KEYS', json_encode([
 *       'hub-prod' => [
 *           'secret'       => '…64+ hex chars…',           // HMAC-SHA256
 *           'public_key'   => '…base64 Ed25519 pubkey…',   // optional, either or both
 *           'capabilities' => ['hub:read', 'hub:deploy', 'hub:config', 'hub:maintenance'],
 *       ],
 *   ]));
 *
 * An entry needs at least one of `secret` / `public_key` to be kept. Malformed
 * entries (no usable material, non-array spec, non-string key id) are skipped
 * silently rather than throwing — a single bad entry must not lock the whole
 * integration out.
 */
final class KeyRing
{
    /**
     * @param array<string, HubKey> $keys
     */
    public function __construct(private array $keys)
    {
    }

    /**
     * Build from the `TAW_HUB_KEYS` constant, optionally merged with keys a
     * {@see KeyStore} registered at enrolment time. Constant entries win on an
     * id collision — an operator-set credential outranks a self-registered one.
     * Returns an empty ring (which rejects everything) when neither source has
     * a usable key.
     */
    public static function fromEnvironment(?KeyStore $store = null): self
    {
        $raw = [];

        if ($store !== null) {
            foreach ($store->all() as $keyId => $spec) {
                $raw[$keyId] = $spec;
            }
        }

        $json = defined('TAW_HUB_KEYS') ? constant('TAW_HUB_KEYS') : '';
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $keyId => $spec) {
                    $raw[$keyId] = $spec;
                }
            }
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<array-key, mixed> $raw Key specs keyed by key id.
     */
    public static function fromArray(array $raw): self
    {
        $keys = [];

        foreach ($raw as $keyId => $spec) {
            if (!is_string($keyId) || $keyId === '' || !is_array($spec)) {
                continue;
            }

            $secret = $spec['secret'] ?? null;
            $secret = is_string($secret) && $secret !== '' ? $secret : null;

            $publicKey = $spec['public_key'] ?? null;
            $publicKey = is_string($publicKey) && $publicKey !== '' ? $publicKey : null;

            if ($secret === null && $publicKey === null) {
                continue;
            }

            $capabilities = [];
            if (isset($spec['capabilities']) && is_array($spec['capabilities'])) {
                foreach ($spec['capabilities'] as $cap) {
                    if (is_string($cap) && $cap !== '') {
                        $capabilities[] = $cap;
                    }
                }
            }

            $keys[$keyId] = new HubKey($keyId, $secret, $capabilities, $publicKey);
        }

        return new self($keys);
    }

    public function find(string $keyId): ?HubKey
    {
        return $this->keys[$keyId] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }
}
