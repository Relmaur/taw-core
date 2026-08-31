<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\EnrolmentLedger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * {@see EnrolmentLedger} backed by an autoload-off option. Records the one
 * successful handshake so the enrolment token can't be replayed.
 */
final class OptionEnrolmentLedger implements EnrolmentLedger
{
    private const OPTION = 'taw_hub_enrolment';

    public function tokenConsumed(): bool
    {
        $record = get_option(self::OPTION, []);

        return is_array($record) && ($record['consumed'] ?? false) === true;
    }

    public function markConsumed(string $keyId): void
    {
        update_option(self::OPTION, [
            'consumed' => true,
            'key_id'   => $keyId,
            'at'       => time(),
        ], false);
    }
}
