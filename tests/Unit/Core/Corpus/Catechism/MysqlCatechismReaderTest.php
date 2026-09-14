<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\Catechism;

use TAW\Core\Corpus\Catechism\MysqlCatechismReader;
use TAW\Tests\Support\FakeWpdb;
use TAW\Tests\TestCase;

final class MysqlCatechismReaderTest extends TestCase
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
        $p = $this->wpdb->prefix . 'taw_corpus_catechism_';

        $this->wpdb->exec("CREATE TABLE {$p}parts (id INTEGER PRIMARY KEY, edition TEXT, source_id INTEGER, name TEXT, part_order INTEGER)");
        $this->wpdb->exec("CREATE TABLE {$p}sections (id INTEGER PRIMARY KEY, edition TEXT, source_id INTEGER, part_id INTEGER, title TEXT, section_order INTEGER)");
        $this->wpdb->exec("CREATE TABLE {$p}chapters (id INTEGER PRIMARY KEY, edition TEXT, source_id INTEGER, section_id INTEGER, title TEXT, chapter_order INTEGER)");
        $this->wpdb->exec("CREATE TABLE {$p}paragraphs (id INTEGER PRIMARY KEY, edition TEXT, source_id INTEGER, chapter_id INTEGER, section_id INTEGER, part_id INTEGER, paragraph_number INTEGER, question_text TEXT, answer_text TEXT)");

        // pius-x edition: one part, one section, two chapters.
        $this->wpdb->exec("INSERT INTO {$p}parts VALUES (1, 'pius-x', 5, 'De la Doctrina Cristiana', 1)");
        $this->wpdb->exec("INSERT INTO {$p}sections VALUES (1, 'pius-x', 6, 5, 'Lección preliminar', 1)");
        $this->wpdb->exec("INSERT INTO {$p}chapters VALUES (1, 'pius-x', 10, 6, 'Lección preliminar', 1)");
        // A chapter heading with zero paragraphs of its own, in the same
        // section as real content — the source book's "CAPÍTULO II | ..."
        // group-header shape (see CatechismReader's docblock). Must
        // survive in parts()'s output.
        $this->wpdb->exec("INSERT INTO {$p}chapters VALUES (2, 'pius-x', 11, 6, 'Segunda lección', 2)");

        $this->wpdb->exec("INSERT INTO {$p}paragraphs VALUES (1, 'pius-x', 1, 10, 6, 5, 1, '¿Sois cristiano?', 'Sí, señor; soy cristiano por la gracia de Dios.')");
        $this->wpdb->exec("INSERT INTO {$p}paragraphs VALUES (2, 'pius-x', 2, 10, 6, 5, 2, '¿Por qué decís por la gracia de Dios?', 'Digo por la gracia de Dios porque el ser cristiano es un don gratuito.')");

        // (deliberately no paragraphs row for chapter_id 2 / source_id 11)

        // A wholly empty part/section/chapter branch — nothing anywhere
        // under it — proves pruning still happens at that level.
        $this->wpdb->exec("INSERT INTO {$p}parts VALUES (3, 'pius-x', 1, 'The Creed', 2)");
        $this->wpdb->exec("INSERT INTO {$p}sections VALUES (3, 'pius-x', 2, 1, 'On the Apostles Creed', 1)");
        $this->wpdb->exec("INSERT INTO {$p}chapters VALUES (4, 'pius-x', 30, 2, 'Empty chapter', 1)");

        // A second edition sharing the same source_id space — proves
        // every query scopes on edition, not just source_id.
        $this->wpdb->exec("INSERT INTO {$p}parts VALUES (5, 'jp2', 5, 'A Different Catechism', 1)");
        $this->wpdb->exec("INSERT INTO {$p}sections VALUES (5, 'jp2', 6, 5, 'Otra sección', 1)");
        $this->wpdb->exec("INSERT INTO {$p}chapters VALUES (6, 'jp2', 10, 6, 'Otro capítulo', 1)");
        $this->wpdb->exec("INSERT INTO {$p}paragraphs VALUES (3, 'jp2', 1, 10, 6, 5, 1, 'Otra pregunta', 'Otra respuesta.')");
    }

    public function test_is_installed_reflects_rows_for_that_specific_edition(): void
    {
        $this->assertTrue(MysqlCatechismReader::isInstalled('pius-x'));
        $this->assertTrue(MysqlCatechismReader::isInstalled('jp2'));
        $this->assertFalse(MysqlCatechismReader::isInstalled('unknown-edition'));
    }

    public function test_is_installed_is_false_when_the_table_does_not_exist_at_all(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();

        $this->assertFalse(MysqlCatechismReader::isInstalled('pius-x'));
    }

    public function test_parts_scopes_to_the_requested_edition_only(): void
    {
        $result = (new MysqlCatechismReader())->parts('pius-x');

        $this->assertCount(1, $result);
        $this->assertSame('De la Doctrina Cristiana', $result[0]['name']);
    }

    public function test_parts_keeps_a_zero_paragraph_chapter_when_its_section_has_other_content(): void
    {
        $result = (new MysqlCatechismReader())->parts('pius-x');

        $chapters = $result[0]['sections'][0]['chapters'];
        $titles = array_column($chapters, 'title');
        $this->assertContains('Segunda lección', $titles, 'A zero-paragraph group-header chapter must survive alongside real content in its section.');

        $header = $chapters[array_search('Segunda lección', $titles, true)];
        $this->assertSame(0, $header['paragraph_count']);
    }

    public function test_parts_filters_out_a_wholly_empty_section_and_prunes_its_empty_part(): void
    {
        $result = (new MysqlCatechismReader())->parts('pius-x');

        $names = array_column($result, 'name');
        $this->assertNotContains('The Creed', $names, 'Empty stub part must not surface.');
    }

    public function test_chapter_returns_paragraphs_with_breadcrumb_scoped_to_edition(): void
    {
        $result = (new MysqlCatechismReader())->chapter('pius-x', 10);

        $this->assertNotNull($result);
        $this->assertSame('De la Doctrina Cristiana', $result['part']['name']);
        $this->assertCount(2, $result['paragraphs']);
    }

    public function test_chapter_does_not_leak_another_editions_same_source_id(): void
    {
        $result = (new MysqlCatechismReader())->chapter('pius-x', 10);

        $this->assertNotNull($result);
        $this->assertSame('¿Sois cristiano?', $result['paragraphs'][0]['question_text']);
        // The jp2 edition's chapter also has source_id 10 — must not
        // have been the one returned.
        $this->assertNotSame('Otra pregunta', $result['paragraphs'][0]['question_text']);
    }

    public function test_chapter_returns_null_for_the_empty_chapter(): void
    {
        $this->assertNull((new MysqlCatechismReader())->chapter('pius-x', 11));
    }

    public function test_search_paragraphs_matches_across_question_and_answer_columns(): void
    {
        $results = (new MysqlCatechismReader())->searchParagraphs('pius-x', 'gracia');

        $this->assertCount(2, $results);
        $this->assertStringContainsString('<mark>', $results[0]['excerpt']);
    }

    public function test_search_paragraphs_scopes_to_the_requested_edition(): void
    {
        $results = (new MysqlCatechismReader())->searchParagraphs('jp2', 'respuesta');

        $this->assertCount(1, $results);
        $this->assertSame('Otro capítulo', $results[0]['chapter_title']);

        // The same word/edition combination must never surface pius-x rows.
        $this->assertSame([], (new MysqlCatechismReader())->searchParagraphs('pius-x', 'respuesta'));
    }

    /**
     * Same innodb_ft_min_token_size handling MysqlBibleReader needed after
     * a real production incident — applied here from the start.
     */
    public function test_search_paragraphs_ignores_words_too_short_for_innodb_to_have_indexed(): void
    {
        $results = (new MysqlCatechismReader())->searchParagraphs('pius-x', 'gracia de Dios');

        $this->assertCount(2, $results);
    }

    public function test_search_paragraphs_returns_nothing_when_every_word_is_too_short(): void
    {
        $this->assertSame([], (new MysqlCatechismReader())->searchParagraphs('pius-x', 'de y'));
    }

    public function test_search_paragraphs_with_an_empty_query_returns_nothing_without_hitting_the_db(): void
    {
        $this->assertSame([], (new MysqlCatechismReader())->searchParagraphs('pius-x', '   '));
    }
}
