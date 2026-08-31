<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration;

use TAW\Hub\Orchestration\Contracts\AuditStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * {@see AuditStore} backed by the `{$wpdb->prefix}taw_hub_audit` table
 * ({@see AuditSchema}). Swallows DB errors — a missing table or a failed
 * insert must never break a Hub request; {@see AuditLog} falls back to
 * {@see ArrayAuditStore} when this can't be used.
 */
final class WpdbAuditStore implements AuditStore
{
    public function __construct(private ?string $table = null)
    {
        $this->table ??= self::defaultTable();
    }

    public function append(array $row): void
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'insert')) {
            return;
        }

        try {
            $wpdb->insert($this->table, $row, ['%d', '%s', '%s', '%s', '%s', '%s']);
        } catch (\Throwable) {
            // audit is best-effort
        }
    }

    public function recent(int $limit, int $sinceTs = 0): array
    {
        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
            return [];
        }

        try {
            $sql = $wpdb->prepare(
                "SELECT ts, actor, event, action, outcome, detail FROM `{$this->table}`"
                . ' WHERE ts >= %d ORDER BY id DESC LIMIT %d',
                $sinceTs,
                $limit,
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
        } catch (\Throwable) {
            return [];
        }

        return is_array($rows) ? array_values($rows) : [];
    }

    private static function defaultTable(): string
    {
        global $wpdb;

        $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';

        return $prefix . 'taw_hub_audit';
    }
}
