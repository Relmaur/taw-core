<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Bible;

/**
 * Table names and DDL for the MySQL-backed mirror of the Straubinger
 * corpus shape, used by {@see MysqlBibleReader} (reads) and
 * {@see MysqlBibleInstaller} (schema creation + import) alike, so the two
 * can never drift out of sync with each other.
 *
 * Only the columns {@see BibleReader} actually reads are carried here —
 * this mirrors that reader's own "narrow, purpose-built" schema surface
 * (`books`/`chapters`/`verses`/`sections`/`notes`), not the full source
 * `.sqlite` schema (e.g. `notes.anchor_type`/`anchor_id`, unused by the
 * reader, are dropped on export).
 */
final class MysqlBibleSchema
{
    private const PREFIX = 'taw_corpus_bible_';

    public static function books(): string
    {
        return self::table('books');
    }

    public static function chapters(): string
    {
        return self::table('chapters');
    }

    public static function verses(): string
    {
        return self::table('verses');
    }

    public static function sections(): string
    {
        return self::table('sections');
    }

    public static function notes(): string
    {
        return self::table('notes');
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
            "CREATE TABLE IF NOT EXISTS " . self::books() . " (
                id INT UNSIGNED NOT NULL,
                slug VARCHAR(64) NOT NULL,
                name VARCHAR(191) NOT NULL,
                full_name VARCHAR(191) NULL,
                latin_name VARCHAR(191) NULL,
                abbreviation VARCHAR(32) NOT NULL,
                canon VARCHAR(32) NOT NULL,
                testament VARCHAR(64) NOT NULL,
                division VARCHAR(64) NULL,
                book_order INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY slug (slug),
                KEY book_order (book_order)
            ) {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::chapters() . " (
                id INT UNSIGNED NOT NULL,
                book_id INT UNSIGNED NOT NULL,
                chapter_number INT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY book_chapter (book_id, chapter_number)
            ) {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::verses() . " (
                id INT UNSIGNED NOT NULL,
                book_id INT UNSIGNED NOT NULL,
                chapter_id INT UNSIGNED NOT NULL,
                verse_number INT UNSIGNED NOT NULL,
                verse_label VARCHAR(32) NOT NULL,
                text MEDIUMTEXT NOT NULL,
                is_editorial_addition TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY book_chapter_verse (book_id, chapter_id, verse_number),
                FULLTEXT KEY verse_text_fulltext (text)
            ) ENGINE=InnoDB {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::sections() . " (
                id INT UNSIGNED NOT NULL,
                book_id INT UNSIGNED NOT NULL,
                parent_id INT UNSIGNED NULL,
                kind VARCHAR(32) NOT NULL,
                heading VARCHAR(255) NOT NULL,
                subheading VARCHAR(255) NULL,
                body MEDIUMTEXT NULL,
                start_chapter INT UNSIGNED NOT NULL,
                start_verse INT UNSIGNED NULL,
                end_chapter INT UNSIGNED NOT NULL,
                end_verse INT UNSIGNED NULL,
                position INT NOT NULL,
                PRIMARY KEY (id),
                KEY book_range (book_id, start_chapter, end_chapter),
                KEY position (position)
            ) {$charsetCollate}",

            "CREATE TABLE IF NOT EXISTS " . self::notes() . " (
                id INT UNSIGNED NOT NULL,
                book_id INT UNSIGNED NOT NULL,
                type VARCHAR(32) NOT NULL,
                marker VARCHAR(16) NULL,
                body MEDIUMTEXT NOT NULL,
                start_chapter INT UNSIGNED NULL,
                start_verse INT UNSIGNED NULL,
                end_chapter INT UNSIGNED NULL,
                end_verse INT UNSIGNED NULL,
                position INT NOT NULL,
                PRIMARY KEY (id),
                KEY book_range (book_id, start_chapter, end_chapter),
                FULLTEXT KEY note_body_fulltext (body)
            ) ENGINE=InnoDB {$charsetCollate}",
        ];
    }
}
