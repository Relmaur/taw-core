<?php

declare(strict_types=1);

namespace TAW\Hub\Security\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks whether the one-time enrolment token has already been spent.
 *
 * The enrolment token (`TAW_HUB_ENROLMENT_TOKEN`) authorizes exactly one
 * successful handshake — a site pairs with one Hub. Re-pairing means setting a
 * fresh token and clearing the ledger (a wp-cli command, Phase 4).
 */
interface EnrolmentLedger
{
    public function tokenConsumed(): bool;

    /**
     * Record a successful enrolment as $keyId and burn the token.
     */
    public function markConsumed(string $keyId): void;
}
