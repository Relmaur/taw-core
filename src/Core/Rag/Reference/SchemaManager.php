<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Reference;

/**
 * No ABSPATH guard — pure PDO, runnable before WordPress boots. See
 * {@see ReferenceSchema}'s docblock for why.
 */
final class SchemaManager
{
    public static function ensureTable(\PDO $pdo, ReferenceSchema $schema): void
    {
        $columnDefs = array_map(
            static fn (string $col): string => "{$col} TEXT NOT NULL DEFAULT ''",
            $schema->columns
        );

        $pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (id INTEGER PRIMARY KEY AUTOINCREMENT, %s)',
            $schema->table,
            implode(', ', $columnDefs)
        ));

        $pdo->exec(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s)',
            $schema->table . '_natural_key',
            $schema->table,
            implode(', ', $schema->naturalKey)
        ));
    }
}
