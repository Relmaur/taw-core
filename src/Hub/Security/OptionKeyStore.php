<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\KeyStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * {@see KeyStore} backed by a single autoload-off WordPress option. Holds only
 * enrolled Ed25519 public keys + capabilities — no secrets — so it's stored
 * as plain data.
 */
final class OptionKeyStore implements KeyStore
{
    private const OPTION = 'taw_hub_enrolled_keys';

    public function all(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $keyId => $spec) {
            if (is_string($keyId) && $keyId !== '' && is_array($spec)) {
                $clean[$keyId] = $spec;
            }
        }

        return $clean;
    }

    public function put(string $keyId, array $spec): void
    {
        $all         = $this->all();
        $all[$keyId] = $spec;
        update_option(self::OPTION, $all, false);
    }

    public function forget(string $keyId): void
    {
        $all = $this->all();
        unset($all[$keyId]);
        update_option(self::OPTION, $all, false);
    }
}
