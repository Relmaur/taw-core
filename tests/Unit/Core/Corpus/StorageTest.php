<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\Storage;
use TAW\Tests\TestCase;

final class StorageTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-corpus-storage-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
    }

    protected function tearDown(): void
    {
        $it = @new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadsDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it ?: [] as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->uploadsDir);
        parent::tearDown();
    }

    public function test_dir_is_a_sibling_of_rag_storage_not_nested_inside_it(): void
    {
        $this->assertSame(
            trailingslashit($this->uploadsDir) . 'taw-private/corpus',
            Storage::dir()
        );
    }

    public function test_db_path_joins_dir_and_filename(): void
    {
        $this->assertSame(
            trailingslashit($this->uploadsDir) . 'taw-private/corpus/bible-straubinger.sqlite',
            Storage::dbPath('bible-straubinger.sqlite')
        );
    }

    public function test_open_read_only_rejects_writes(): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        $path = Storage::dbPath('t.sqlite');
        (new \PDO('sqlite:' . $path))->exec('CREATE TABLE t (a TEXT)');

        $pdo = Storage::openReadOnly($path);

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO t VALUES ('nope')");
    }
}
