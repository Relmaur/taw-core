<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\CanonLaw;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\CanonLaw\MysqlCanonLawInstaller;
use TAW\Core\Corpus\CanonLaw\MysqlCanonLawReader;
use TAW\Tests\Support\FakeWpdb;
use TAW\Tests\TestCase;

/**
 * Installer and reader together: the reader is exercised over exactly the
 * rows the installer writes from a portable export.
 */
final class MysqlCanonLawTest extends TestCase
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
     * @return array{divisions: list<array<string, mixed>>, canons: list<array<string, mixed>>, meta: array<string, string|null>}
     */
    private function sampleData(): array
    {
        return [
            'meta' => ['surface' => 'canon_law', 'release_channel' => 'beta'],
            'divisions' => [
                ['source_id' => 1, 'parent_id' => null, 'kind' => 'libro', 'title' => 'LIBRO I: DE LAS NORMAS GENERALES', 'division_order' => 1],
                ['source_id' => 2, 'parent_id' => 1, 'kind' => 'titulo', 'title' => 'TÍTULO I – DE LAS LEYES ECLESIÁSTICAS', 'division_order' => 1],
                ['source_id' => 3, 'parent_id' => null, 'kind' => 'libro', 'title' => 'LIBRO VI: DE LAS SANCIONES', 'division_order' => 2],
            ],
            'canons' => [
                ['source_id' => 1, 'division_id' => 1, 'number' => 1, 'text' => 'Los cánones de este Código son sólo para la Iglesia latina.'],
                ['source_id' => 7, 'division_id' => 2, 'number' => 7, 'text' => 'La ley queda establecida cuando se promulga.'],
                ['source_id' => 1311, 'division_id' => 3, 'number' => 1311, 'text' => "- § 1. La Iglesia tiene derecho originario y propio a castigar con sanciones penales.\n§ 2. Quien preside en la Iglesia…"],
            ],
        ];
    }

    public function test_install_loads_rows_and_reports_counts_read_back(): void
    {
        $counts = MysqlCanonLawInstaller::install('cic-1983', $this->sampleData());

        $this->assertSame(['divisions' => 3, 'canons' => 3], $counts);
    }

    public function test_reinstalling_one_edition_never_touches_another(): void
    {
        MysqlCanonLawInstaller::install('cic-1917', $this->sampleData());
        MysqlCanonLawInstaller::install('cic-1983', $this->sampleData());
        MysqlCanonLawInstaller::install('cic-1983', $this->sampleData());

        $this->assertSame(3, count((new MysqlCanonLawReader())->canons('cic-1917', [1, 7, 1311])));
        $this->assertSame(3, count((new MysqlCanonLawReader())->canons('cic-1983', [1, 7, 1311])));
    }

    public function test_a_failed_insert_rolls_back_and_throws(): void
    {
        MysqlCanonLawInstaller::install('cic-1983', $this->sampleData());
        $this->wpdb->failOnQueryContaining = 'INSERT INTO wp_taw_corpus_canon_law_canons';

        try {
            MysqlCanonLawInstaller::install('cic-1983', $this->sampleData());
            $this->fail('Expected the install to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated failure', $e->getMessage());
        }

        $this->wpdb->failOnQueryContaining = null;
        $this->assertCount(3, (new MysqlCanonLawReader())->canons('cic-1983', [1, 7, 1311]), 'previous install survives');
    }

    public function test_reader_matches_the_sqlite_reader_shape(): void
    {
        MysqlCanonLawInstaller::install('cic-1983', $this->sampleData());
        $reader = new MysqlCanonLawReader();

        $this->assertTrue(MysqlCanonLawReader::isInstalled('cic-1983'));
        $this->assertFalse(MysqlCanonLawReader::isInstalled('cic-1917'));

        $tree = $reader->divisions('cic-1983');
        $this->assertSame([1, 3], array_column($tree, 'id'));
        $this->assertSame(1, $tree[0]['canon_count']);
        $this->assertSame(7, $tree[0]['canon_to']);
        $this->assertSame([2], array_column($tree[0]['children'], 'id'));

        $view = $reader->division('cic-1983', 3);
        $this->assertNotNull($view);
        $this->assertSame("§ 1. La Iglesia tiene derecho originario y propio a castigar con sanciones penales.\n§ 2. Quien preside en la Iglesia…", $view['canons'][0]['text']);
        $this->assertTrue($view['canons'][0]['amended']);

        $canons = $reader->canons('cic-1983', [7]);
        $this->assertSame([1, 2], array_column($canons[0]['breadcrumb'], 'id'));

        $this->assertSame('beta', $reader->meta('cic-1983')['release_channel']);
    }

    public function test_search_filters_short_words_and_cleans_excerpts(): void
    {
        MysqlCanonLawInstaller::install('cic-1983', $this->sampleData());

        $results = (new MysqlCanonLawReader())->searchCanons('cic-1983', 'derecho de castigar');

        $this->assertSame([1311], array_column($results, 'number'));
        $this->assertStringContainsString('<mark>derecho</mark>', $results[0]['excerpt']);
        $this->assertStringStartsNotWith('- ', $results[0]['excerpt']);
        $this->assertSame([], (new MysqlCanonLawReader())->searchCanons('cic-1983', 'de y'));
    }
}
