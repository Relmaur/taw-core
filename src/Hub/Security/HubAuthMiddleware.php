<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\AuditSink;
use TAW\Hub\Security\Contracts\RequestVerifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The gate every Hub REST route passes through: is the integration enabled,
 * did the request authenticate, and does the signing key hold the capability
 * this route requires. Every decision is recorded to the {@see AuditSink}.
 *
 * WordPress-free by design — it works on {@see InboundRequest} and returns an
 * {@see AuthOutcome}. The Phase 3 REST controller wraps this in the thin
 * adapter that turns a `WP_REST_Request` into an `InboundRequest` and an
 * `AuthOutcome` into `true` / `WP_Error`, and stashes the {@see HubIdentity}
 * on the request for the handler.
 */
final class HubAuthMiddleware
{
    /** Request attribute the REST adapter stores the resolved identity under. */
    public const IDENTITY_ATTR = 'taw_hub_identity';

    public function __construct(
        private RequestVerifier $verifier,
        private AuditSink $audit,
        private bool $enabled,
    ) {
    }

    public function authorize(InboundRequest $request, string $capability): AuthOutcome
    {
        if (!$this->enabled) {
            return AuthOutcome::disabled();
        }

        try {
            $identity = $this->verifier->verify($request);
        } catch (VerificationException $e) {
            $this->audit->rejected($request, $e->reason());

            return AuthOutcome::unauthenticated($e->reason());
        }

        if (!$identity->can($capability)) {
            $this->audit->denied($request, $identity, $capability);

            return AuthOutcome::forbidden($identity, $capability);
        }

        $this->audit->accepted($request, $identity);

        return AuthOutcome::ok($identity);
    }
}
