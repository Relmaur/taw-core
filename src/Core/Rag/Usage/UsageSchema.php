<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Usage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name and DDL for the LLM usage ledger read and written by
 * {@see UsageMeter}. One row per (site-local day, kind, model), so the
 * table stays tiny (a few rows a day) and every write is a single atomic
 * upsert. MySQL, not SQLite, so metering works on every host — including
 * those without pdo_sqlite, where the budget matters just as much.
 */
final class UsageSchema
{
    public const VERSION = '1';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'taw_rag_usage';
    }

    public static function createStatement(): string
    {
        global $wpdb;

        return 'CREATE TABLE IF NOT EXISTS ' . self::table() . ' (
            day DATE NOT NULL,
            kind VARCHAR(16) NOT NULL,
            model VARCHAR(100) NOT NULL,
            requests INT UNSIGNED NOT NULL DEFAULT 0,
            prompt_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
            completion_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cost_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (day, kind, model)
        ) ' . $wpdb->get_charset_collate();
    }
}
