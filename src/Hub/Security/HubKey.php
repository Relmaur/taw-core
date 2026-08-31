<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A single enrolled Hub credential: an identifier, the shared secret used to
 * verify HMAC-SHA256 request signatures, and the capabilities that key grants.
 *
 * Held only in memory, built by {@see KeyRing} from configuration. The secret
 * never leaves this object except into {@see \hash_hmac()} inside the verifier.
 */
final class HubKey
{
    /**
     * @param string       $id           Opaque key identifier the Hub sends in `X-TAW-Hub-Key-Id`.
     * @param string       $secret       Shared secret (>= 32 bytes of entropy recommended).
     * @param list<string> $capabilities Coarse action groups this key is allowed to invoke.
     */
    public function __construct(
        private string $id,
        private string $secret,
        private array $capabilities,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }
}
