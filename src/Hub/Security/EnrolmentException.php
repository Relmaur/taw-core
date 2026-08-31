<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by {@see EnrolmentService} when a handshake can't be completed. Like
 * {@see VerificationException}, {@see self::reason()} is a stable slug for the
 * audit log; the `/handshake` route collapses failures to a generic 400/403.
 */
final class EnrolmentException extends \RuntimeException
{
    public const ENROLMENT_DISABLED  = 'enrolment_disabled';
    public const BAD_TOKEN           = 'bad_enrolment_token';
    public const TOKEN_SPENT         = 'enrolment_token_spent';
    public const BAD_HUB_PUBLIC_KEY  = 'bad_hub_public_key';

    public function __construct(private string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
