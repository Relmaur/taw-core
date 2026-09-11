<?php

declare(strict_types=1);

namespace TAW\Core\Storage;

/**
 * Shared plumbing for "a subsystem keeps its own SQLite file(s) in a
 * webserver-inaccessible uploads subdirectory" — the mechanics
 * {@see \TAW\Core\Rag\Storage} and {@see \TAW\Core\Corpus\Storage} both
 * need, extracted here rather than duplicated, because the protection
 * guarantee (never directly HTTP-reachable) and the open-with-exceptions
 * connection setup have to stay identical across every subsystem that
 * relies on them — a copy-pasted drift here is a real vulnerability, not
 * just untidy code. What differs per subsystem (which directory, whether
 * writes ever happen) stays in each subsystem's own thin `Storage` class.
 *
 * Deliberately no `if (!defined('ABSPATH')) exit;` guard — same reasoning
 * as `Content\*`: this is a pure class def with no WordPress dependency of
 * its own (`ensureProtectedDir()`/`open()`/`openReadOnly()` take a path
 * and call plain PHP + PDO), and `TAW\CLI\CorpusInstallCommand` autoloads
 * it — via `Corpus\Storage` — before `require $wpLoad`. A guard here would
 * silently `exit` the whole CLI process the moment this class is touched,
 * pre-boot, with no output at all — exactly the failure mode `taw-core`'s
 * CLAUDE.md already documents for `Content\*` (v1.25.1): "the guard's
 * `exit` silently kills the command."
 *
 * See docs/adr/0001-reference-corpus-storage.md for why this was extracted
 * instead of letting {@see \TAW\Core\Corpus\Storage} duplicate it.
 */
final class ProtectedSqlite
{
    /** First 16 bytes of every valid SQLite database file. */
    public const SQLITE_MAGIC = "SQLite format 3\000";

    /**
     * Guard a directory against direct HTTP access: `.htaccess` denying all
     * requests, plus a silent `index.php` stub since Apache doesn't always
     * honor `.htaccess` (e.g. `AllowOverride` off).
     */
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

    /**
     * Open a SQLite file with exceptions-on-error consistently enabled.
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
    public static function open(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Open a SQLite file the caller will only ever read from. Sets
     * `PRAGMA query_only = 1` so an accidental write anywhere on the
     * connection fails loudly instead of silently mutating a curated,
     * developer-installed dataset — belt-and-braces on top of the caller
     * never issuing a write, not a substitute for it.
     */
    public static function openReadOnly(string $path): \PDO
    {
        $pdo = self::open($path);
        $pdo->exec('PRAGMA query_only = 1');

        return $pdo;
    }

    /**
     * Whether SQLite storage is actually usable on this host: the
     * `pdo_sqlite` extension loaded, AND a real connection actually works.
     * `extension_loaded()` alone isn't sufficient on every build — some
     * managed-hosting PHP builds report the extension present but fail to
     * construct a real connection, so this also opens a throwaway
     * `:memory:` database to confirm the driver is genuinely functional.
     *
     * Deliberately not cached — a `:memory:` connection is cheap (no
     * filesystem I/O), and the result must stay fresh across test runs
     * that stub `extension_loaded()`.
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('pdo_sqlite')) {
            return false;
        }

        try {
            new \PDO('sqlite::memory:');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a file's first bytes match the SQLite 3 file format magic
     * header — a cheap, real check against non-SQLite uploads/installs
     * before ever attempting to open one.
     */
    public static function looksLikeSqliteFile(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, strlen(self::SQLITE_MAGIC));
        fclose($handle);

        return $header === self::SQLITE_MAGIC;
    }
}
