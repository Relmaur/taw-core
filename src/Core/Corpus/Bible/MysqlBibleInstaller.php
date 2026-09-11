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
 * Every `$wpdb->query()` call here is checked — a `false` result throws
 * immediately with `$wpdb->last_error`, so a real failure (permissions, a
 * malformed export, a transient connection issue) is a loud error, never
 * a silently-incomplete import reported as success. Found the hard way in
 * production: `install()` originally trusted the input export's own row
 * counts for the CLI's "success" message rather than reading anything
 * back from MySQL, so a run that wrote nothing at all (confirmed once —
 * root cause never pinned down, possibly a truncated file transfer) still
 * printed a clean `[OK] Imported 73 books...` with the right-looking
 * numbers while `SHOW TABLES` showed the table didn't exist. `install()`
 * now returns the *actual* row counts read back via `COUNT(*)` after the
 * import, and {@see \TAW\CLI\CorpusInstallCommand} reports those, not the
 * input's.
 *
 * NOT fully atomic across tables, despite the `START TRANSACTION`/
 * `COMMIT`/`ROLLBACK` wrapping below: `TRUNCATE TABLE` is DDL on
 * InnoDB/MariaDB and causes an implicit commit, so a failure partway
 * through `reload()` can leave some tables truncated-and-reloaded and
 * others not — a later `ROLLBACK` cannot undo a `TRUNCATE` that already
 * implicitly committed. The transaction wrapping is kept anyway (it costs
 * nothing, and still protects the `INSERT` batches within one table's
 * reload on a connection with autocommit off) but it is not a guarantee
 * of whole-import atomicity — the real safety net here is the
 * loud-failure behavior above, not the transaction.
 *
 * Re-running against an updated (and, this time, complete) export is
 * always safe regardless: every table is unconditionally truncated and
 * reloaded from scratch.
 *
 * Values are inserted as manually-escaped SQL literals (`esc_sql()`)
 * rather than through `$wpdb->prepare()`'s `%s` placeholders, because
 * `prepare()` coerces a PHP `null` passed for `%s` into an empty string,
 * not a real SQL `NULL` — wrong for nullable columns like
 * `division`/`full_name`/`parent_id`/`marker`. Every value in this import
 * is a developer-installed dataset from a controlled export pipeline, not
 * raw end-user input, so this is a safe and standard pattern for a
 * bulk-loader (same class of operation `wp db import` itself does).
 */
final class MysqlBibleInstaller
{
    private const BATCH_SIZE = 200;

    /**
     * @param array{books: list<array<string, mixed>>, chapters: list<array<string, mixed>>, verses: list<array<string, mixed>>, sections: list<array<string, mixed>>, notes: list<array<string, mixed>>} $data
     * @return array{books: int, chapters: int, verses: int, sections: int, notes: int} Row counts actually read back from MySQL after the import, not the input's own counts.
     */
    public static function install(array $data): array
    {
        global $wpdb;

        foreach (MysqlBibleSchema::createStatements() as $statement) {
            self::query($statement);
        }

        self::query('START TRANSACTION');

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

            self::query('COMMIT');
        } catch (\Throwable $e) {
            self::query('ROLLBACK');

            throw $e;
        }

        return [
            'books' => self::countRows(MysqlBibleSchema::books()),
            'chapters' => self::countRows(MysqlBibleSchema::chapters()),
            'verses' => self::countRows(MysqlBibleSchema::verses()),
            'sections' => self::countRows(MysqlBibleSchema::sections()),
            'notes' => self::countRows(MysqlBibleSchema::notes()),
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private static function reload(string $table, array $columns, array $rows): void
    {
        self::query("TRUNCATE TABLE {$table}");

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $tuples = [];
            foreach ($chunk as $row) {
                $values = array_map(
                    static fn (string $col): string => self::sqlLiteral($row[$col] ?? null),
                    $columns
                );
                $tuples[] = '(' . implode(',', $values) . ')';
            }

            self::query(
                "INSERT INTO {$table} (" . implode(',', $columns) . ') VALUES ' . implode(',', $tuples)
            );
        }
    }

    private static function countRows(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * The one place every DDL/DML statement in this class runs through —
     * `$wpdb->query()` returns `false` on failure (permissions, a
     * malformed statement, a dropped connection), and WordPress does not
     * throw for that on its own. Letting a failed query pass silently is
     * exactly how this class once reported success on an import that
     * wrote nothing at all.
     */
    private static function query(string $sql): void
    {
        global $wpdb;

        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException("MySQL query failed: {$wpdb->last_error}\nQuery: {$sql}");
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
