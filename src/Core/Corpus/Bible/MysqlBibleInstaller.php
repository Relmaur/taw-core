<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Bible;

/**
 * Creates {@see MysqlBibleSchema}'s tables and bulk-loads a portable
 * corpus export (see {@see \TAW\CLI\CorpusExportCommand}) into them via
 * `$wpdb` — the install-time half of the MySQL fallback path, used when
 * the target host has no working `pdo_sqlite` (see
 * `TAW\Core\Storage\ProtectedSqlite::isAvailable()`).
 *
 * Idempotent: re-running against an updated export is always safe (tables
 * are created `IF NOT EXISTS`, then truncated before the fresh import) —
 * same posture as `bin/taw corpus:install`'s file-copy path for SQLite.
 *
 * Values are inserted as manually-escaped SQL literals (`esc_sql()`)
 * rather than through `$wpdb->prepare()`'s `%s` placeholders, because
 * `prepare()` coerces a PHP `null` passed for `%s` into an empty string,
 * not a real SQL `NULL` — wrong for nullable columns like
 * `division`/`full_name`/`parent_id`/`marker`. Every value in this
 * import is a developer-installed dataset from a controlled export
 * pipeline, not raw end-user input, so this is a safe and standard
 * pattern for a bulk-loader (same class of operation `wp db import`
 * itself does).
 */
final class MysqlBibleInstaller
{
    private const BATCH_SIZE = 200;

    /**
     * @param array{books: list<array<string, mixed>>, chapters: list<array<string, mixed>>, verses: list<array<string, mixed>>, sections: list<array<string, mixed>>, notes: list<array<string, mixed>>} $data
     */
    public static function install(array $data): void
    {
        global $wpdb;

        foreach (MysqlBibleSchema::createStatements() as $statement) {
            $wpdb->query($statement);
        }

        $wpdb->query('START TRANSACTION');

        try {
            self::reload(MysqlBibleSchema::books(), [
                'id', 'slug', 'name', 'full_name', 'latin_name', 'abbreviation', 'canon', 'testament', 'division', 'book_order',
            ], $data['books']);

            self::reload(MysqlBibleSchema::chapters(), [
                'id', 'book_id', 'chapter_number',
            ], $data['chapters']);

            self::reload(MysqlBibleSchema::verses(), [
                'id', 'book_id', 'chapter_id', 'verse_number', 'verse_label', 'text', 'is_editorial_addition',
            ], $data['verses']);

            self::reload(MysqlBibleSchema::sections(), [
                'id', 'book_id', 'parent_id', 'kind', 'heading', 'subheading', 'body', 'start_chapter', 'start_verse', 'end_chapter', 'end_verse', 'position',
            ], $data['sections']);

            self::reload(MysqlBibleSchema::notes(), [
                'id', 'book_id', 'type', 'marker', 'body', 'start_chapter', 'start_verse', 'end_chapter', 'end_verse', 'position',
            ], $data['notes']);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');

            throw $e;
        }
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private static function reload(string $table, array $columns, array $rows): void
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$table}");

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $tuples = [];
            foreach ($chunk as $row) {
                $values = array_map(
                    static fn (string $col): string => self::sqlLiteral($row[$col] ?? null),
                    $columns
                );
                $tuples[] = '(' . implode(',', $values) . ')';
            }

            $wpdb->query(
                "INSERT INTO {$table} (" . implode(',', $columns) . ') VALUES ' . implode(',', $tuples)
            );
        }
    }

    private static function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'" . esc_sql((string) $value) . "'";
    }
}
