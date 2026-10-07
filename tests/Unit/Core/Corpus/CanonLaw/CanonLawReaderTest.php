<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\CanonLaw;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\CanonLaw\CanonLawReader;
use TAW\Core\Corpus\Storage;
use TAW\Tests\TestCase;

final class CanonLawReaderTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadsDir = sys_get_temp_dir() . '/taw-canon-law-reader-' . getmypid() . '-' . uniqid();
        mkdir($this->uploadsDir, 0777, true);

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));

        Storage::ensureProtectedDir(Storage::dir());
        self::seedFixtureDb(Storage::dbPath('canon-law-cic-1983.sqlite'));
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

    /**
     * The upstream export's exact schema: divisions nest to any depth, a
     * Book carries canons of its own before its first Title, and one
     * Title has no canons anywhere under it (must be pruned).
     */
    public static function seedFixtureDb(string $path): void
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE divisions (id INTEGER PRIMARY KEY, parent_id INTEGER, kind TEXT NOT NULL, title TEXT NOT NULL, "order" INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE canons (id INTEGER PRIMARY KEY, division_id INTEGER NOT NULL, number INTEGER NOT NULL, text TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE meta ("key" TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("CREATE VIRTUAL TABLE canons_fts USING fts5(text, content='canons', content_rowid='id')");

        $pdo->exec("INSERT INTO divisions VALUES (1, NULL, 'libro', 'LIBRO I: DE LAS NORMAS GENERALES (Cann. 1–203)', 1)");
        $pdo->exec("INSERT INTO divisions VALUES (2, 1, 'titulo', 'TÍTULO I – DE LAS LEYES ECLESIÁSTICAS (Cann. 7–22)', 1)");
        $pdo->exec("INSERT INTO divisions VALUES (3, 1, 'titulo', 'TÍTULO II – VACÍO', 3)");
        $pdo->exec("INSERT INTO divisions VALUES (4, 1, 'titulo', 'TÍTULO IV – DE LOS ACTOS (Cann. 35–93)', 2)");
        $pdo->exec("INSERT INTO divisions VALUES (5, 4, 'capitulo', 'CAPÍTULO I – NORMAS COMUNES', 1)");
        $pdo->exec("INSERT INTO divisions VALUES (6, NULL, 'libro', 'LIBRO IV: DE LA FUNCIÓN DE SANTIFICAR', 2)");

        $pdo->exec("INSERT INTO canons VALUES (1, 1, 1, 'Los cánones de este Código son sólo para la Iglesia latina.')");
        $pdo->exec("INSERT INTO canons VALUES (2, 1, 2, 'El Código no determina los ritos litúrgicos.')");
        $pdo->exec("INSERT INTO canons VALUES (7, 2, 7, 'La ley queda establecida cuando se promulga.')");
        $pdo->exec("INSERT INTO canons VALUES (35, 5, 35, 'n El acto administrativo singular, véase el c. 7.\n(n Indica que el texto corresponde a la nueva versión)')");
        $pdo->exec("INSERT INTO canons VALUES (1055, 6, 1055, '§ 1. La alianza matrimonial fue elevada a sacramento.\n§ 2. Por tanto, entre bautizados no puede haber contrato matrimonial válido.')");
        $pdo->exec("INSERT INTO canons_fts(canons_fts) VALUES('rebuild')");

        $pdo->exec("INSERT INTO meta VALUES ('surface', 'canon_law'), ('release_channel', 'beta'), ('source_revision', 'a5799f9d95e9')");
    }

    public function test_is_installed_checks_the_registered_filename(): void
    {
        $this->assertTrue(CanonLawReader::isInstalled('cic-1983'));
        $this->assertFalse(CanonLawReader::isInstalled('cic-1917'));
    }

    public function test_divisions_builds_a_nested_tree_in_order_with_ranges(): void
    {
        $tree = (new CanonLawReader())->divisions('cic-1983');

        $this->assertSame([1, 6], array_column($tree, 'id'));

        $book = $tree[0];
        $this->assertSame(2, $book['canon_count'], 'the Book\'s own preliminary canons');
        $this->assertSame(1, $book['canon_from']);
        $this->assertSame(35, $book['canon_to'], 'range spans the whole subtree');

        // Ordered by "order", not id — and the empty Title II is pruned.
        $this->assertSame([2, 4], array_column($book['children'], 'id'));
        $this->assertSame(0, $book['children'][1]['canon_count']);
        $this->assertSame([5], array_column($book['children'][1]['children'], 'id'));
    }

    public function test_division_returns_own_canons_with_breadcrumb_and_parsed_text(): void
    {
        $view = (new CanonLawReader())->division('cic-1983', 5);

        $this->assertNotNull($view);
        $this->assertSame('capitulo', $view['division']['kind']);
        $this->assertSame([1, 4], array_column($view['breadcrumb'], 'id'));
        $this->assertSame([35], array_column($view['canons'], 'number'));
        $this->assertSame('El acto administrativo singular, véase el c. 7.', $view['canons'][0]['text']);
        $this->assertTrue($view['canons'][0]['amended']);
    }

    public function test_division_without_own_canons_is_null(): void
    {
        $this->assertNull((new CanonLawReader())->division('cic-1983', 4));
        $this->assertNull((new CanonLawReader())->division('cic-1983', 999));
    }

    public function test_canons_by_number_keep_request_order_and_skip_unknown(): void
    {
        $canons = (new CanonLawReader())->canons('cic-1983', [1055, 9999, 7]);

        $this->assertSame([1055, 7], array_column($canons, 'number'));
        $this->assertSame(6, $canons[0]['division_id']);
        $this->assertSame([6], array_column($canons[0]['breadcrumb'], 'id'), 'breadcrumb ends with the canon\'s own division');
        $this->assertSame([1, 2], array_column($canons[1]['breadcrumb'], 'id'));
    }

    public function test_search_finds_canons_with_clean_marked_excerpts(): void
    {
        $results = (new CanonLawReader())->searchCanons('cic-1983', 'acto administrativo');

        $this->assertCount(1, $results);
        $this->assertSame(35, $results[0]['number']);
        $this->assertTrue($results[0]['amended']);
        $this->assertStringContainsString('<mark>', $results[0]['excerpt']);
        $this->assertStringNotContainsString('Indica que', $results[0]['excerpt']);
        $this->assertStringStartsNotWith('n ', $results[0]['excerpt']);
    }

    public function test_search_with_blank_query_returns_nothing(): void
    {
        $this->assertSame([], (new CanonLawReader())->searchCanons('cic-1983', '  '));
    }

    public function test_meta_reads_the_export_provenance(): void
    {
        $meta = (new CanonLawReader())->meta('cic-1983');

        $this->assertSame('beta', $meta['release_channel']);
        $this->assertSame('canon_law', $meta['surface']);
    }
}
