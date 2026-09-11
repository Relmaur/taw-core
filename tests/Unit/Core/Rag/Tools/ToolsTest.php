<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Tools;

use Brain\Monkey\Functions;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Reference\ReferenceImporter;
use TAW\Core\Rag\Reference\ReferenceSchema;
use TAW\Core\Rag\Reference\SchemaManager;
use TAW\Core\Rag\Storage;
use TAW\Core\Rag\Tools\ArchiveSearchTool;
use TAW\Core\Rag\Tools\BibleLookupTool;
use TAW\Core\Rag\Tools\CatechismLookupTool;
use TAW\Core\Rag\Vector\VectorRepository;
use TAW\Tests\TestCase;

final class ToolsTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-rag-tools-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
        Functions\when('get_option')->justReturn(false);
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

    public function test_bible_lookup_tool_requires_book_and_chapter(): void
    {
        $result = (new BibleLookupTool())->call(['book' => 'Genesis']);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_bible_lookup_tool_reports_no_match_against_an_empty_db(): void
    {
        $result = (new BibleLookupTool())->call(['book' => 'Genesis', 'chapter' => '1']);

        $this->assertSame(['error' => 'No matching verse(s) found.'], $result);
    }

    public function test_bible_lookup_tool_finds_an_imported_verse(): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        $pdo = Storage::openSqlite(Storage::dbPath('bible_straubinger.sqlite'));
        $schema = ReferenceSchema::bible();
        SchemaManager::ensureTable($pdo, $schema);
        $importer = new ReferenceImporter();
        $importer->apply($pdo, $schema, [
            ['book' => 'John', 'chapter' => '3', 'verse' => '16', 'text' => 'For God so loved the world.'],
        ]);

        $result = (new BibleLookupTool())->call(['book' => 'John', 'chapter' => '3', 'verse' => '16']);

        $this->assertArrayHasKey('results', $result);
        $this->assertSame('For God so loved the world.', $result['results'][0]['text']);
    }

    public function test_catechism_lookup_tool_requires_topic_or_number(): void
    {
        $result = (new CatechismLookupTool())->call([]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_catechism_lookup_tool_finds_an_imported_entry(): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        $pdo = Storage::openSqlite(Storage::dbPath('catechism_trent.sqlite'));
        $schema = ReferenceSchema::catechism();
        SchemaManager::ensureTable($pdo, $schema);
        $importer = new ReferenceImporter();
        $importer->apply($pdo, $schema, [
            ['part' => 'First Part', 'question' => '1', 'topic' => 'On Faith', 'text' => 'Entry text here.'],
        ]);

        $result = (new CatechismLookupTool())->call(['topic_or_number' => 'First Part/1']);

        $this->assertArrayHasKey('results', $result);
        $this->assertSame('Entry text here.', $result['results'][0]['text']);
    }

    public function test_archive_search_tool_requires_a_query(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $result = (new ArchiveSearchTool($llm))->call([]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_archive_search_tool_returns_error_when_embedding_fails(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willThrowException(new \RuntimeException('down'));

        $result = (new ArchiveSearchTool($llm))->call(['query' => 'something']);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_archive_search_tool_returns_matches_with_permalinks(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/hello/');

        Storage::ensureProtectedDir(Storage::dir());
        $pdo = Storage::openSqlite(Storage::dbPath('taw_vectors.sqlite'));
        (new VectorRepository($pdo))->upsertChunks(9, ['Hello content chunk.'], [[1.0, 0.0]], 'test-model');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0, 0.0]]);

        $result = (new ArchiveSearchTool($llm))->call(['query' => 'hello']);

        $this->assertArrayHasKey('results', $result);
        $this->assertSame(9, $result['results'][0]['post_id']);
        $this->assertSame('https://example.test/hello/', $result['results'][0]['permalink']);
    }
}
