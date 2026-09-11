<?php

declare(strict_types=1);

namespace TAW\Core\Rag\KnowledgeBase;

/**
 * Extracts row text out of an arbitrary, admin-uploaded SQLite file for
 * embedding — content-agnostic by design: no assumption about the file's
 * schema, no ABSPATH guard (pure PDO, no WordPress dependency).
 *
 * Every table is scanned; columns are kept only if their declared type has
 * SQLite TEXT affinity (contains CHAR/CLOB/TEXT, or has no declared type
 * at all — SQLite's own affinity rule). A table with no such column is
 * skipped entirely — nothing to embed. Column names are kept alongside
 * their values ("column: value" per line) so the embedding — and any
 * citation the model builds from a search result — has real structure
 * instead of a bag of values.
 */
final class GenericSqliteIngestor
{
    /**
     * @return list<string> One string per source row.
     */
    public function extractRows(\PDO $pdo, string $vectorTableName = 'taw_rag_chunks'): array
    {
        $rows = [];

        foreach ($this->tables($pdo, $vectorTableName) as $table) {
            $textColumns = $this->textColumns($pdo, $table);
            if ($textColumns === []) {
                continue;
            }

            $columnList = implode(', ', array_map(
                static fn (string $c): string => self::quoteIdentifier($c),
                $textColumns
            ));

            $stmt = $pdo->query(sprintf('SELECT %s FROM %s', $columnList, self::quoteIdentifier($table)), \PDO::FETCH_ASSOC);

            foreach ($stmt as $row) {
                $parts = [];
                foreach ($row as $column => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $parts[] = "{$column}: {$value}";
                }
                if ($parts !== []) {
                    $rows[] = implode("\n", $parts);
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function tables(\PDO $pdo, string $vectorTableName): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\_%' ESCAPE '\'");

        $tables = [];
        foreach ($stmt as $row) {
            $name = (string) $row['name'];
            if ($name === $vectorTableName || $name === 'vec_chunks') {
                continue;
            }
            $tables[] = $name;
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function textColumns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->query(sprintf('PRAGMA table_info(%s)', self::quoteIdentifier($table)));

        $columns = [];
        foreach ($stmt as $row) {
            $declaredType = strtoupper((string) ($row['type'] ?? ''));
            $isTextAffinity = $declaredType === ''
                || str_contains($declaredType, 'CHAR')
                || str_contains($declaredType, 'CLOB')
                || str_contains($declaredType, 'TEXT');

            if ($isTextAffinity) {
                $columns[] = (string) $row['name'];
            }
        }

        return $columns;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
