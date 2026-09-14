<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Catechism;

/**
 * Creates {@see MysqlCatechismSchema}'s tables and bulk-loads a portable
 * corpus export (see {@see \TAW\CLI\CatechismExportCommand}) into them via
 * `$wpdb` for one edition at a time — the install-time half of the MySQL
 * fallback path, mirroring {@see \TAW\Core\Corpus\Bible\MysqlBibleInstaller}
 * (read that class's docblock — every lesson it documents, learned from a
 * real production incident, is applied here from the start):
 *
 *  - Every `$wpdb->query()` call is checked; a `false` result throws
 *    immediately with `$wpdb->last_error` — never a silently-incomplete
 *    import reported as success.
 *  - `install()` returns the *actual* row counts read back via `COUNT(*)`
 *    after the import, for {@see \TAW\CLI\CatechismInstallCommand} to
 *    report — never the input export's own counts.
 *
 * One genuine improvement over `MysqlBibleInstaller`, possible here
 * specifically because these tables are **shared across editions** (see
 * {@see MysqlCatechismSchema}'s docblock): reloading one edition uses
 * `DELETE FROM ... WHERE edition = ?`, not `TRUNCATE TABLE` — a targeted
 * `DELETE` is DML, not DDL, so it never causes InnoDB's implicit commit
 * `TRUNCATE` does. The `START TRANSACTION`/`COMMIT`/`ROLLBACK` wrapping
 * here is therefore a *real* atomicity guarantee across all four tables
 * for the edition being installed, unlike the Bible installer's wrapping
 * (which the `TRUNCATE` there undermines) — a `ROLLBACK` here genuinely
 * undoes every `DELETE`/`INSERT` this method issued.
 *
 * Values are inserted as manually-escaped SQL literals (`esc_sql()`)
 * rather than `$wpdb->prepare()`'s `%s` placeholders, for the identical
 * NULL-handling reason `MysqlBibleInstaller` documents (`prepare()`
 * coerces a PHP `null` into an empty string for `%s`, wrong for
 * `question_text`).
 */
final class MysqlCatechismInstaller
{
    private const BATCH_SIZE = 200;

    /**
     * @param array{parts: list<array<string, mixed>>, sections: list<array<string, mixed>>, chapters: list<array<string, mixed>>, paragraphs: list<array<string, mixed>>} $data
     * @return array{parts: int, sections: int, chapters: int, paragraphs: int} Row counts for this edition, actually read back from MySQL after the import.
     */
    public static function install(string $edition, array $data): array
    {
        foreach (MysqlCatechismSchema::createStatements() as $statement) {
            self::query($statement);
        }

        self::query('START TRANSACTION');

        try {
            self::reload(MysqlCatechismSchema::parts(), $edition, [
                'source_id', 'name', 'part_order',
            ], $data['parts']);

            self::reload(MysqlCatechismSchema::sections(), $edition, [
                'source_id', 'part_id', 'title', 'section_order',
            ], $data['sections']);

            self::reload(MysqlCatechismSchema::chapters(), $edition, [
                'source_id', 'section_id', 'title', 'chapter_order',
            ], $data['chapters']);

            self::reload(MysqlCatechismSchema::paragraphs(), $edition, [
                'source_id', 'chapter_id', 'section_id', 'part_id', 'paragraph_number', 'question_text', 'answer_text',
            ], $data['paragraphs']);

            self::query('COMMIT');
        } catch (\Throwable $e) {
            self::query('ROLLBACK');

            throw $e;
        }

        return [
            'parts' => self::countRows(MysqlCatechismSchema::parts(), $edition),
            'sections' => self::countRows(MysqlCatechismSchema::sections(), $edition),
            'chapters' => self::countRows(MysqlCatechismSchema::chapters(), $edition),
            'paragraphs' => self::countRows(MysqlCatechismSchema::paragraphs(), $edition),
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private static function reload(string $table, string $edition, array $columns, array $rows): void
    {
        $editionLiteral = "'" . esc_sql($edition) . "'";

        self::query("DELETE FROM {$table} WHERE edition = {$editionLiteral}");

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $tuples = [];
            foreach ($chunk as $row) {
                $values = [$editionLiteral];
                foreach ($columns as $col) {
                    $values[] = self::sqlLiteral($row[$col] ?? null);
                }
                $tuples[] = '(' . implode(',', $values) . ')';
            }

            self::query(
                "INSERT INTO {$table} (edition," . implode(',', $columns) . ') VALUES ' . implode(',', $tuples)
            );
        }
    }

    private static function countRows(string $table, string $edition): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE edition = %s", $edition));
    }

    /**
     * The one place every DDL/DML statement in this class runs through —
     * see {@see \TAW\Core\Corpus\Bible\MysqlBibleInstaller::query()}'s
     * docblock for why this exists.
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
