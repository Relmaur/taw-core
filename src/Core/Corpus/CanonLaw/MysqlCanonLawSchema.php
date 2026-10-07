<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

/**
 * Table names and DDL for the MySQL mirror of the canon-law corpus, used
 * by {@see MysqlCanonLawReader} and {@see MysqlCanonLawInstaller} alike.
 *
 * One table-set shared across every edition, exactly like
 * {@see \TAW\Core\Corpus\Catechism\MysqlCatechismSchema}: each row carries
 * `edition` plus the source export's own integer id as `source_id`
 * (`UNIQUE (edition, source_id)` stands in for the source primary key),
 * and every parent reference (`parent_id`, `division_id`) stores the
 * *source* id, always queried together with the same `edition` filter.
 *
 * Canon `text` is stored raw, exactly as exported — the source marks are
 * normalized at read time by {@see CanonText}, the same as the SQLite
 * path, so both backends can never drift apart on what a reader sees.
 * `meta` mirrors the export's own provenance key/value table.
 */
final class MysqlCanonLawSchema
{
    private const PREFIX = 'taw_corpus_canon_law_';

    public static function divisions(): string
    {
        return self::table('divisions');
    }

    public static function canons(): string
    {
        return self::table('canons');
    }

    public static function meta(): string
    {
        return self::table('meta');
    }

    private static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . self::PREFIX . $name;
    }

    /**
     * @return list<string> CREATE TABLE statements, one per table.
     */
    public static function createStatements(): array
    {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        return [
            "CREATE TABLE IF NOT EXISTS " . self::divisions() . " (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                edition VARCHAR(32) NOT NULL,
                source_id INT UNSIGNED NOT NULL,
                parent_id INT UNSIGNED NULL,
                kind VARCHAR(32) NOT NULL,
                title VARCHAR(255) NOT NULL,
                division_order INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY edition_source (edition, source_id),
                KEY edition_parent (edition, parent_id)
            ) {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::canons() . " (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                edition VARCHAR(32) NOT NULL,
                source_id INT UNSIGNED NOT NULL,
                division_id INT UNSIGNED NOT NULL,
                number INT UNSIGNED NOT NULL,
                text MEDIUMTEXT NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY edition_source (edition, source_id),
                KEY edition_number (edition, number),
                KEY edition_division (edition, division_id, number),
                FULLTEXT KEY text_fulltext (text)
            ) ENGINE=InnoDB {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::meta() . " (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                edition VARCHAR(32) NOT NULL,
                meta_key VARCHAR(64) NOT NULL,
                meta_value TEXT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY edition_key (edition, meta_key)
            ) {$charsetCollate}",
        ];
    }
}
