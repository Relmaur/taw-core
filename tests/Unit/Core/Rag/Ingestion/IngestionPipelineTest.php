<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Ingestion;

use Brain\Monkey\Functions;
use TAW\Core\Rag\Ingestion\IngestionPipeline;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Storage;
use TAW\Tests\TestCase;

final class IngestionPipelineTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-rag-ingestion-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
        Functions\when('wp_strip_all_tags')->alias(static fn (string $s): string => strip_tags($s));
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

    private function fakePost(int $id, string $content): \WP_Post
    {
        return new \WP_Post(['ID' => $id, 'post_content' => $content, 'post_type' => 'post']);
    }

    private function vectorsDb(): \PDO
    {
        Storage::ensureProtectedDir(Storage::dir());

        $pdo = new \PDO('sqlite:' . Storage::dbPath('taw_vectors.sqlite'));
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        \TAW\Core\Rag\Vector\SchemaManager::ensureVectorSchema($pdo);

        return $pdo;
    }

    public function test_ingest_post_embeds_and_stores_chunks(): void
    {
        Functions\when('get_post')->justReturn($this->fakePost(42, 'Hello world, this is the post content.'));

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0, 0.0, 0.0]]);

        (new IngestionPipeline($llm))->ingestPost(42);

        $pdo = $this->vectorsDb();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM taw_rag_chunks WHERE post_id = 42')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_ingest_post_with_empty_content_removes_any_existing_vectors(): void
    {
        Functions\when('get_post')->justReturn($this->fakePost(7, ''));

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0]]);

        // Seed one chunk directly, then ingest empty content.
        $pdo = $this->vectorsDb();
        \TAW\Core\Rag\Vector\SchemaManager::ensureVectorSchema($pdo);
        $pdo->exec("INSERT INTO taw_rag_chunks (post_id, chunk_index, content, embedding, model, updated_at) VALUES (7, 0, 'stale', X'00', 'm', '2020-01-01')");

        (new IngestionPipeline($llm))->ingestPost(7);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM taw_rag_chunks WHERE post_id = 7')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_nonexistent_post_is_a_no_op(): void
    {
        Functions\when('get_post')->justReturn(null);

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('embeddings');

        (new IngestionPipeline($llm))->ingestPost(999);

        $this->addToAssertionCount(1);
    }

    public function test_llm_failure_does_not_throw_and_leaves_no_chunks(): void
    {
        Functions\when('get_post')->justReturn($this->fakePost(5, 'Some content.'));

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willThrowException(new \RuntimeException('API down'));

        (new IngestionPipeline($llm))->ingestPost(5);

        $pdo = $this->vectorsDb();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM taw_rag_chunks WHERE post_id = 5')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_remove_post_deletes_its_chunks(): void
    {
        $pdo = $this->vectorsDb();
        \TAW\Core\Rag\Vector\SchemaManager::ensureVectorSchema($pdo);
        $pdo->exec("INSERT INTO taw_rag_chunks (post_id, chunk_index, content, embedding, model, updated_at) VALUES (3, 0, 'x', X'00', 'm', '2020-01-01')");

        $llm = $this->createMock(LlmClientInterface::class);
        (new IngestionPipeline($llm))->removePost(3);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM taw_rag_chunks WHERE post_id = 3')->fetchColumn();
        $this->assertSame(0, $count);
    }

    /**
     * removePost() runs inline on every ineligible save_post (see
     * PostIndexer) — a storage failure here (a missing pdo_sqlite driver
     * on the host, permissions, disk full…) must degrade to a logged
     * warning, never an uncaught PDOException that would fatal the
     * editor's save request.
     */
    public function test_remove_post_does_not_throw_when_storage_is_unavailable(): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        // A directory sitting where the sqlite file should be forces PDO's
        // sqlite driver to fail opening it, simulating any storage failure
        // without needing to actually disable the pdo_sqlite extension.
        mkdir(Storage::dbPath('taw_vectors.sqlite'));

        $llm = $this->createMock(LlmClientInterface::class);

        (new IngestionPipeline($llm))->removePost(99);

        $this->addToAssertionCount(1);
    }

    public function test_ingest_post_does_not_throw_when_storage_is_unavailable(): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        mkdir(Storage::dbPath('taw_vectors.sqlite'));

        Functions\when('get_post')->justReturn($this->fakePost(11, 'Some content that will chunk fine.'));

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0, 0.0]]);

        (new IngestionPipeline($llm))->ingestPost(11);

        $this->addToAssertionCount(1);
    }
}
