<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The result of {@see HubAuthMiddleware::authorize()} — a small value object
 * the REST layer maps onto `true` or a `WP_Error` with the right status.
 *
 * The four states collapse to three HTTP responses the caller sees:
 *   - ok               → request proceeds
 *   - disabled         → 404 (the integration isn't enabled here; don't
 *                        confirm the routes even exist)
 *   - unauthenticated  → 401 (generic — the specific reason goes to the
 *                        audit log, never the response)
 *   - forbidden        → 403 (authenticated, but the key lacks the capability)
 */
final class AuthOutcome
{
    private function __construct(
        private string $state,
        private ?HubIdentity $identity,
        private string $detail,
    ) {
    }

    public static function ok(HubIdentity $identity): self
    {
        return new self('ok', $identity, '');
    }

    public static function disabled(): self
    {
        return new self('disabled', null, 'hub_disabled');
    }

    public static function unauthenticated(string $reason): self
    {
        return new self('unauthenticated', null, $reason);
    }

    public static function forbidden(HubIdentity $identity, string $capability): self
    {
        return new self('forbidden', $identity, $capability);
    }

    public function isOk(): bool
    {
        return $this->state === 'ok';
    }

    public function identity(): ?HubIdentity
    {
        return $this->identity;
    }

    /**
     * Audit-log detail: the verification-failure slug, the missing capability,
     * or '' when ok.
     */
    public function detail(): string
    {
        return $this->detail;
    }

    public function httpStatus(): int
    {
        return match ($this->state) {
            'ok'             => 200,
            'disabled'       => 404,
            'unauthenticated' => 401,
            'forbidden'      => 403,
            default          => 500,
        };
    }

    public function errorCode(): string
    {
        return match ($this->state) {
            'ok'             => '',
            'disabled'       => 'taw_hub_disabled',
            'unauthenticated' => 'taw_hub_unauthorized',
            'forbidden'      => 'taw_hub_forbidden',
            default          => 'taw_hub_error',
        };
    }
}
