<?php

declare(strict_types=1);

namespace TAW\Core\Rag;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filesystem home for the RAG chatbot's SQLite files — all three
 * (bible_straubinger, catechism_trent, taw_vectors) live in the same
 * protected directory, guarded the same way
 * {@see \TAW\Core\Content\Importer}'s rollback-snapshot directory is:
 * `.htaccess` denying direct HTTP access + a silent `index.php` stub,
 * since Apache doesn't always honor `.htaccess` (e.g. AllowOverride off).
 */
final class Storage
{
    public static function dbPath(string $filename): string
    {
        return trailingslashit(self::dir()) . $filename;
    }

    public static function dir(): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . 'taw-private/rag';
    }

    public static function ensureProtectedDir(string $dir): void
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        foreach (
            [
                '.htaccess' => "Require all denied\nDeny from all\n",
                'index.php' => "<?php\n// Silence is golden.\n",
            ] as $guard => $body
        ) {
            $path = $dir . '/' . $guard;
            if (!file_exists($path)) {
                file_put_contents($path, $body);
            }
        }
    }
}
