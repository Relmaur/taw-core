<?php

declare(strict_types=1);

namespace TAW\Hub\Security\Contracts;

use TAW\Hub\Security\HubIdentity;
use TAW\Hub\Security\InboundRequest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records the outcome of every Hub authorization decision. Implementations
 * must be non-throwing — a logging failure must never turn an otherwise-valid
 * request into an error (or vice versa).
 *
 * {@see \TAW\Hub\Security\ErrorLogAuditSink} is the default; the Hub API's
 * persistent audit log (Phase 3) will implement this too.
 */
interface AuditSink
{
    /**
     * Signature verification failed. `$reason` is a
     * {@see \TAW\Hub\Security\VerificationException} slug.
     */
    public function rejected(InboundRequest $request, string $reason): void;

    /**
     * The caller authenticated but lacks the capability the route requires.
     */
    public function denied(InboundRequest $request, HubIdentity $identity, string $capability): void;

    /**
     * The request authenticated and is authorized for the route.
     */
    public function accepted(InboundRequest $request, HubIdentity $identity): void;
}
