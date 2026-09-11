<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\Bible;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\Bible\MysqlBibleInstaller;
use TAW\Tests\Support\FakeWpdb;
use TAW\Tests\TestCase;

final class MysqlBibleInstallerTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;

        // esc_sql() just needs to be a real single-quote escaper for these
        // tests. Doubling the quote (not backslash-escaping) is what both
        // real MySQL and the SQLite engine backing FakeWpdb accept as a
        // standard-SQL quoted-string escape.
        Functions\when('esc_sql')->alias(static fn (string $s): string => str_replace("'", "''", $s));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * @return array{books: list<array<string, mixed>>, chapters: list<array<string, mixed>>, verses: list<array<string, mixed>>, sections: list<array<string, mixed>>, notes: list<array<string, mixed>>}
     */
    private function sampleData(): array
    {
        return [
            'books' => [
                ['id' => 1, 'slug' => 'genesis', 'name' => 'Génesis', 'full_name' => null, 'latin_name' => null, 'abbreviation' => 'Gén', 'canon' => 'protocanonical', 'testament' => 'Antiguo Testamento', 'division' => null, 'book_order' => 1],
            ],
            'chapters' => [
                ['id' => 10, 'book_id' => 1, 'chapter_number' => 1],
            ],
            'verses' => [
                ['id' => 100, 'book_id' => 1, 'chapter_id' => 10, 'verse_number' => 1, 'verse_label' => '1', 'text' => "Al principio creó Dios el cielo y la tierra.", 'is_editorial_addition' => 0],
            ],
            'sections' => [
                ['id' => 1, 'book_id' => 1, 'parent_id' => null, 'kind' => 'pericope', 'heading' => "La creación", 'subheading' => null, 'body' => null, 'start_chapter' => 1, 'start_verse' => 1, 'end_chapter' => 1, 'end_verse' => 1, 'position' => 1001],
            ],
            'notes' => [
                ['id' => 1, 'book_id' => 1, 'type' => 'commentary', 'marker' => null, 'body' => "Nota con 'comilla' y salto\nde línea.", 'start_chapter' => 1, 'start_verse' => 1, 'end_chapter' => 1, 'end_verse' => 1, 'position' => 0],
            ],
        ];
    }

    public function test_install_creates_tables_and_loads_every_row(): void
    {
        MysqlBibleInstaller::install($this->sampleData());

        $p = $this->wpdb->prefix . 'taw_corpus_bible_';
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}books"));
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}chapters"));
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}verses"));
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}sections"));
        $this->assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}notes"));
    }

    public function test_install_writes_real_null_not_empty_string_for_nullable_columns(): void
    {
        MysqlBibleInstaller::install($this->sampleData());

        $p = $this->wpdb->prefix . 'taw_corpus_bible_';
        $division = $this->wpdb->get_var("SELECT division FROM {$p}books WHERE id = 1");
        $parentId = $this->wpdb->get_var("SELECT parent_id FROM {$p}sections WHERE id = 1");

        $this->assertNull($division);
        $this->assertNull($parentId);
    }

    public function test_install_escapes_quotes_and_preserves_special_characters_in_text(): void
    {
        MysqlBibleInstaller::install($this->sampleData());

        $p = $this->wpdb->prefix . 'taw_corpus_bible_';
        $body = $this->wpdb->get_var("SELECT body FROM {$p}notes WHERE id = 1");

        $this->assertSame("Nota con 'comilla' y salto\nde línea.", $body);
    }

    public function test_install_is_idempotent_and_truncates_before_reloading(): void
    {
        MysqlBibleInstaller::install($this->sampleData());

        $second = $this->sampleData();
        $second['books'][] = ['id' => 2, 'slug' => 'exodus', 'name' => 'Éxodo', 'full_name' => null, 'latin_name' => null, 'abbreviation' => 'Éx', 'canon' => 'protocanonical', 'testament' => 'Antiguo Testamento', 'division' => null, 'book_order' => 2];

        MysqlBibleInstaller::install($second);

        $p = $this->wpdb->prefix . 'taw_corpus_bible_';
        $this->assertSame(2, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}books"));
    }

    /**
     * Regression: install() used to be void, and CorpusInstallCommand
     * reported the *input* export's own row counts as "success" — so a
     * run that wrote nothing at all to MySQL still printed a clean
     * "[OK] Imported 73 books..." (confirmed once in production). install()
     * must return what's actually in MySQL after the import.
     */
    public function test_install_returns_row_counts_actually_read_back_from_mysql(): void
    {
        $counts = MysqlBibleInstaller::install($this->sampleData());

        $this->assertSame(
            ['books' => 1, 'chapters' => 1, 'verses' => 1, 'sections' => 1, 'notes' => 1],
            $counts
        );
    }

    /**
     * Regression: every $wpdb->query() call here used to go unchecked —
     * a false-returning failure (permissions, a malformed statement, a
     * dropped connection) just fell through to `install()` returning
     * normally, meaning a real MySQL failure looked identical to success.
     */
    public function test_install_throws_with_the_real_mysql_error_when_a_query_fails(): void
    {
        $this->wpdb->failOnQueryContaining = 'INSERT INTO ' . $this->wpdb->prefix . 'taw_corpus_bible_verses';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Simulated failure/');

        MysqlBibleInstaller::install($this->sampleData());
    }

    public function test_install_rolls_back_the_transaction_when_a_query_fails(): void
    {
        $this->wpdb->failOnQueryContaining = 'INSERT INTO ' . $this->wpdb->prefix . 'taw_corpus_bible_verses';

        try {
            MysqlBibleInstaller::install($this->sampleData());
            $this->fail('Expected install() to throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertContains('ROLLBACK', $this->wpdb->recordedQueries);
    }
}
