<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\ContentImportReferenceCommand;
use TAW\Tests\TestCase;

/**
 * With --db=<path> given explicitly, this command never boots WordPress —
 * it's pure PDO against an arbitrary SQLite file, which is what makes it
 * testable directly against the fixtures without a WpLoader/Brain Monkey
 * setup like ContentImportCommandTest needs.
 */
final class ContentImportReferenceCommandTest extends TestCase
{
    private string $tmpDb;
    private string $themeDir = '/nonexistent-theme-dir-never-used-with---db';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDb = sys_get_temp_dir() . '/taw-rag-reference-' . getmypid() . '-' . uniqid() . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDb);
        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__, 3) . '/tests/fixtures/rag/' . $name;
    }

    public function test_dry_run_reports_counts_and_writes_nothing(): void
    {
        $tester = new CommandTester(new ContentImportReferenceCommand($this->themeDir));
        $exit = $tester->execute([
            'schema' => 'bible',
            'file' => $this->fixture('bible-sample.csv'),
            '--db' => $this->tmpDb,
        ]);

        $display = $tester->getDisplay();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Dry run', $display);
        $this->assertStringContainsString('Rows read', $display);

        // File is created by SchemaManager::ensureTable() but no rows written.
        $pdo = new \PDO('sqlite:' . $this->tmpDb);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM verses')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function test_yes_applies_and_reports_success(): void
    {
        $tester = new CommandTester(new ContentImportReferenceCommand($this->themeDir));
        $exit = $tester->execute([
            'schema' => 'bible',
            'file' => $this->fixture('bible-sample.csv'),
            '--db' => $this->tmpDb,
            '--yes' => true,
        ]);

        $display = $tester->getDisplay();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Applied to', $display);

        $pdo = new \PDO('sqlite:' . $this->tmpDb);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM verses')->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function test_catechism_schema_against_json_fixture(): void
    {
        $tester = new CommandTester(new ContentImportReferenceCommand($this->themeDir));
        $exit = $tester->execute([
            'schema' => 'catechism',
            'file' => $this->fixture('catechism-sample.json'),
            '--db' => $this->tmpDb,
            '--yes' => true,
        ]);

        $this->assertSame(0, $exit);

        $pdo = new \PDO('sqlite:' . $this->tmpDb);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM entries')->fetchColumn();
        $this->assertSame(3, $count);
    }

    public function test_json_output_is_valid_json(): void
    {
        $tester = new CommandTester(new ContentImportReferenceCommand($this->themeDir));
        $tester->execute([
            'schema' => 'bible',
            'file' => $this->fixture('bible-sample.csv'),
            '--db' => $this->tmpDb,
            '--yes' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(3, $decoded['inserted']);
    }

    public function test_unknown_schema_fails(): void
    {
        $tester = new CommandTester(new ContentImportReferenceCommand($this->themeDir));
        $exit = $tester->execute([
            'schema' => 'quran',
            'file' => $this->fixture('bible-sample.csv'),
            '--db' => $this->tmpDb,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown schema', $tester->getDisplay());
    }

    public function test_missing_file_fails(): void
    {
        $tester = new CommandTester(new ContentImportReferenceCommand($this->themeDir));
        $exit = $tester->execute([
            'schema' => 'bible',
            'file' => '/tmp/does-not-exist-' . uniqid() . '.csv',
            '--db' => $this->tmpDb,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Cannot read file', $tester->getDisplay());
    }
}
