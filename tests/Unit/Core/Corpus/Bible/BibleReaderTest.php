<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\Bible;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\Bible\BibleReader;
use TAW\Core\Corpus\Storage;
use TAW\Tests\TestCase;

final class BibleReaderTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-bible-reader-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));

        Storage::ensureProtectedDir(Storage::dir());
        $this->seedFixtureDb(Storage::dbPath(BibleReader::FILENAME));
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

    private function seedFixtureDb(string $path): void
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY, testament TEXT NOT NULL, division TEXT, name TEXT NOT NULL, full_name TEXT, latin_name TEXT, slug TEXT NOT NULL, abbreviation TEXT NOT NULL, canon TEXT NOT NULL, book_order INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE chapters (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL, chapter_number INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE verses (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL, chapter_id INTEGER NOT NULL, verse_number INTEGER NOT NULL, verse_label TEXT NOT NULL, text TEXT NOT NULL, is_editorial_addition INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE sections (id INTEGER PRIMARY KEY, book_id INTEGER NOT NULL, parent_id INTEGER, kind TEXT NOT NULL, heading TEXT NOT NULL, subheading TEXT, body TEXT, start_chapter INTEGER NOT NULL, start_verse INTEGER, end_chapter INTEGER NOT NULL, end_verse INTEGER, position INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, book_id INTEGER, anchor_type TEXT NOT NULL, anchor_id INTEGER NOT NULL, type TEXT NOT NULL, marker TEXT, body TEXT NOT NULL, start_chapter INTEGER, start_verse INTEGER, end_chapter INTEGER, end_verse INTEGER, position INTEGER NOT NULL)');
        $pdo->exec("CREATE VIRTUAL TABLE verses_fts USING fts5(text, content='verses', content_rowid='id')");
        $pdo->exec("CREATE VIRTUAL TABLE notes_fts USING fts5(body, content='notes', content_rowid='id')");

        // Book 1: Génesis (AT / Pentateuco), book_order 1, two chapters.
        $pdo->exec("INSERT INTO books VALUES (1, 'Antiguo Testamento', 'Pentateuco', 'Génesis', 'Génesis', 'Genesis', 'genesis', 'Gén', 'protocanonical', 1)");
        // Book 2: Éxodo (AT / Pentateuco), book_order 2 — same division as book 1, tests grouping.
        $pdo->exec("INSERT INTO books VALUES (2, 'Antiguo Testamento', 'Pentateuco', 'Éxodo', 'Éxodo', 'Exodus', 'exodus', 'Éx', 'protocanonical', 2)");
        // Book 3: San Juan (NT / Evangelios), book_order 3 — new testament + division.
        $pdo->exec("INSERT INTO books VALUES (3, 'Nuevo Testamento', 'Evangelios', 'San Juan', 'Evangelio según San Juan', 'Iohannes', 'iohannes', 'Jn', 'protocanonical', 3)");

        $pdo->exec('INSERT INTO chapters VALUES (10, 1, 1)'); // Genesis 1
        $pdo->exec('INSERT INTO chapters VALUES (11, 1, 2)'); // Genesis 2
        $pdo->exec('INSERT INTO chapters VALUES (20, 2, 1)'); // Exodus 1
        $pdo->exec('INSERT INTO chapters VALUES (30, 3, 1)'); // John 1

        $pdo->exec("INSERT INTO verses VALUES (100, 1, 10, 1, '1', 'Al principio creó Dios el cielo y la tierra.', 0)");
        $pdo->exec("INSERT INTO verses VALUES (101, 1, 10, 2, '2', 'Y dijo Dios: haya luz; y hubo luz.', 0)");
        $pdo->exec("INSERT INTO verses VALUES (102, 1, 10, 3, '3', 'Vio Dios que la luz era buena.', 0)");
        $pdo->exec("INSERT INTO verses VALUES (110, 1, 11, 1, '1', 'Fueron acabados los cielos y la tierra.', 0)");
        $pdo->exec("INSERT INTO verses VALUES (200, 2, 20, 1, '1', 'Estos son los nombres de los hijos de Israel.', 0)");
        $pdo->exec("INSERT INTO verses VALUES (300, 3, 30, 1, '1', 'En el principio era el Verbo.', 1)");

        $pdo->exec('INSERT INTO verses_fts(rowid, text) SELECT id, text FROM verses');

        // Pericope scoped to Genesis 1:1-3.
        $pdo->exec("INSERT INTO sections VALUES (1, 1, NULL, 'pericope', 'La creación del cielo y la tierra', NULL, NULL, 1, 1, 1, 3, 1001)");
        // Part title spanning Genesis chapters 1-2 — tests the cross-chapter overlap query.
        $pdo->exec("INSERT INTO sections VALUES (2, 1, NULL, 'part_title', 'I. Desde la Creación', NULL, NULL, 1, 1, 2, 25, 1000)");

        $pdo->exec("INSERT INTO notes VALUES (1, 1, 'App\\Models\\Verse', 101, 'commentary', '1', 'Nota de prueba sobre la luz.', 1, 2, 1, 2, 0)");

        $pdo->exec('INSERT INTO notes_fts(rowid, body) SELECT id, body FROM notes');
    }

    public function test_books_are_grouped_by_testament_then_division_in_book_order(): void
    {
        $result = (new BibleReader())->books();

        $this->assertCount(2, $result);
        $this->assertSame('Antiguo Testamento', $result[0]['testament']);
        $this->assertCount(1, $result[0]['divisions']);
        $this->assertSame('Pentateuco', $result[0]['divisions'][0]['division']);
        $this->assertCount(2, $result[0]['divisions'][0]['books']);
        $this->assertSame('genesis', $result[0]['divisions'][0]['books'][0]['slug']);
        $this->assertSame('exodus', $result[0]['divisions'][0]['books'][1]['slug']);
        // Genesis has 2 chapters seeded.
        $this->assertSame(2, $result[0]['divisions'][0]['books'][0]['chapter_count']);

        $this->assertSame('Nuevo Testamento', $result[1]['testament']);
        $this->assertSame('Evangelios', $result[1]['divisions'][0]['division']);
        $this->assertSame('iohannes', $result[1]['divisions'][0]['books'][0]['slug']);
    }

    public function test_chapter_returns_verses_sections_and_notes_scoped_to_that_chapter(): void
    {
        $result = (new BibleReader())->chapter('genesis', 1);

        $this->assertNotNull($result);
        $this->assertSame('genesis', $result['book']['slug']);
        $this->assertSame(1, $result['chapter_number']);
        $this->assertCount(3, $result['verses']);
        $this->assertSame('Y dijo Dios: haya luz; y hubo luz.', $result['verses'][1]['text']);

        // Both the ch1-only pericope and the ch1-2 part title overlap chapter 1.
        $this->assertCount(2, $result['sections']);

        $this->assertCount(1, $result['notes']);
        $this->assertSame('Nota de prueba sobre la luz.', $result['notes'][0]['body']);
        $this->assertSame('1', $result['notes'][0]['marker']);
    }

    public function test_chapter_two_only_includes_the_spanning_section_not_the_chapter_one_only_pericope(): void
    {
        $result = (new BibleReader())->chapter('genesis', 2);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['sections']);
        $this->assertSame('part_title', $result['sections'][0]['kind']);
        $this->assertCount(0, $result['notes']);
    }

    public function test_chapter_returns_null_for_unknown_book_slug(): void
    {
        $this->assertNull((new BibleReader())->chapter('nonexistent-book', 1));
    }

    public function test_chapter_returns_null_for_a_chapter_number_that_does_not_exist(): void
    {
        $this->assertNull((new BibleReader())->chapter('genesis', 99));
    }

    public function test_search_verses_matches_and_includes_a_highlighted_excerpt(): void
    {
        $results = (new BibleReader())->searchVerses('luz');

        $this->assertCount(2, $results);
        $this->assertStringContainsString('<mark>luz</mark>', $results[0]['excerpt']);
        $this->assertSame('genesis', $results[0]['book_slug']);
    }

    public function test_search_verses_with_a_quote_in_the_query_does_not_throw(): void
    {
        $results = (new BibleReader())->searchVerses('Dios "haya luz"');

        $this->assertIsArray($results);
    }

    /**
     * "Dios el cielo" (verse 100) means a literal-phrase MATCH for "cielo
     * Dios" — words present, reversed, not adjacent — would return nothing,
     * even though both words are genuinely in that verse. Every multi-word
     * query must AND the individual words instead of requiring them as one
     * exact contiguous phrase.
     */
    public function test_search_verses_matches_all_words_regardless_of_order_or_adjacency(): void
    {
        $results = (new BibleReader())->searchVerses('cielo Dios');

        $this->assertCount(1, $results);
        $this->assertSame('genesis', $results[0]['book_slug']);
        $this->assertSame(1, $results[0]['verse_number']);
    }

    public function test_search_verses_with_an_empty_query_returns_nothing(): void
    {
        $this->assertSame([], (new BibleReader())->searchVerses('   '));
    }

    public function test_search_notes_matches_the_footnote_body(): void
    {
        $results = (new BibleReader())->searchNotes('prueba');

        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]['marker']);
        $this->assertStringContainsString('<mark>prueba</mark>', $results[0]['excerpt']);
    }

    public function test_is_installed_reflects_whether_the_file_exists(): void
    {
        $this->assertTrue(BibleReader::isInstalled());

        unlink(Storage::dbPath(BibleReader::FILENAME));

        $this->assertFalse(BibleReader::isInstalled());
    }
}
