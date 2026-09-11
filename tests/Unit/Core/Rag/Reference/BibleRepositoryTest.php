<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Reference;

use PDO;
use TAW\Core\Rag\Reference\BibleRepository;
use TAW\Core\Rag\Reference\ReferenceImporter;
use TAW\Core\Rag\Reference\ReferenceSchema;
use TAW\Core\Rag\Reference\SchemaManager;
use TAW\Tests\TestCase;

final class BibleRepositoryTest extends TestCase
{
    private function seededDb(): PDO
    {
        $schema = ReferenceSchema::bible();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaManager::ensureTable($pdo, $schema);

        $importer = new ReferenceImporter();
        $rows = $importer->parse(dirname(__DIR__, 4) . '/fixtures/rag/bible-sample.csv', $schema);
        $importer->apply($pdo, $schema, $rows);

        return $pdo;
    }

    public function test_lookup_a_single_verse(): void
    {
        $repo = new BibleRepository($this->seededDb());

        $results = $repo->lookup('Genesis', '1', '1');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('In the beginning', $results[0]['text']);
    }

    public function test_lookup_a_whole_chapter_when_verse_omitted(): void
    {
        $repo = new BibleRepository($this->seededDb());

        $results = $repo->lookup('Genesis', '1');

        $this->assertCount(2, $results);
    }

    public function test_lookup_with_no_match_returns_empty(): void
    {
        $repo = new BibleRepository($this->seededDb());

        $this->assertSame([], $repo->lookup('Nonexistent Book', '99'));
    }

    public function test_creates_its_table_if_the_db_is_empty(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $repo = new BibleRepository($pdo);

        $this->assertSame([], $repo->lookup('Genesis', '1'));
    }
}
