<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Reference;

use PDO;
use TAW\Core\Rag\Reference\CatechismRepository;
use TAW\Core\Rag\Reference\ReferenceImporter;
use TAW\Core\Rag\Reference\ReferenceSchema;
use TAW\Core\Rag\Reference\SchemaManager;
use TAW\Tests\TestCase;

final class CatechismRepositoryTest extends TestCase
{
    private function seededDb(): PDO
    {
        $schema = ReferenceSchema::catechism();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaManager::ensureTable($pdo, $schema);

        $importer = new ReferenceImporter();
        $rows = $importer->parse(dirname(__DIR__, 4) . '/fixtures/rag/catechism-sample.json', $schema);
        $importer->apply($pdo, $schema, $rows);

        return $pdo;
    }

    public function test_lookup_by_exact_part_and_question(): void
    {
        $repo = new CatechismRepository($this->seededDb());

        $results = $repo->lookup('First Part/1');

        $this->assertCount(1, $results);
        $this->assertSame('Placeholder Topic One', $results[0]['topic']);
    }

    public function test_lookup_by_topic_keyword(): void
    {
        $repo = new CatechismRepository($this->seededDb());

        $results = $repo->lookup('Two');

        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertSame('Placeholder Topic Two', $results[0]['topic']);
    }

    public function test_lookup_with_no_match_returns_empty(): void
    {
        $repo = new CatechismRepository($this->seededDb());

        $this->assertSame([], $repo->lookup('Completely Unrelated Nonsense'));
    }
}
