<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

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
 *           'secret'       => '…64+ hex chars…',
 *           'capabilities' => ['hub:read', 'hub:deploy', 'hub:config', 'hub:maintenance'],
 *       ],
 *   ]));
 *
 * Malformed entries (missing/empty secret, non-array spec, non-string key id)
 * are skipped silently rather than throwing — a single bad entry must not lock
 * the whole integration out.
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
     * Build from the `TAW_HUB_KEYS` constant. Returns an empty ring (which
     * rejects everything) when the constant is absent or unparseable.
     */
    public static function fromEnvironment(): self
    {
        $json = defined('TAW_HUB_KEYS') ? constant('TAW_HUB_KEYS') : '';

        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return self::fromArray($decoded);
            }
        }

        return new self([]);
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
            if (!is_string($secret) || $secret === '') {
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

            $keys[$keyId] = new HubKey($keyId, $secret, $capabilities);
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
