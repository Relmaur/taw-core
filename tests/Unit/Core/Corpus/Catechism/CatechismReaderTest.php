<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\Catechism;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\Catechism\CatechismReader;
use TAW\Core\Corpus\Storage;
use TAW\Tests\TestCase;

final class CatechismReaderTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-catechism-reader-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));

        Storage::ensureProtectedDir(Storage::dir());
        $this->seedFixtureDb(Storage::dbPath('catechism-pius-x.sqlite'));
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

        $pdo->exec('CREATE TABLE parts (id INTEGER PRIMARY KEY, name TEXT NOT NULL, "order" INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE sections (id INTEGER PRIMARY KEY, part_id INTEGER NOT NULL, title TEXT NOT NULL, "order" INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE chapters (id INTEGER PRIMARY KEY, section_id INTEGER NOT NULL, title TEXT NOT NULL, "order" INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE paragraphs (id INTEGER PRIMARY KEY, chapter_id INTEGER NOT NULL, section_id INTEGER NOT NULL, part_id INTEGER NOT NULL, paragraph_number INTEGER NOT NULL, question_text TEXT, answer_text TEXT NOT NULL)');
        $pdo->exec("CREATE VIRTUAL TABLE paragraphs_fts USING fts5(question_text, answer_text, content='paragraphs', content_rowid='id')");

        // Real content: one part, two sections, two chapters.
        $pdo->exec("INSERT INTO parts VALUES (5, 'De la Doctrina Cristiana', 1)");
        $pdo->exec("INSERT INTO sections VALUES (6, 5, 'Lección preliminar', 1)");
        $pdo->exec("INSERT INTO sections VALUES (7, 5, 'Del Credo', 2)");
        $pdo->exec("INSERT INTO chapters VALUES (10, 6, 'Lección preliminar', 1)");
        $pdo->exec("INSERT INTO chapters VALUES (20, 7, 'Del primer artículo', 1)");
        // A chapter heading with zero paragraphs of its own, followed by a
        // sibling chapter row with real content in the same section — the
        // source book's "CAPÍTULO II | ..." group-header shape (see
        // CatechismReader's own docblock). Must survive in parts()'s
        // output even though it has nothing to read directly.
        $pdo->exec("INSERT INTO chapters VALUES (21, 7, 'CAPÍTULO II | DEL SEGUNDO ARTÍCULO', 2)");
        $pdo->exec("INSERT INTO chapters VALUES (22, 7, 'De un sub-ítem numerado', 3)");

        $pdo->exec("INSERT INTO paragraphs VALUES (1, 10, 6, 5, 1, '¿Sois cristiano?', 'Sí, señor; soy cristiano por la gracia de Dios.')");
        $pdo->exec("INSERT INTO paragraphs VALUES (2, 10, 6, 5, 2, '¿Por qué decís por la gracia de Dios?', 'Digo por la gracia de Dios porque el ser cristiano es un don gratuito.')");
        // A run-on paragraph with no separated question — the known,
        // documented beta data-quality case (~1% of a real export).
        $pdo->exec("INSERT INTO paragraphs VALUES (3, 20, 7, 5, 3, NULL, '¿Quién creó el mundo?Dios creó el mundo.')");
        $pdo->exec("INSERT INTO paragraphs VALUES (4, 22, 7, 5, 4, NULL, 'Contenido del sub-ítem.')");

        // Stub structure with zero paragraphs under it — mirrors the real
        // upstream bug (empty Trent-catechism parts bundled into an early
        // Pius X export) this reader must never surface.
        $pdo->exec("INSERT INTO parts VALUES (1, 'The Creed', 2)");
        $pdo->exec("INSERT INTO sections VALUES (2, 1, 'On the Apostles Creed', 1)");
        $pdo->exec("INSERT INTO chapters VALUES (30, 2, 'Empty chapter', 1)");

        $pdo->exec('INSERT INTO paragraphs_fts(rowid, question_text, answer_text) SELECT id, question_text, answer_text FROM paragraphs');
    }

    public function test_parts_builds_the_full_part_section_chapter_tree(): void
    {
        $result = (new CatechismReader())->parts('pius-x');

        $this->assertCount(1, $result);
        $this->assertSame('De la Doctrina Cristiana', $result[0]['name']);
        $this->assertCount(2, $result[0]['sections']);
        $this->assertSame('Lección preliminar', $result[0]['sections'][0]['title']);
        $this->assertCount(1, $result[0]['sections'][0]['chapters']);
        $this->assertSame(2, $result[0]['sections'][0]['chapters'][0]['paragraph_count']);
    }

    public function test_parts_filters_out_a_wholly_empty_section_and_prunes_its_empty_part(): void
    {
        $result = (new CatechismReader())->parts('pius-x');

        $names = array_column($result, 'name');
        $this->assertNotContains('The Creed', $names, 'Empty stub part must not surface.');
    }

    public function test_parts_keeps_a_zero_paragraph_chapter_heading_when_its_section_has_other_content(): void
    {
        $result = (new CatechismReader())->parts('pius-x');

        $section = $result[0]['sections'][1];
        $this->assertSame('Del Credo', $section['title']);

        $titles = array_column($section['chapters'], 'title');
        $this->assertContains('CAPÍTULO II | DEL SEGUNDO ARTÍCULO', $titles, 'A zero-paragraph group-header chapter must survive alongside real content in its section.');

        $header = $section['chapters'][array_search('CAPÍTULO II | DEL SEGUNDO ARTÍCULO', $titles, true)];
        $this->assertSame(0, $header['paragraph_count']);
    }

    public function test_chapter_returns_paragraphs_in_order_with_part_and_section_breadcrumb(): void
    {
        $result = (new CatechismReader())->chapter('pius-x', 10);

        $this->assertNotNull($result);
        $this->assertSame('De la Doctrina Cristiana', $result['part']['name']);
        $this->assertSame('Lección preliminar', $result['section']['title']);
        $this->assertSame('Lección preliminar', $result['chapter']['title']);
        $this->assertCount(2, $result['paragraphs']);
        $this->assertSame('¿Sois cristiano?', $result['paragraphs'][0]['question_text']);
    }

    public function test_chapter_handles_a_null_question_text_paragraph(): void
    {
        $result = (new CatechismReader())->chapter('pius-x', 20);

        $this->assertNotNull($result);
        $this->assertNull($result['paragraphs'][0]['question_text']);
        $this->assertStringContainsString('Dios creó el mundo', $result['paragraphs'][0]['answer_text']);
    }

    public function test_chapter_returns_null_for_the_empty_stub_chapter(): void
    {
        $this->assertNull((new CatechismReader())->chapter('pius-x', 30));
    }

    public function test_chapter_returns_null_for_an_unknown_chapter_id(): void
    {
        $this->assertNull((new CatechismReader())->chapter('pius-x', 9999));
    }

    public function test_search_paragraphs_matches_question_or_answer_text(): void
    {
        // Both seeded paragraphs' answers genuinely contain "gracia".
        $results = (new CatechismReader())->searchParagraphs('pius-x', 'gracia');

        $this->assertCount(2, $results);
        $this->assertStringContainsString('<mark>', $results[0]['excerpt']);
    }

    public function test_search_paragraphs_matches_a_word_only_in_the_question_text(): void
    {
        $results = (new CatechismReader())->searchParagraphs('pius-x', 'decís');

        $this->assertCount(1, $results);
        $this->assertSame(2, $results[0]['paragraph_number']);
    }

    public function test_search_paragraphs_with_an_empty_query_returns_nothing(): void
    {
        $this->assertSame([], (new CatechismReader())->searchParagraphs('pius-x', '   '));
    }

    public function test_is_installed_reflects_whether_the_edition_file_exists(): void
    {
        $this->assertTrue(CatechismReader::isInstalled('pius-x'));
        $this->assertFalse(CatechismReader::isInstalled('unknown-edition'));

        unlink(Storage::dbPath('catechism-pius-x.sqlite'));

        $this->assertFalse(CatechismReader::isInstalled('pius-x'));
    }
}
