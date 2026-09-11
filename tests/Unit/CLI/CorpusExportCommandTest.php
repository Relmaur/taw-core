<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\CorpusExportCommand;
use TAW\Tests\TestCase;

final class CorpusExportCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-corpus-export-' . getmypid() . '-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = @new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it ?: [] as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function fixtureSqlite(): string
    {
        $path = $this->tmp . '/source.sqlite';
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE books (id INTEGER, testament TEXT, division TEXT, name TEXT, full_name TEXT, latin_name TEXT, slug TEXT, abbreviation TEXT, canon TEXT, book_order INTEGER)');
        $pdo->exec('CREATE TABLE chapters (id INTEGER, book_id INTEGER, chapter_number INTEGER)');
        $pdo->exec('CREATE TABLE verses (id INTEGER, book_id INTEGER, chapter_id INTEGER, verse_number INTEGER, verse_label TEXT, text TEXT, is_editorial_addition INTEGER)');
        $pdo->exec('CREATE TABLE sections (id INTEGER, book_id INTEGER, parent_id INTEGER, kind TEXT, heading TEXT, subheading TEXT, body TEXT, start_chapter INTEGER, start_verse INTEGER, end_chapter INTEGER, end_verse INTEGER, position INTEGER)');
        $pdo->exec('CREATE TABLE notes (id INTEGER, book_id INTEGER, anchor_type TEXT, anchor_id INTEGER, type TEXT, marker TEXT, body TEXT, start_chapter INTEGER, start_verse INTEGER, end_chapter INTEGER, end_verse INTEGER, position INTEGER)');

        $pdo->exec("INSERT INTO books VALUES (1, 'Antiguo Testamento', NULL, 'Génesis', NULL, NULL, 'genesis', 'Gén', 'protocanonical', 1)");
        $pdo->exec('INSERT INTO chapters VALUES (10, 1, 1)');
        $pdo->exec("INSERT INTO verses VALUES (100, 1, 10, 1, '1', 'Al principio...', 0)");
        $pdo->exec("INSERT INTO sections VALUES (1, 1, NULL, 'pericope', 'La creación', NULL, NULL, 1, 1, 1, 1, 1001)");
        $pdo->exec("INSERT INTO notes VALUES (1, 1, 'App\\Models\\Verse', 100, 'commentary', '1', 'Nota.', 1, 1, 1, 1, 0)");

        return $path;
    }

    public function test_exports_every_table_to_a_portable_json_file(): void
    {
        $source = $this->fixtureSqlite();
        $output = $this->tmp . '/export.json';

        $tester = new CommandTester(new CorpusExportCommand());
        $exit = $tester->execute(['path' => $source, 'output' => $output]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($output);

        $data = json_decode((string) file_get_contents($output), true);
        $this->assertCount(1, $data['books']);
        $this->assertSame('genesis', $data['books'][0]['slug']);
        $this->assertCount(1, $data['chapters']);
        $this->assertCount(1, $data['verses']);
        $this->assertCount(1, $data['sections']);
        $this->assertCount(1, $data['notes']);
        // anchor_type/anchor_id are dropped — not part of BibleReader's read set.
        $this->assertArrayNotHasKey('anchor_type', $data['notes'][0]);
    }

    public function test_rejects_a_missing_source_file(): void
    {
        $tester = new CommandTester(new CorpusExportCommand());
        $exit = $tester->execute(['path' => $this->tmp . '/nope.sqlite', 'output' => $this->tmp . '/out.json']);

        $this->assertSame(1, $exit);
    }

    public function test_rejects_a_non_sqlite_source_file(): void
    {
        $source = $this->tmp . '/not-a-db.sqlite';
        file_put_contents($source, 'plain text');

        $tester = new CommandTester(new CorpusExportCommand());
        $exit = $tester->execute(['path' => $source, 'output' => $this->tmp . '/out.json']);

        $this->assertSame(1, $exit);
    }

    public function test_rejects_a_sqlite_file_missing_the_expected_tables(): void
    {
        $source = $this->tmp . '/wrong-schema.sqlite';
        (new \PDO('sqlite:' . $source))->exec('CREATE TABLE unrelated (id INTEGER)');

        $tester = new CommandTester(new CorpusExportCommand());
        $exit = $tester->execute(['path' => $source, 'output' => $this->tmp . '/out.json']);

        $this->assertSame(1, $exit);
    }
}
