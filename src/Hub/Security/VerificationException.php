<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by any {@see Contracts\RequestVerifier} when an inbound Hub request
 * fails authentication for any reason.
 *
 * The {@see self::reason()} code is a stable, machine-readable slug meant for
 * the audit log and for metrics — NOT for the HTTP response body. The REST
 * middleware collapses every reason into a single generic 401 so a probing
 * client can't learn which specific check it tripped (valid key id? in-window
 * timestamp? and so on).
 */
final class VerificationException extends \RuntimeException
{
    public const MISSING_AUTH_HEADERS = 'missing_auth_headers';
    public const UNKNOWN_KEY_ID       = 'unknown_key_id';
    public const UNSUPPORTED_SCHEME   = 'unsupported_scheme';
    public const TIMESTAMP_DRIFT      = 'timestamp_drift';
    public const MALFORMED_NONCE      = 'malformed_nonce';
    public const REPLAYED_NONCE       = 'replayed_nonce';
    public const BAD_SIGNATURE        = 'bad_signature';

    public function __construct(private string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    /**
     * Stable slug identifying which check failed — one of the class constants.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
