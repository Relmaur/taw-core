<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A single enrolled Hub credential: an identifier, the material used to
 * verify request signatures, and the capabilities the key grants.
 *
 * A key carries a shared secret (HMAC-SHA256), an Ed25519 public key, or
 * both — {@see KeyRing} keeps any entry that has at least one. Held only in
 * memory; verification material never leaves this object except into the
 * verifier that needs it.
 */
final class HubKey
{
    /**
     * @param string       $id           Opaque id the Hub sends in `X-TAW-Hub-Key-Id`.
     * @param string|null  $secret       Shared secret for HMAC-SHA256 (>= 32 bytes of entropy recommended).
     * @param list<string> $capabilities Coarse action groups this key may invoke.
     * @param string|null  $publicKey    Base64 Ed25519 public key (32 bytes decoded).
     */
    public function __construct(
        private string $id,
        private ?string $secret,
        private array $capabilities,
        private ?string $publicKey = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function secret(): ?string
    {
        return $this->secret;
    }

    public function publicKey(): ?string
    {
        return $this->publicKey;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }
}
