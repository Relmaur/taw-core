<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Storage;

use Brain\Monkey\Functions;
use TAW\Core\Storage\ProtectedSqlite;
use TAW\Tests\TestCase;

final class ProtectedSqliteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/taw-protected-sqlite-' . getmypid() . '-' . uniqid();

        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
    }

    protected function tearDown(): void
    {
        // Not every test in this file creates $this->dir (e.g. isAvailable()
        // tests never touch the filesystem at all).
        if (is_dir($this->dir)) {
            $it = @new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it ?: [] as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_ensure_protected_dir_creates_htaccess_and_index_stub(): void
    {
        ProtectedSqlite::ensureProtectedDir($this->dir);

        $this->assertFileExists($this->dir . '/.htaccess');
        $this->assertFileExists($this->dir . '/index.php');
        $this->assertStringContainsString('Deny from all', (string) file_get_contents($this->dir . '/.htaccess'));
    }

    public function test_ensure_protected_dir_does_not_clobber_existing_guard_files(): void
    {
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/.htaccess', 'custom content');

        ProtectedSqlite::ensureProtectedDir($this->dir);

        $this->assertSame('custom content', file_get_contents($this->dir . '/.htaccess'));
    }

    public function test_open_returns_a_pdo_connection_with_exceptions_enabled(): void
    {
        mkdir($this->dir, 0777, true);
        $path = $this->dir . '/t.sqlite';

        $pdo = ProtectedSqlite::open($path);

        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        $pdo->exec('CREATE TABLE t (a TEXT)');
        $pdo->exec("INSERT INTO t VALUES ('ok')");
        $this->assertSame('ok', $pdo->query('SELECT a FROM t')->fetchColumn());
    }

    public function test_open_read_only_rejects_writes(): void
    {
        mkdir($this->dir, 0777, true);
        $path = $this->dir . '/t.sqlite';
        (new \PDO('sqlite:' . $path))->exec('CREATE TABLE t (a TEXT)');

        $pdo = ProtectedSqlite::openReadOnly($path);

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO t VALUES ('nope')");
    }

    public function test_looks_like_sqlite_file_accepts_a_real_database(): void
    {
        mkdir($this->dir, 0777, true);
        $path = $this->dir . '/t.sqlite';
        (new \PDO('sqlite:' . $path))->exec('CREATE TABLE t (a TEXT)');

        $this->assertTrue(ProtectedSqlite::looksLikeSqliteFile($path));
    }

    public function test_looks_like_sqlite_file_rejects_non_sqlite_content(): void
    {
        mkdir($this->dir, 0777, true);
        $path = $this->dir . '/t.txt';
        file_put_contents($path, 'not a database');

        $this->assertFalse(ProtectedSqlite::looksLikeSqliteFile($path));
    }

    public function test_is_available_is_true_when_the_extension_is_loaded_and_a_connection_works(): void
    {
        // On the real test machine, pdo_sqlite genuinely is available —
        // every other test in this suite already depends on that being true.
        $this->assertTrue(ProtectedSqlite::isAvailable());
    }

    public function test_is_available_is_false_when_the_extension_is_not_loaded(): void
    {
        Functions\when('extension_loaded')->justReturn(false);

        $this->assertFalse(ProtectedSqlite::isAvailable());
    }
}
