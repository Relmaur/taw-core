<?php

declare(strict_types=1);

namespace TAW\Core\Corpus;

use TAW\Core\Storage\ProtectedSqlite;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filesystem home for reference-corpus SQLite files (e.g. the Straubinger
 * Bible) — a deliberately separate protected directory from
 * {@see \TAW\Core\Rag\Storage}, not a subdirectory of it. A reference
 * corpus meant for direct structured reading and a RAG knowledge base
 * meant for chatbot embedding are different concerns that happen to share
 * "protected-dir + read-only PDO" plumbing (see
 * {@see \TAW\Core\Storage\ProtectedSqlite}); keeping them in separate
 * directories means `KnowledgeBase\KnowledgeBaseIngestionPipeline` — which
 * writes a `taw_rag_chunks` table directly into whatever `.sqlite` file it
 * ingests — can never mistake a corpus file for a knowledge base upload.
 * See docs/adr/0001-reference-corpus-storage.md.
 *
 * Files here are installed by a developer via `bin/taw corpus:install`
 * (see {@see \TAW\CLI\CorpusInstallCommand}), not uploaded through
 * wp-admin — a curated dataset a developer places, not something an
 * end-user swaps through a web form.
 */
final class Storage
{
    public static function dbPath(string $filename): string
    {
        return trailingslashit(self::dir()) . $filename;
    }

    /**
     * Every corpus file is opened read-only — nothing in this subsystem
     * ever writes to a reference corpus.
     */
    public static function openReadOnly(string $path): \PDO
    {
        return ProtectedSqlite::openReadOnly($path);
    }

    public static function dir(): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . 'taw-private/corpus';
    }

    public static function ensureProtectedDir(string $dir): void
    {
        ProtectedSqlite::ensureProtectedDir($dir);
    }
}
