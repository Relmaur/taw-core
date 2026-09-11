<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Reference;

use PDO;
use TAW\Core\Rag\Reference\ReferenceImporter;
use TAW\Core\Rag\Reference\ReferenceSchema;
use TAW\Core\Rag\Reference\SchemaManager;
use TAW\Tests\TestCase;

final class ReferenceImporterTest extends TestCase
{
    private function freshDb(ReferenceSchema $schema): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaManager::ensureTable($pdo, $schema);

        return $pdo;
    }

    public function test_bible_fixture_imports_all_rows(): void
    {
        $schema = ReferenceSchema::bible();
        $pdo = $this->freshDb($schema);
        $importer = new ReferenceImporter();

        $rows = $importer->parse(dirname(__DIR__, 4) . '/fixtures/rag/bible-sample.csv', $schema);
        $this->assertCount(3, $rows);

        $report = $importer->apply($pdo, $schema, $rows);

        $this->assertSame(3, $report->inserted);
        $this->assertSame(0, $report->updated);
        $this->assertSame(0, $report->skipped);
        $this->assertSame([], $report->warnings);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM verses')->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function test_catechism_fixture_imports_all_rows(): void
    {
        $schema = ReferenceSchema::catechism();
        $pdo = $this->freshDb($schema);
        $importer = new ReferenceImporter();

        $rows = $importer->parse(dirname(__DIR__, 4) . '/fixtures/rag/catechism-sample.json', $schema);
        $this->assertCount(3, $rows);

        $report = $importer->apply($pdo, $schema, $rows);

        $this->assertSame(3, $report->inserted);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function test_reimporting_identical_rows_is_a_true_no_op(): void
    {
        $schema = ReferenceSchema::bible();
        $pdo = $this->freshDb($schema);
        $importer = new ReferenceImporter();

        $rows = $importer->parse(dirname(__DIR__, 4) . '/fixtures/rag/bible-sample.csv', $schema);
        $importer->apply($pdo, $schema, $rows);

        $report = $importer->apply($pdo, $schema, $rows);

        $this->assertSame(0, $report->inserted);
        $this->assertSame(0, $report->updated);
        $this->assertSame(3, $report->skipped);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM verses')->fetchColumn();
        $this->assertSame(3, $count, 'No duplicate rows should be created on re-import.');
    }

    public function test_reimporting_with_changed_text_updates_in_place(): void
    {
        $schema = ReferenceSchema::bible();
        $pdo = $this->freshDb($schema);
        $importer = new ReferenceImporter();

        $importer->apply($pdo, $schema, [
            ['book' => 'Genesis', 'chapter' => '1', 'verse' => '1', 'text' => 'Original text.'],
        ]);

        $report = $importer->apply($pdo, $schema, [
            ['book' => 'Genesis', 'chapter' => '1', 'verse' => '1', 'text' => 'Corrected text.'],
        ]);

        $this->assertSame(0, $report->inserted);
        $this->assertSame(1, $report->updated);
        $this->assertSame(0, $report->skipped);

        $text = $pdo->query('SELECT text FROM verses')->fetchColumn();
        $this->assertSame('Corrected text.', $text);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM verses')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function test_plan_does_not_write_anything(): void
    {
        $schema = ReferenceSchema::bible();
        $pdo = $this->freshDb($schema);
        $importer = new ReferenceImporter();

        $rows = $importer->parse(dirname(__DIR__, 4) . '/fixtures/rag/bible-sample.csv', $schema);
        $report = $importer->plan($pdo, $schema, $rows);

        $this->assertSame(3, $report->inserted);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM verses')->fetchColumn();
        $this->assertSame(0, $count, 'plan() must not write rows.');
    }

    public function test_row_missing_natural_key_column_is_skipped_with_a_warning(): void
    {
        $schema = ReferenceSchema::bible();
        $pdo = $this->freshDb($schema);
        $importer = new ReferenceImporter();

        $report = $importer->apply($pdo, $schema, [
            ['book' => 'Genesis', 'chapter' => '1', 'verse' => '', 'text' => 'Missing verse number.'],
        ]);

        $this->assertSame(0, $report->inserted);
        $this->assertSame(1, $report->skipped);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('verse', $report->warnings[0]);
    }

    public function test_forName_rejects_unknown_schema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReferenceSchema::forName('quran');
    }

    public function test_unsupported_file_extension_throws(): void
    {
        $importer = new ReferenceImporter();

        $this->expectException(\InvalidArgumentException::class);
        $importer->parse('/tmp/not-a-real-file.txt', ReferenceSchema::bible());
    }

    public function test_file_missing_a_schema_column_throws(): void
    {
        $path = sys_get_temp_dir() . '/taw-rag-bad-columns-' . uniqid() . '.csv';
        file_put_contents($path, "book,chapter,text\nGenesis,1,\"No verse column.\"\n");

        $importer = new ReferenceImporter();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $importer->parse($path, ReferenceSchema::bible());
        } finally {
            @unlink($path);
        }
    }
}
