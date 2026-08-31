<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Creates / upgrades the `{$wpdb->prefix}taw_hub_audit` table. Called from
 * the Phase 4 activation hook (and idempotent, so it's safe to call on
 * `admin_init` behind a version check too).
 */
final class AuditSchema
{
    public static function tableName(): string
    {
        global $wpdb;

        $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';

        return $prefix . 'taw_hub_audit';
    }

    public static function ensureTable(): void
    {
        global $wpdb;

        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $collate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';

        dbDelta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ts BIGINT UNSIGNED NOT NULL,
                actor VARCHAR(64) NOT NULL DEFAULT '-',
                event VARCHAR(32) NOT NULL DEFAULT '',
                action VARCHAR(64) NOT NULL DEFAULT '',
                outcome VARCHAR(191) NOT NULL DEFAULT '',
                detail LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                KEY ts (ts),
                KEY actor (actor)
            ) {$collate};",
        );
    }
}
