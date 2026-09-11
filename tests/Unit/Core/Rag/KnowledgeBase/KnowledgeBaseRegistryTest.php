<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\KnowledgeBase;

use Brain\Monkey\Functions;
use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseRegistry;
use TAW\Tests\TestCase;

final class KnowledgeBaseRegistryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = [];

        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->store[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value) {
            $this->store[$key] = $value;
            return true;
        });
    }

    public function test_all_always_includes_the_synthetic_wp_content_entry(): void
    {
        $registry = new KnowledgeBaseRegistry();

        $all = $registry->all();

        $this->assertCount(1, $all);
        $this->assertSame(KnowledgeBaseRegistry::WP_CONTENT_ID, $all[0]['id']);
        $this->assertSame('ready', $all[0]['status']);
    }

    public function test_add_registers_a_new_knowledge_base(): void
    {
        $registry = new KnowledgeBaseRegistry();

        $kb = $registry->add('kb-abc123', 'Test KB', 'A test knowledge base', 'kb-abc123.sqlite');

        $this->assertSame('kb-abc123', $kb['id']);
        $this->assertSame('pending', $kb['status']);

        $all = $registry->all();
        $this->assertCount(2, $all);
    }

    public function test_find_locates_an_uploaded_knowledge_base(): void
    {
        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-abc123', 'Test KB', 'desc', 'kb-abc123.sqlite');

        $found = $registry->find('kb-abc123');

        $this->assertNotNull($found);
        $this->assertSame('Test KB', $found['name']);
    }

    public function test_find_locates_the_wp_content_entry(): void
    {
        $found = (new KnowledgeBaseRegistry())->find(KnowledgeBaseRegistry::WP_CONTENT_ID);

        $this->assertNotNull($found);
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $this->assertNull((new KnowledgeBaseRegistry())->find('does-not-exist'));
    }

    public function test_update_status_updates_and_returns_the_record(): void
    {
        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-abc123', 'Test KB', 'desc', 'kb-abc123.sqlite');

        $updated = $registry->updateStatus('kb-abc123', 'ready', 42);

        $this->assertNotNull($updated);
        $this->assertSame('ready', $updated['status']);
        $this->assertSame(42, $updated['chunk_count']);
        $this->assertSame('ready', $registry->find('kb-abc123')['status']);
    }

    public function test_update_status_for_unknown_id_returns_null(): void
    {
        $this->assertNull((new KnowledgeBaseRegistry())->updateStatus('does-not-exist', 'ready'));
    }

    public function test_delete_removes_an_uploaded_knowledge_base(): void
    {
        $registry = new KnowledgeBaseRegistry();
        $registry->add('kb-abc123', 'Test KB', 'desc', 'kb-abc123.sqlite');

        $registry->delete('kb-abc123');

        $this->assertNull($registry->find('kb-abc123'));
        $this->assertCount(1, $registry->all());
    }

    public function test_generate_id_produces_distinct_ids(): void
    {
        $a = KnowledgeBaseRegistry::generateId();
        $b = KnowledgeBaseRegistry::generateId();

        $this->assertNotSame($a, $b);
        $this->assertStringStartsWith('kb-', $a);
    }
}
