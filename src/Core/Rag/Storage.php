<?php

declare(strict_types=1);

namespace TAW\Core\Rag;

use TAW\Core\Storage\ProtectedSqlite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filesystem home for the RAG chatbot's SQLite files (the WP-content
 * vector store plus every admin-uploaded knowledge base) — all living in
 * one protected uploads subdirectory. The protected-dir guard and the
 * exception-mode PDO open are shared plumbing; see
 * {@see \TAW\Core\Storage\ProtectedSqlite}. This class only owns what's
 * RAG-specific: which directory, and that it's a read/write store (unlike
 * {@see \TAW\Core\Corpus\Storage}, which is read-only).
 */
final class Storage
{
    public static function dbPath(string $filename): string
    {
        return trailingslashit(self::dir()) . $filename;
    }

    public static function openSqlite(string $path): \PDO
    {
        return ProtectedSqlite::open($path);
    }

    public static function dir(): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . 'taw-private/rag';
    }

    public static function ensureProtectedDir(string $dir): void
    {
        ProtectedSqlite::ensureProtectedDir($dir);
    }
}
