<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Tools;

use Brain\Monkey\Functions;
use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseRegistry;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Storage;
use TAW\Core\Rag\Tools\SearchKnowledgeBaseTool;
use TAW\Core\Rag\Vector\VectorRepository;
use TAW\Tests\TestCase;

final class SearchKnowledgeBaseToolTest extends TestCase
{
    private string $uploadsDir;

    /** @var array<string, mixed> */
    private array $optionStore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-rag-search-tool-' . getmypid() . '-' . uniqid();
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

    public function test_definition_enum_reflects_the_registry(): void
    {
        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-test', 'Test KB', 'A test knowledge base', 'kb-test.sqlite');

        $llm = $this->createMock(LlmClientInterface::class);
        $definition = (new SearchKnowledgeBaseTool($llm, $registry))->definition();

        $enum = $definition['function']['parameters']['properties']['knowledge_base']['enum'];
        $this->assertContains(KnowledgeBaseRegistry::WP_CONTENT_ID, $enum);
        $this->assertContains('kb-test', $enum);
        $this->assertStringContainsString('kb-test', $definition['function']['description']);
        $this->assertStringContainsString('Test KB', $definition['function']['description']);
    }

    public function test_missing_arguments_return_an_error(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $result = (new SearchKnowledgeBaseTool($llm))->call(['knowledge_base' => 'wp-content']);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_unknown_knowledge_base_returns_an_error(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $result = (new SearchKnowledgeBaseTool($llm))->call(['knowledge_base' => 'nope', 'query' => 'hi']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unknown', $result['error']);
    }

    public function test_a_pending_knowledge_base_returns_a_not_ready_error(): void
    {
        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-test', 'Test KB', 'desc', 'kb-test.sqlite');

        $llm = $this->createMock(LlmClientInterface::class);
        $result = (new SearchKnowledgeBaseTool($llm, $registry))->call(['knowledge_base' => 'kb-test', 'query' => 'hi']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not ready', $result['error']);
    }

    public function test_searches_wp_content_and_includes_permalinks(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.test/hello/');

        Storage::ensureProtectedDir(Storage::dir());
        $pdo = Storage::openSqlite(Storage::dbPath('taw_vectors.sqlite'));
        (new VectorRepository($pdo))->upsertChunks(9, ['Hello content'], [[1.0, 0.0]], 'test-model');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0, 0.0]]);

        $result = (new SearchKnowledgeBaseTool($llm))->call(['knowledge_base' => 'wp-content', 'query' => 'hello']);

        $this->assertArrayHasKey('results', $result);
        $this->assertSame(9, $result['results'][0]['post_id']);
        $this->assertSame('https://example.test/hello/', $result['results'][0]['permalink']);
    }

    public function test_searches_an_uploaded_knowledge_base_without_a_permalink(): void
    {
        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-test', 'Test KB', 'desc', 'kb-test.sqlite');
        $registry->updateStatus('kb-test', 'ready', 1);

        Storage::ensureProtectedDir(Storage::dir());
        $pdo = Storage::openSqlite(Storage::dbPath('kb-test.sqlite'));
        (new VectorRepository($pdo))->replaceAll([
            ['content' => 'row one', 'embedding' => [1.0, 0.0]],
        ], 'test-model');

        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willReturn([[1.0, 0.0]]);

        $result = (new SearchKnowledgeBaseTool($llm, $registry))->call(['knowledge_base' => 'kb-test', 'query' => 'anything']);

        $this->assertArrayHasKey('results', $result);
        $this->assertSame('row one', $result['results'][0]['excerpt']);
        $this->assertArrayNotHasKey('permalink', $result['results'][0]);
    }

    public function test_embedding_failure_returns_an_error_not_an_exception(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('embeddings')->willThrowException(new \RuntimeException('down'));

        $result = (new SearchKnowledgeBaseTool($llm))->call(['knowledge_base' => 'wp-content', 'query' => 'hi']);

        $this->assertArrayHasKey('error', $result);
    }
}
