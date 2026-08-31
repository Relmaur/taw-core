<?php

declare(strict_types=1);

namespace TAW\Hub;

use TAW\Hub\Api\HubRoutes;
use TAW\Hub\Cli\HubCliCommand;
use TAW\Hub\Orchestration\AuditSchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Entry point for the Management Hub integration, wired into {@see \TAW\Core\Theme\Theme::boot()}.
 *
 * Unlike `Lucide` / `MediaFolders`, there is no `enable()` code call — the Hub
 * is a security boundary, so it's switched on only by `define('TAW_HUB_ENABLED', true)`
 * in `wp-config.php` (a deliberate server-config decision, not theme code). See
 * {@see HubConfig} and `src/Hub/README.md`.
 *
 * When disabled this is completely inert: no routes registered, no hooks added.
 */
final class HubIntegration
{
    /**
     * Bump when {@see AuditSchema} changes so the table is re-run through dbDelta.
     */
    private const SCHEMA_VERSION = '1';

    private static bool $booted = false;

    public static function init(): void
    {
        if (self::$booted || !HubConfig::enabled()) {
            return;
        }
        self::$booted = true;

        HubRoutes::fromEnvironment()->register();

        // taw-core ships as a Composer library, not a plugin, so there's no
        // activation hook — keep the audit table in sync on admin_init behind
        // a one-option version gate (a single cheap read otherwise).
        add_action('admin_init', [self::class, 'ensureSchema']);

        if (defined('WP_CLI') && constant('WP_CLI')) {
            \WP_CLI::add_command('taw hub', HubCliCommand::class);
        }
    }

    public static function ensureSchema(): void
    {
        if (get_option('taw_hub_schema_version') === self::SCHEMA_VERSION) {
            return;
        }

        AuditSchema::ensureTable();
        update_option('taw_hub_schema_version', self::SCHEMA_VERSION, true);
    }
}
