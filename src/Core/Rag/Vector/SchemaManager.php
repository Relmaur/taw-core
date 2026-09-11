<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Vector;

/**
 * No ABSPATH guard — pure PDO, no WordPress dependency.
 */
final class SchemaManager
{
    public static function ensureVectorSchema(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                embedding BLOB NOT NULL,
                model TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(post_id, chunk_index)
            )'
        );
    }
}
