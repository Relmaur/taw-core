<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\Catechism;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\Catechism\MysqlCatechismInstaller;
use TAW\Tests\Support\FakeWpdb;
use TAW\Tests\TestCase;

final class MysqlCatechismInstallerTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\when('esc_sql')->alias(static fn (string $s): string => str_replace("'", "''", $s));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * @return array{parts: list<array<string, mixed>>, sections: list<array<string, mixed>>, chapters: list<array<string, mixed>>, paragraphs: list<array<string, mixed>>}
     */
    private function sampleData(): array
    {
        return [
            'parts' => [
                ['source_id' => 5, 'name' => 'De la Doctrina Cristiana', 'part_order' => 1],
            ],
            'sections' => [
                ['source_id' => 6, 'part_id' => 5, 'title' => 'Lección preliminar', 'section_order' => 1],
            ],
            'chapters' => [
                ['source_id' => 10, 'section_id' => 6, 'title' => 'Lección preliminar', 'chapter_order' => 1],
            ],
            'paragraphs' => [
                ['source_id' => 1, 'chapter_id' => 10, 'section_id' => 6, 'part_id' => 5, 'paragraph_number' => 1, 'question_text' => '¿Sois cristiano?', 'answer_text' => "Sí, señor; con la gracia de Dios."],
                ['source_id' => 2, 'chapter_id' => 10, 'section_id' => 6, 'part_id' => 5, 'paragraph_number' => 2, 'question_text' => null, 'answer_text' => "Texto sin pregunta separada."],
            ],
        ];
    }

    public function test_install_creates_tables_and_loads_every_row(): void
    {
        MysqlCatechismInstaller::install('pius-x', $this->sampleData());

        $p = $this->wpdb->prefix . 'taw_corpus_catechism_';
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}parts"));
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}sections"));
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}chapters"));
        $this->assertSame(2, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}paragraphs"));
    }

    public function test_install_tags_every_row_with_the_given_edition(): void
    {
        MysqlCatechismInstaller::install('pius-x', $this->sampleData());

        $p = $this->wpdb->prefix . 'taw_corpus_catechism_';
        $edition = $this->wpdb->get_var("SELECT edition FROM {$p}parts WHERE source_id = 5");

        $this->assertSame('pius-x', $edition);
    }

    public function test_install_writes_real_null_for_a_missing_question_text(): void
    {
        MysqlCatechismInstaller::install('pius-x', $this->sampleData());

        $p = $this->wpdb->prefix . 'taw_corpus_catechism_';
        $question = $this->wpdb->get_var("SELECT question_text FROM {$p}paragraphs WHERE source_id = 2");

        $this->assertNull($question);
    }

    /**
     * The distinguishing behavior vs. MysqlBibleInstaller (single corpus,
     * one table set): these tables are shared across every catechism
     * edition, so reloading one edition must use a scoped `DELETE ...
     * WHERE edition = ?`, never `TRUNCATE TABLE` — installing a second
     * edition must never touch the first edition's already-installed rows.
     */
    public function test_installing_a_second_edition_does_not_touch_the_first_editions_rows(): void
    {
        MysqlCatechismInstaller::install('pius-x', $this->sampleData());

        $otherEdition = $this->sampleData();
        $otherEdition['parts'][0]['source_id'] = 5; // same source_id, different edition — must not collide
        $otherEdition['parts'][0]['name'] = 'A Different Catechism';

        MysqlCatechismInstaller::install('jp2', $otherEdition);

        $p = $this->wpdb->prefix . 'taw_corpus_catechism_';
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}parts WHERE edition = 'pius-x'"));
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}parts WHERE edition = 'jp2'"));
        $this->assertSame(
            'De la Doctrina Cristiana',
            $this->wpdb->get_var("SELECT name FROM {$p}parts WHERE edition = 'pius-x'")
        );
    }

    public function test_install_is_idempotent_for_the_same_edition(): void
    {
        MysqlCatechismInstaller::install('pius-x', $this->sampleData());

        $second = $this->sampleData();
        $second['parts'][] = ['source_id' => 1, 'name' => 'Another Part', 'part_order' => 2];

        MysqlCatechismInstaller::install('pius-x', $second);

        $p = $this->wpdb->prefix . 'taw_corpus_catechism_';
        $this->assertSame(2, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}parts WHERE edition = 'pius-x'"));
    }

    public function test_install_returns_row_counts_actually_read_back_from_mysql(): void
    {
        $counts = MysqlCatechismInstaller::install('pius-x', $this->sampleData());

        $this->assertSame(
            ['parts' => 1, 'sections' => 1, 'chapters' => 1, 'paragraphs' => 2],
            $counts
        );
    }

    public function test_install_throws_with_the_real_mysql_error_when_a_query_fails(): void
    {
        $this->wpdb->failOnQueryContaining = 'INSERT INTO ' . $this->wpdb->prefix . 'taw_corpus_catechism_paragraphs';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Simulated failure/');

        MysqlCatechismInstaller::install('pius-x', $this->sampleData());
    }

    public function test_install_rolls_back_the_transaction_when_a_query_fails(): void
    {
        $this->wpdb->failOnQueryContaining = 'INSERT INTO ' . $this->wpdb->prefix . 'taw_corpus_catechism_paragraphs';

        try {
            MysqlCatechismInstaller::install('pius-x', $this->sampleData());
            $this->fail('Expected install() to throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertContains('ROLLBACK', $this->wpdb->recordedQueries);
    }
}
