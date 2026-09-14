<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Catechism;

/**
 * Table names and DDL for the MySQL-backed mirror of the catechism corpus
 * shape, used by {@see MysqlCatechismReader} (reads) and
 * {@see MysqlCatechismInstaller} (schema creation + import) alike — same
 * split-responsibility pattern as
 * {@see \TAW\Core\Corpus\Bible\MysqlBibleSchema}.
 *
 * Tables are shared across every catechism edition rather than one
 * table-set per edition — each row carries an `edition` column plus the
 * source `.sqlite` export's own integer `id` as `source_id` (two
 * independently-exported editions can both legitimately use `id = 1`, so
 * the source id alone is never unique on its own). `UNIQUE (edition,
 * source_id)` stands in for the source file's primary key; every
 * parent-reference column (`part_id`, `section_id`, `chapter_id`) stores
 * the *source* id of its parent and is only ever queried together with the
 * same `edition` filter — see {@see MysqlCatechismReader} for that
 * pattern in practice. Adding a second edition later needs no schema
 * change, just new rows carrying a new `edition` value.
 */
final class MysqlCatechismSchema
{
    private const PREFIX = 'taw_corpus_catechism_';

    public static function parts(): string
    {
        return self::table('parts');
    }

    public static function sections(): string
    {
        return self::table('sections');
    }

    public static function chapters(): string
    {
        return self::table('chapters');
    }

    public static function paragraphs(): string
    {
        return self::table('paragraphs');
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
            "CREATE TABLE IF NOT EXISTS " . self::parts() . " (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                edition VARCHAR(32) NOT NULL,
                source_id INT UNSIGNED NOT NULL,
                name VARCHAR(191) NOT NULL,
                part_order INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY edition_source (edition, source_id)
            ) {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::sections() . " (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                edition VARCHAR(32) NOT NULL,
                source_id INT UNSIGNED NOT NULL,
                part_id INT UNSIGNED NOT NULL,
                title VARCHAR(191) NOT NULL,
                section_order INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY edition_source (edition, source_id),
                KEY edition_part (edition, part_id)
            ) {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::chapters() . " (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                edition VARCHAR(32) NOT NULL,
                source_id INT UNSIGNED NOT NULL,
                section_id INT UNSIGNED NOT NULL,
                title VARCHAR(191) NOT NULL,
                chapter_order INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY edition_source (edition, source_id),
                KEY edition_section (edition, section_id)
            ) {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::paragraphs() . " (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                edition VARCHAR(32) NOT NULL,
                source_id INT UNSIGNED NOT NULL,
                chapter_id INT UNSIGNED NOT NULL,
                section_id INT UNSIGNED NOT NULL,
                part_id INT UNSIGNED NOT NULL,
                paragraph_number INT UNSIGNED NOT NULL,
                question_text MEDIUMTEXT NULL,
                answer_text MEDIUMTEXT NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY edition_source (edition, source_id),
                KEY edition_chapter (edition, chapter_id, paragraph_number),
                FULLTEXT KEY qa_fulltext (question_text, answer_text)
            ) ENGINE=InnoDB {$charsetCollate}",
        ];
    }
}
