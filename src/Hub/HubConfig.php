<?php

declare(strict_types=1);

namespace TAW\Hub;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for whether the Management Hub integration is live
 * on this site, and for the handful of tunables it exposes.
 *
 * Opt-in, like `Lucide` / `MediaFolders` / headless `Cors`: everything Hub
 * stays inert — routes not even registered — until `TAW_HUB_ENABLED` is
 * defined truthy in `wp-config.php` (and `TAW_HUB_KEYS` carries at least one
 * credential; see {@see Security\KeyRing}).
 */
final class HubConfig
{
    public static function enabled(): bool
    {
        return defined('TAW_HUB_ENABLED') && (bool) constant('TAW_HUB_ENABLED');
    }

    /**
     * Max `|now − request timestamp|` tolerated, in seconds. Filterable but
     * clamped to a sane 5..300 so a misconfiguration can't disable replay
     * protection outright.
     */
    public static function maxTimestampDrift(): int
    {
        $drift = 60;

        if (function_exists('apply_filters')) {
            /** @var mixed $filtered */
            $filtered = apply_filters('taw_hub_max_timestamp_drift', $drift);
            if (is_int($filtered)) {
                $drift = $filtered;
            }
        }

        return max(5, min(300, $drift));
    }
}
