<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Vector;

use PDO;
use TAW\Core\Rag\Vector\VectorCapability;
use TAW\Core\Rag\Vector\VectorRepository;
use TAW\Tests\TestCase;

final class VectorRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        VectorCapability::setOverrideForTests(null);
        parent::tearDown();
    }

    private function repository(): VectorRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new VectorRepository($pdo);
    }

    public function test_search_ranks_the_closest_chunk_first(): void
    {
        VectorCapability::setOverrideForTests(false);
        $repo = $this->repository();

        $repo->upsertChunks(1, ['about cats'], [[1.0, 0.0, 0.0]], 'test-model');
        $repo->upsertChunks(2, ['about dogs'], [[0.0, 1.0, 0.0]], 'test-model');
        $repo->upsertChunks(3, ['unrelated'], [[0.0, 0.0, 1.0]], 'test-model');

        $results = $repo->search([1.0, 0.0, 0.0], 2);

        $this->assertCount(2, $results);
        $this->assertSame(1, $results[0]['post_id']);
        $this->assertSame('about cats', $results[0]['content']);
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
    }

    public function test_delete_post_removes_its_chunks(): void
    {
        VectorCapability::setOverrideForTests(false);
        $repo = $this->repository();

        $repo->upsertChunks(1, ['a', 'b'], [[1.0, 0.0], [0.0, 1.0]], 'test-model');
        $repo->deletePost(1);

        $results = $repo->search([1.0, 0.0], 10);
        $this->assertSame([], $results);
    }

    public function test_reingesting_a_post_replaces_its_old_chunks(): void
    {
        VectorCapability::setOverrideForTests(false);
        $repo = $this->repository();

        $repo->upsertChunks(1, ['a', 'b', 'c'], [[1.0], [1.0], [1.0]], 'test-model');
        $repo->upsertChunks(1, ['only one chunk now'], [[1.0]], 'test-model');

        $results = $repo->search([1.0], 10);
        $matchingPost = array_filter($results, static fn (array $r): bool => $r['post_id'] === 1);

        $this->assertCount(1, $matchingPost);
        $this->assertSame('only one chunk now', array_values($matchingPost)[0]['content']);
    }

    public function test_mismatched_chunk_and_embedding_counts_throws(): void
    {
        $repo = $this->repository();

        $this->expectException(\InvalidArgumentException::class);
        $repo->upsertChunks(1, ['a', 'b'], [[1.0]], 'test-model');
    }

    /**
     * The real sqlite-vec extension is confirmed absent everywhere — this
     * forces VectorCapability to (falsely) claim it's available, so the
     * `CREATE VIRTUAL TABLE ... USING vec0(...)` call genuinely fails
     * (no such module), and asserts search() catches that and still
     * returns correct results via the brute-force fallback rather than
     * throwing.
     */
    public function test_search_still_works_if_capability_check_lies_and_the_extension_is_missing(): void
    {
        VectorCapability::setOverrideForTests(true);
        $repo = $this->repository();

        $repo->upsertChunks(1, ['about cats'], [[1.0, 0.0]], 'test-model');

        $results = $repo->search([1.0, 0.0], 5);

        $this->assertCount(1, $results);
        $this->assertSame('about cats', $results[0]['content']);
    }
}
