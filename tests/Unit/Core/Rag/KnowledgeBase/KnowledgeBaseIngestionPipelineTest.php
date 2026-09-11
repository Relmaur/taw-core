<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\KnowledgeBase;

use Brain\Monkey\Functions;
use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseIngestionPipeline;
use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseRegistry;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Storage;
use TAW\Tests\TestCase;

final class KnowledgeBaseIngestionPipelineTest extends TestCase
{
    private string $uploadsDir;

    /** @var array<string, mixed> */
    private array $optionStore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-rag-kb-ingest-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);
        $this->optionStore = [];

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->optionStore[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value) {
            $this->optionStore[$key] = $value;
            return true;
        });
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

    private function seedSourceFile(string $filename): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        $pdo = Storage::openSqlite(Storage::dbPath($filename));
        $pdo->exec('CREATE TABLE entries (topic TEXT, text TEXT)');
        $pdo->exec("INSERT INTO entries (topic, text) VALUES ('Greeting', 'Hello world.')");
    }

    public function test_ingest_unknown_id_returns_null(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);

        $result = (new KnowledgeBaseIngestionPipeline($llm))->ingest('does-not-exist');

        $this->assertNull($result);
    }

    public function test_ingest_missing_source_file_fails(): void
    {
        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-test', 'Test KB', 'desc', 'kb-test-missing.sqlite');

        $llm = $this->createMock(LlmClientInterface::class);

        $result = (new KnowledgeBaseIngestionPipeline($llm, $registry))->ingest('kb-test');

        $this->assertNull($result);
        $this->assertSame('failed', $registry->find('kb-test')['status']);
    }

    public function test_ingest_happy_path_embeds_and_marks_ready(): void
    {
        $this->seedSourceFile('kb-test.sqlite');

        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-test', 'Test KB', 'desc', 'kb-test.sqlite');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0, 0.0]]);

        $result = (new KnowledgeBaseIngestionPipeline($llm, $registry))->ingest('kb-test');

        $this->assertNotNull($result);
        $this->assertSame('ready', $result['status']);
        $this->assertSame(1, $result['chunk_count']);
        $this->assertSame('ready', $registry->find('kb-test')['status']);
    }

    public function test_ingest_with_no_text_content_fails(): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        $pdo = Storage::openSqlite(Storage::dbPath('kb-empty.sqlite'));
        $pdo->exec('CREATE TABLE numbers (n INTEGER)');
        $pdo->exec('INSERT INTO numbers (n) VALUES (1)');

        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-empty', 'Empty KB', 'desc', 'kb-empty.sqlite');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('embeddings');

        $result = (new KnowledgeBaseIngestionPipeline($llm, $registry))->ingest('kb-empty');

        $this->assertNull($result);
        $this->assertSame('failed', $registry->find('kb-empty')['status']);
    }

    public function test_ingest_embedding_failure_marks_failed(): void
    {
        $this->seedSourceFile('kb-test.sqlite');

        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-test', 'Test KB', 'desc', 'kb-test.sqlite');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willThrowException(new \RuntimeException('down'));

        $result = (new KnowledgeBaseIngestionPipeline($llm, $registry))->ingest('kb-test');

        $this->assertNull($result);
        $this->assertSame('failed', $registry->find('kb-test')['status']);
    }

    public function test_reingesting_replaces_rather_than_accumulates(): void
    {
        $this->seedSourceFile('kb-test.sqlite');

        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-test', 'Test KB', 'desc', 'kb-test.sqlite');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0, 0.0]]);

        $pipeline = new KnowledgeBaseIngestionPipeline($llm, $registry);
        $first = $pipeline->ingest('kb-test');
        $second = $pipeline->ingest('kb-test');

        $this->assertSame($first['chunk_count'], $second['chunk_count']);
    }
}
