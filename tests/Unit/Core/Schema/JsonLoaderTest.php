<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TAW\Core\Schema\Field;
use TAW\Core\Schema\JsonLoader;
use TAW\Core\Schema\Registry;
use TAW\Core\Schema\Schema;
use TAW\Core\Schema\Source;

/**
 * Discovery, reading, hydration and caching of taw-schema/*.json files.
 * Uses real temporary folders: glob/realpath/filemtime are the behavior
 * under test, not something worth stubbing.
 */
final class JsonLoaderTest extends SchemaTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/taw-schema-test-' . getmypid() . '-' . uniqid();
        mkdir($this->root . '/parent/taw-schema/post-types', 0777, true);
        mkdir($this->root . '/child/taw-schema', 0777, true);
        mkdir($this->root . '/content/taw-schema', 0777, true);

        Functions\when('get_template_directory')->justReturn($this->root . '/parent');
        Functions\when('get_stylesheet_directory')->justReturn($this->root . '/child');
        Functions\when('wp_get_environment_type')->justReturn('local');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    // ── Reading one file ─────────────────────────────────────────────────

    public function test_a_json_fieldset_hydrates_to_exactly_what_the_php_api_builds(): void
    {
        $result = JsonLoader::readFile(__DIR__ . '/fixtures/valid/book-details.json');
        $this->assertSame([], $result['errors']);

        $fromJson = JsonLoader::toDefinition($result['data'])->toArray();
        $fromPhp = Schema::fieldset('book_details')->title('Book details')->on('book')->context('normal')->fields([
            Field::text('subtitle')->label('Subtitle')->required(),
            Field::image('cover')->label('Cover'),
            Field::select('format')->label('Format')->options(['hardcover' => 'Hardcover', 'ebook' => 'E-book']),
            Field::group('publisher')->fields([Field::text('name'), Field::number('year')->min(1450)]),
            Field::repeater('awards')->fields([Field::text('name')])->with(['conditions' => []]),
        ])->toArray();

        $this->assertEquals($fromPhp, $fromJson);
    }

    public function test_post_type_and_taxonomy_json_hydrate_with_labels_and_args(): void
    {
        $postType = JsonLoader::toDefinition(JsonLoader::readFile(__DIR__ . '/fixtures/valid/post-types/book.json')['data'])->toArray();
        $taxonomy = JsonLoader::toDefinition(JsonLoader::readFile(__DIR__ . '/fixtures/valid/genre.json')['data'])->toArray();

        $this->assertSame('Books', $postType['labels']['name']);
        $this->assertTrue($postType['has_archive']);
        $this->assertContains('custom-fields', $postType['supports']);
        $this->assertSame(['book'], $taxonomy['object_type']);
        $this->assertTrue($taxonomy['args']['hierarchical']);
    }

    public function test_invalid_json_is_reported_not_thrown(): void
    {
        $file = $this->write('parent/taw-schema/broken.json', '{"version": 1, "kind": ');

        $result = JsonLoader::readFile($file);

        $this->assertNull($result['data']);
        $this->assertStringContainsString('invalid JSON', $result['errors'][0]);
    }

    public function test_wordpress_name_rules_are_enforced_through_the_definitions(): void
    {
        // Structurally valid, but "post" is reserved by WordPress.
        $file = $this->write('parent/taw-schema/post.json', '{"version":1,"kind":"post_type","key":"post"}');

        $result = JsonLoader::readFile($file);

        $this->assertNull($result['data']);
        $this->assertStringContainsString('/key: Invalid post_type key "post"', $result['errors'][0]);
    }

    // ── Discovery ────────────────────────────────────────────────────────

    // Separate process: defines WP_CONTENT_DIR, which can't be undefined.
    #[RunInSeparateProcess]
    public function test_discovery_ranks_child_above_parent_above_wp_content(): void
    {
        define('WP_CONTENT_DIR', $this->root . '/content');

        $paths = JsonLoader::discoveryPaths();

        $this->assertSame([
            $this->root . '/child/taw-schema'   => Source::RANK_CHILD_THEME,
            $this->root . '/parent/taw-schema'  => Source::RANK_PARENT_THEME,
            $this->root . '/content/taw-schema' => Source::RANK_WP_CONTENT,
        ], $paths);
    }

    public function test_without_a_child_theme_the_theme_folder_is_scanned_once_as_the_parent(): void
    {
        Functions\when('get_stylesheet_directory')->justReturn($this->root . '/parent');

        $paths = JsonLoader::discoveryPaths();

        $this->assertSame(Source::RANK_PARENT_THEME, $paths[$this->root . '/parent/taw-schema']);
        $this->assertArrayNotHasKey($this->root . '/child/taw-schema', $paths);
    }

    public function test_the_paths_filter_can_add_a_folder(): void
    {
        Filters\expectApplied('taw_schema_paths')->once()->andReturnUsing(
            fn (array $paths): array => $paths + ['/extra/taw-schema' => Source::RANK_WP_CONTENT]
        );

        $this->assertArrayHasKey('/extra/taw-schema', JsonLoader::discoveryPaths());
    }

    public function test_files_are_found_one_level_deep_in_a_stable_order_and_folders_only_once(): void
    {
        $this->write('parent/taw-schema/b.json', '{}');
        $this->write('parent/taw-schema/a.json', '{}');
        $this->write('parent/taw-schema/post-types/book.json', '{}');
        mkdir($this->root . '/parent/taw-schema/post-types/deeper');
        $this->write('parent/taw-schema/post-types/deeper/ignored.json', '{}');
        $this->write('parent/taw-schema/notes.txt', 'not json');
        symlink($this->root . '/parent/taw-schema', $this->root . '/alias');

        $files = JsonLoader::findFiles([
            $this->root . '/parent/taw-schema' => Source::RANK_PARENT_THEME,
            $this->root . '/alias'             => Source::RANK_WP_CONTENT, // same folder via a symlink
            $this->root . '/missing'           => Source::RANK_WP_CONTENT,
        ]);

        $names = array_map(fn (array $f): string => substr($f['path'], strlen((string) realpath($this->root . '/parent/taw-schema')) + 1), $files);
        $this->assertSame(['a.json', 'b.json', 'post-types/book.json'], $names);
    }

    // ── Loading into the registry ────────────────────────────────────────

    public function test_load_into_adds_definitions_with_their_folder_rank_and_skips_broken_files(): void
    {
        copy(__DIR__ . '/fixtures/valid/genre.json', $this->root . '/parent/taw-schema/genre.json');
        copy(__DIR__ . '/fixtures/valid/post-types/book.json', $this->root . '/child/taw-schema/book.json');
        $this->write('parent/taw-schema/broken.json', '{"version": 1, "kind": "fieldset", "key": "x"}');

        JsonLoader::loadInto(Registry::instance());

        $registry = Registry::instance();
        $this->assertSame(Source::RANK_CHILD_THEME, $registry->sourceOf('post_type:book')?->rank);
        $this->assertSame(Source::RANK_PARENT_THEME, $registry->sourceOf('taxonomy:genre')?->rank);
        $this->assertStringStartsWith('json:', (string) $registry->sourceOf('taxonomy:genre')?->describe());
        // The broken file is reported and skipped; the others still load.
        $this->assertNotSame([], array_filter($this->notices, fn (string $n): bool => str_contains($n, 'broken.json')));
    }

    public function test_php_definitions_beat_json_ones(): void
    {
        copy(__DIR__ . '/fixtures/valid/post-types/book.json', $this->root . '/parent/taw-schema/book.json');
        $registry = Registry::instance();
        $registry->add(Schema::postType('book')->args(['has_archive' => false])->override());

        JsonLoader::loadInto($registry);

        $this->assertFalse($registry->postTypes()[0]->toArray()['has_archive']);
        $this->assertSame('php', $registry->sourceOf('post_type:book')?->describe());
    }

    public function test_production_reuses_the_cached_result_until_a_file_changes(): void
    {
        Functions\when('wp_get_environment_type')->justReturn('production');
        $store = [];
        Functions\when('get_transient')->alias(function (string $key) use (&$store) {
            return $store[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function (string $key, $value) use (&$store): bool {
            $store[$key] = $value;
            return true;
        });
        $file = $this->root . '/parent/taw-schema/book.json';
        copy(__DIR__ . '/fixtures/valid/post-types/book.json', $file);

        JsonLoader::loadInto(Registry::instance());
        $this->assertCount(1, $store, 'first load caches');

        // Same files → same key → served from cache.
        Registry::resetForTests();
        JsonLoader::loadInto(Registry::instance());
        $this->assertCount(1, $store);
        $this->assertCount(1, Registry::instance()->postTypes());

        // Editing the file changes its size/mtime → new key → re-read.
        file_put_contents($file, str_replace('"Books"', '"All Books"', (string) file_get_contents($file)));
        touch($file, time() + 5);
        clearstatcache();
        Registry::resetForTests();
        JsonLoader::loadInto(Registry::instance());
        $this->assertCount(2, $store);
        $this->assertSame('All Books', Registry::instance()->postTypes()[0]->toArray()['labels']['name']);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        file_put_contents($path, $contents);

        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->rrmdir($dir . '/' . $item);
            }
        }
        rmdir($dir);
    }
}
