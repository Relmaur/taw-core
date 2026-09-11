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

    /**
     * Single choke point for opening one of the RAG chatbot's SQLite
     * files, with exceptions-on-error consistently enabled.
     *
     * Note on sqlite-vec reachability: `PDO::loadExtension()` only exists
     * on PHP 8.4+'s `Pdo\Sqlite` driver-specific subclass (PHP RFC "PDO
     * driver-specific subclasses"), and only on a connection actually
     * constructed as that subclass — a plain `new PDO(...)`, which is what
     * this method does, never exposes it, on any PHP version. This
     * package targets PHP >=8.2 and sqlite-vec is confirmed absent on
     * every target host today, so {@see \TAW\Core\Rag\Vector\VectorCapability}
     * is written to degrade to "unavailable" here rather than requiring
     * every caller to branch on PHP version for a currently-theoretical
     * accelerator.
     */
    public static function openSqlite(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
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
