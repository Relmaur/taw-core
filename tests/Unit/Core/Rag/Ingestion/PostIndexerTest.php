<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Ingestion;

use Brain\Monkey\Functions;
use TAW\Core\Rag\Ingestion\PostIndexer;
use TAW\Core\Rag\Storage;
use TAW\Core\Rag\Vector\SchemaManager;
use TAW\Tests\TestCase;

final class PostIndexerTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-rag-postindexer-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        // RagSettings::indexedPostTypes() default: 'post,page'.
        Functions\when('get_option')->alias(static fn ($name, $default = false) => $default);
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

    private function fakePost(int $id, string $status, string $type = 'post', string $password = ''): \WP_Post
    {
        return new \WP_Post(['ID' => $id, 'post_status' => $status, 'post_type' => $type, 'post_password' => $password]);
    }

    private function seedChunk(int $postId): void
    {
        Storage::ensureProtectedDir(Storage::dir());
        $pdo = new \PDO('sqlite:' . Storage::dbPath('taw_vectors.sqlite'));
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        SchemaManager::ensureVectorSchema($pdo);
        $pdo->exec("INSERT INTO taw_rag_chunks (post_id, chunk_index, content, embedding, model, updated_at) VALUES ({$postId}, 0, 'x', X'00', 'm', '2020-01-01')");
    }

    private function chunkCount(int $postId): int
    {
        $pdo = new \PDO('sqlite:' . Storage::dbPath('taw_vectors.sqlite'));

        return (int) $pdo->query("SELECT COUNT(*) FROM taw_rag_chunks WHERE post_id = {$postId}")->fetchColumn();
    }

    public function test_publishing_an_indexed_type_schedules_ingestion(): void
    {
        Functions\expect('wp_schedule_single_event')
            ->once()
            ->with(\Mockery::type('int'), 'taw_rag_ingest_post', [42])
            ->andReturn(true);

        (new PostIndexer())->onSavePost(42, $this->fakePost(42, 'publish'));

        // Removal must not run for an eligible post — nothing to assert
        // against Storage since removePost() was never reached, but a
        // stray fatal from an unstubbed call would fail this test anyway.
        $this->addToAssertionCount(1);
    }

    public function test_unpublishing_a_previously_indexed_post_removes_its_chunks(): void
    {
        $this->seedChunk(7);
        Functions\expect('wp_schedule_single_event')->never();

        (new PostIndexer())->onSavePost(7, $this->fakePost(7, 'draft'));

        $this->assertSame(0, $this->chunkCount(7));
    }

    public function test_trashing_a_previously_indexed_post_removes_its_chunks(): void
    {
        $this->seedChunk(8);
        Functions\expect('wp_schedule_single_event')->never();

        (new PostIndexer())->onSavePost(8, $this->fakePost(8, 'trash'));

        $this->assertSame(0, $this->chunkCount(8));
    }

    public function test_password_protected_publish_is_not_indexed_and_is_removed_if_previously_indexed(): void
    {
        $this->seedChunk(9);
        Functions\expect('wp_schedule_single_event')->never();

        (new PostIndexer())->onSavePost(9, $this->fakePost(9, 'publish', 'post', 'sekrit'));

        $this->assertSame(0, $this->chunkCount(9));
    }

    public function test_revision_and_autosave_are_ignored(): void
    {
        Functions\when('wp_is_post_revision')->justReturn(true);
        Functions\expect('wp_schedule_single_event')->never();

        (new PostIndexer())->onSavePost(10, $this->fakePost(10, 'publish'));

        // Returning before either branch means Storage is never touched at
        // all — no vectors DB file gets created, which we confirm directly
        // rather than opening it (opening would create it if missing).
        $this->assertFileDoesNotExist(Storage::dbPath('taw_vectors.sqlite'));
    }

    public function test_non_indexed_post_type_is_not_scheduled(): void
    {
        Functions\expect('wp_schedule_single_event')->never();

        (new PostIndexer())->onSavePost(11, $this->fakePost(11, 'publish', 'attachment'));

        $this->assertSame(0, $this->chunkCount(11));
    }
}
