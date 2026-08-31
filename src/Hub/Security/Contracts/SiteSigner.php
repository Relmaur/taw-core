<?php

declare(strict_types=1);

namespace TAW\Hub\Security\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * This site's own Ed25519 identity — created once, persisted, and handed to
 * the Hub during enrolment so the Hub can verify responses / callbacks that
 * originate here.
 */
interface SiteSigner
{
    /** Base64 of the 32-byte public key. */
    public function publicKeyBase64(): string;

    /** Detached Ed25519 signature over $message (raw 64 bytes). */
    public function sign(string $message): string;
}
