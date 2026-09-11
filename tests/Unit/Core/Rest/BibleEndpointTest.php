<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rest;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\Bible\BibleReader;
use TAW\Core\Corpus\Bible\BibleReaderInterface;
use TAW\Core\Corpus\Bible\MysqlBibleReader;
use TAW\Core\Corpus\Storage;
use TAW\Core\Rest\BibleEndpoint;
use TAW\Tests\TestCase;

final class BibleEndpointTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-bible-endpoint-' . getmypid() . '-' . uniqid();
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

    private function reader(): BibleReaderInterface
    {
        return $this->callMethod(new BibleEndpoint(), 'reader');
    }

    private function installSqliteFile(): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        touch(Storage::dbPath(BibleReader::FILENAME));
    }

    public function test_reader_prefers_sqlite_when_a_sqlite_corpus_is_installed(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        $this->installSqliteFile();

        $this->assertInstanceOf(BibleReader::class, $this->reader());
    }

    public function test_reader_falls_back_to_mysql_when_no_sqlite_corpus_is_installed(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $this->assertInstanceOf(MysqlBibleReader::class, $this->reader());
    }

    public function test_reader_respects_a_filtered_replacement(): void
    {
        $custom = new class implements BibleReaderInterface {
            public function books(): array
            {
                return [];
            }

            public function chapter(string $bookSlug, int $chapterNumber): ?array
            {
                return null;
            }

            public function searchVerses(string $query, int $limit = 20): array
            {
                return [];
            }

            public function searchNotes(string $query, int $limit = 20): array
            {
                return [];
            }
        };

        Functions\when('apply_filters')->justReturn($custom);

        $this->assertSame($custom, $this->reader());
    }

    public function test_reader_falls_back_to_default_when_the_filter_returns_something_invalid(): void
    {
        Functions\when('apply_filters')->justReturn('not a reader');

        $this->assertInstanceOf(MysqlBibleReader::class, $this->reader());
    }
}
