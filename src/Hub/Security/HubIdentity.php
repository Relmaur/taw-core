<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The resolved caller behind a successfully verified Hub request: which
 * enrolled key signed it, and what that key is allowed to do.
 *
 * Immutable. Produced only by a {@see Contracts\RequestVerifier} on success,
 * then attached to the REST request so route handlers can gate on
 * {@see self::can()} without re-running verification.
 *
 * Capabilities are coarse action groups (e.g. `hub:read`, `hub:deploy`,
 * `hub:config`, `hub:maintenance`), not WordPress capabilities. The wildcard
 * `*` grants everything and should be reserved for a break-glass key.
 */
final class HubIdentity
{
    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        private string $keyId,
        private array $capabilities,
    ) {
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function can(string $capability): bool
    {
        return in_array('*', $this->capabilities, true)
            || in_array($capability, $this->capabilities, true);
    }
}
