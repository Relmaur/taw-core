<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\Bible;

use TAW\Core\Corpus\Bible\MysqlBibleReader;
use TAW\Tests\Support\FakeWpdb;
use TAW\Tests\TestCase;

final class MysqlBibleReaderTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    private function seedFixture(): void
    {
        $p = $this->wpdb->prefix . 'taw_corpus_bible_';

        $this->wpdb->exec("CREATE TABLE {$p}books (id INTEGER, testament TEXT, division TEXT, name TEXT, full_name TEXT, latin_name TEXT, slug TEXT, abbreviation TEXT, canon TEXT, book_order INTEGER)");
        $this->wpdb->exec("CREATE TABLE {$p}chapters (id INTEGER, book_id INTEGER, chapter_number INTEGER)");
        $this->wpdb->exec("CREATE TABLE {$p}verses (id INTEGER, book_id INTEGER, chapter_id INTEGER, verse_number INTEGER, verse_label TEXT, text TEXT, is_editorial_addition INTEGER)");
        $this->wpdb->exec("CREATE TABLE {$p}sections (id INTEGER, book_id INTEGER, parent_id INTEGER, kind TEXT, heading TEXT, subheading TEXT, body TEXT, start_chapter INTEGER, start_verse INTEGER, end_chapter INTEGER, end_verse INTEGER, position INTEGER)");
        $this->wpdb->exec("CREATE TABLE {$p}notes (id INTEGER, book_id INTEGER, type TEXT, marker TEXT, body TEXT, start_chapter INTEGER, start_verse INTEGER, end_chapter INTEGER, end_verse INTEGER, position INTEGER)");

        $this->wpdb->exec("INSERT INTO {$p}books VALUES (1, 'Antiguo Testamento', 'Pentateuco', 'Génesis', 'Génesis', 'Genesis', 'genesis', 'Gén', 'protocanonical', 1)");
        $this->wpdb->exec("INSERT INTO {$p}books VALUES (2, 'Antiguo Testamento', 'Pentateuco', 'Éxodo', 'Éxodo', 'Exodus', 'exodus', 'Éx', 'protocanonical', 2)");
        $this->wpdb->exec("INSERT INTO {$p}books VALUES (3, 'Nuevo Testamento', 'Evangelios', 'San Juan', 'Evangelio según San Juan', 'Iohannes', 'iohannes', 'Jn', 'protocanonical', 3)");

        $this->wpdb->exec("INSERT INTO {$p}chapters VALUES (10, 1, 1)");
        $this->wpdb->exec("INSERT INTO {$p}chapters VALUES (11, 1, 2)");
        $this->wpdb->exec("INSERT INTO {$p}chapters VALUES (20, 2, 1)");

        $this->wpdb->exec("INSERT INTO {$p}verses VALUES (100, 1, 10, 1, '1', 'Al principio creó Dios el cielo y la tierra.', 0)");
        $this->wpdb->exec("INSERT INTO {$p}verses VALUES (101, 1, 10, 2, '2', 'Y dijo Dios: haya luz; y hubo luz.', 0)");
        $this->wpdb->exec("INSERT INTO {$p}verses VALUES (102, 1, 10, 3, '3', 'Vio Dios que la luz era buena.', 0)");
        $this->wpdb->exec("INSERT INTO {$p}verses VALUES (110, 1, 11, 1, '1', 'Fueron acabados los cielos y la tierra.', 0)");

        $this->wpdb->exec("INSERT INTO {$p}sections VALUES (1, 1, NULL, 'pericope', 'La creación del cielo y la tierra', NULL, NULL, 1, 1, 1, 3, 1001)");
        $this->wpdb->exec("INSERT INTO {$p}sections VALUES (2, 1, NULL, 'part_title', 'I. Desde la Creación', NULL, NULL, 1, 1, 2, 25, 1000)");

        $this->wpdb->exec("INSERT INTO {$p}notes VALUES (1, 1, 'commentary', '1', 'Nota de prueba sobre la luz.', 1, 2, 1, 2, 0)");
    }

    public function test_is_installed_reflects_table_existence_and_row_count(): void
    {
        $this->assertTrue(MysqlBibleReader::isInstalled());

        $p = $this->wpdb->prefix . 'taw_corpus_bible_';
        $this->wpdb->exec("DELETE FROM {$p}books");
        $this->assertFalse(MysqlBibleReader::isInstalled());
    }

    public function test_is_installed_is_false_when_the_table_does_not_exist_at_all(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();

        $this->assertFalse(MysqlBibleReader::isInstalled());
    }

    public function test_books_are_grouped_by_testament_then_division_in_book_order(): void
    {
        $result = (new MysqlBibleReader())->books();

        $this->assertCount(2, $result);
        $this->assertSame('Antiguo Testamento', $result[0]['testament']);
        $this->assertCount(1, $result[0]['divisions']);
        $this->assertSame('Pentateuco', $result[0]['divisions'][0]['division']);
        $this->assertCount(2, $result[0]['divisions'][0]['books']);
        $this->assertSame('genesis', $result[0]['divisions'][0]['books'][0]['slug']);
        $this->assertSame(2, $result[0]['divisions'][0]['books'][0]['chapter_count']);

        $this->assertSame('Nuevo Testamento', $result[1]['testament']);
        $this->assertSame('iohannes', $result[1]['divisions'][0]['books'][0]['slug']);
    }

    public function test_chapter_returns_verses_sections_and_notes_scoped_to_that_chapter(): void
    {
        $result = (new MysqlBibleReader())->chapter('genesis', 1);

        $this->assertNotNull($result);
        $this->assertSame('genesis', $result['book']['slug']);
        $this->assertCount(3, $result['verses']);
        $this->assertSame('Y dijo Dios: haya luz; y hubo luz.', $result['verses'][1]['text']);
        $this->assertCount(2, $result['sections']);
        $this->assertCount(1, $result['notes']);
        $this->assertSame('1', $result['notes'][0]['marker']);
    }

    public function test_chapter_two_only_includes_the_spanning_section(): void
    {
        $result = (new MysqlBibleReader())->chapter('genesis', 2);

        $this->assertNotNull($result);
        $this->assertCount(1, $result['sections']);
        $this->assertSame('part_title', $result['sections'][0]['kind']);
        $this->assertCount(0, $result['notes']);
    }

    public function test_chapter_returns_null_for_unknown_book_slug(): void
    {
        $this->assertNull((new MysqlBibleReader())->chapter('nonexistent', 1));
    }

    public function test_chapter_returns_null_for_a_chapter_number_that_does_not_exist(): void
    {
        $this->assertNull((new MysqlBibleReader())->chapter('genesis', 99));
    }

    public function test_search_verses_matches_all_words_regardless_of_order(): void
    {
        $results = (new MysqlBibleReader())->searchVerses('cielo Dios');

        $this->assertCount(1, $results);
        $this->assertSame('genesis', $results[0]['book_slug']);
        $this->assertSame(1, $results[0]['verse_number']);
        $this->assertStringContainsString('<mark>', $results[0]['excerpt']);
    }

    public function test_search_verses_with_an_empty_query_returns_nothing_without_hitting_the_db(): void
    {
        $this->assertSame([], (new MysqlBibleReader())->searchVerses('   '));
    }

    /**
     * Regression: a required `+word` term for a word shorter than
     * innodb_ft_min_token_size (default 3) can never match anything,
     * since InnoDB never indexed it — a query mixing a short word ("de")
     * with real words used to return zero results even though the real
     * words genuinely matched. Confirmed against a real MySQL server.
     */
    public function test_search_verses_ignores_words_too_short_for_innodb_to_have_indexed(): void
    {
        $results = (new MysqlBibleReader())->searchVerses('cielo de Dios');

        $this->assertCount(1, $results);
        $this->assertSame('genesis', $results[0]['book_slug']);
        $this->assertSame(1, $results[0]['verse_number']);
    }

    public function test_search_verses_returns_nothing_when_every_word_is_too_short(): void
    {
        $this->assertSame([], (new MysqlBibleReader())->searchVerses('de la y'));
    }

    public function test_search_verses_respects_a_differently_configured_min_token_size(): void
    {
        $this->wpdb->innodbFtMinTokenSize = 5;

        // "Dios" (4 chars) now falls below the configured minimum too —
        // only "principio" (9 chars) is treated as indexed/searchable.
        $results = (new MysqlBibleReader())->searchVerses('principio Dios');

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['verse_number']);
    }

    public function test_search_notes_matches_the_footnote_body(): void
    {
        $results = (new MysqlBibleReader())->searchNotes('prueba luz');

        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]['marker']);
        $this->assertStringContainsString('<mark>', $results[0]['excerpt']);
    }
}
