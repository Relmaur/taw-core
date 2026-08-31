<?php

declare(strict_types=1);

namespace TAW\Hub\Security\Contracts;

use TAW\Hub\Security\HubIdentity;
use TAW\Hub\Security\InboundRequest;
use TAW\Hub\Security\VerificationException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authenticates an inbound request claimed to originate from the management
 * Hub: proves it was signed by an enrolled key, has not been tampered with in
 * transit, is fresh (within the allowed timestamp drift), and is not a replay.
 *
 * Implementations MUST be side-effect free apart from consuming the request
 * nonce via {@see NonceStore::remember()} on success.
 */
interface RequestVerifier
{
    /**
     * @throws VerificationException on any failure — absent/blank auth headers,
     *         unknown key id, unsupported scheme, timestamp drift, malformed or
     *         replayed nonce, or a signature mismatch.
     */
    public function verify(InboundRequest $request): HubIdentity;
}
