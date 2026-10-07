<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

/**
 * Creates {@see MysqlCanonLawSchema}'s tables and bulk-loads a portable
 * export (see {@see \TAW\CLI\CanonLawExportCommand}) into them for one
 * edition — a line-for-line application of
 * {@see \TAW\Core\Corpus\Catechism\MysqlCatechismInstaller}'s lessons:
 * every query checked (a `false` throws with `$wpdb->last_error`), the
 * edition reloaded with `DELETE … WHERE edition = ?` inside one real
 * transaction (DML, so a `ROLLBACK` genuinely undoes the whole run), values
 * inserted as `esc_sql()` literals so a PHP `null` stays SQL `NULL`, and
 * the counts returned are read back from MySQL, never echoed from input.
 */
final class MysqlCanonLawInstaller
{
    private const BATCH_SIZE = 200;

    /**
     * @param array{divisions: list<array<string, mixed>>, canons: list<array<string, mixed>>, meta?: array<string, string|null>} $data
     * @return array{divisions: int, canons: int} Row counts for this edition, read back after the import.
     */
    public static function install(string $edition, array $data): array
    {
        foreach (MysqlCanonLawSchema::createStatements() as $statement) {
            self::query($statement);
        }

        $meta = [];
        foreach ($data['meta'] ?? [] as $key => $value) {
            $meta[] = ['meta_key' => (string) $key, 'meta_value' => $value === null ? null : (string) $value];
        }

        self::query('START TRANSACTION');

        try {
            self::reload(MysqlCanonLawSchema::divisions(), $edition, [
                'source_id', 'parent_id', 'kind', 'title', 'division_order',
            ], $data['divisions']);

            self::reload(MysqlCanonLawSchema::canons(), $edition, [
                'source_id', 'division_id', 'number', 'text',
            ], $data['canons']);

            self::reload(MysqlCanonLawSchema::meta(), $edition, [
                'meta_key', 'meta_value',
            ], $meta);

            self::query('COMMIT');
        } catch (\Throwable $e) {
            self::query('ROLLBACK');

            throw $e;
        }

        return [
            'divisions' => self::countRows(MysqlCanonLawSchema::divisions(), $edition),
            'canons' => self::countRows(MysqlCanonLawSchema::canons(), $edition),
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
