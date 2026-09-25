<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\DataPanel;

use Brain\Monkey\Functions;
use TAW\Core\DataPanel\DataPanel;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\Registry;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

final class DataPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DataPanel::resetForTests();
        Metabox::forgetInstances();
        Registry::resetForTests();
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['page', 'post', 'book'], true));
        Functions\when('use_block_editor_for_post')->justReturn(true);
        Functions\when('is_wp_error')->alias(static fn ($thing): bool => $thing instanceof \WP_Error);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        DataPanel::resetForTests();
        Metabox::forgetInstances();
        Registry::resetForTests();
        parent::tearDown();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $extra
     */
    private function box(string $id, array $fields, array $extra = []): Metabox
    {
        return new Metabox(array_replace(['id' => $id, 'title' => ucfirst($id), 'screens' => ['book'], 'fields' => $fields], $extra));
    }

    public function test_register_adds_one_late_init_check_once(): void
    {
        DataPanel::register();
        DataPanel::register();

        $this->assertSame(DataPanel::CHECK_PRIORITY, has_action('init', [DataPanel::class, 'check']));
    }

    public function test_without_panel_fieldsets_the_check_removes_itself_and_hooks_nothing(): void
    {
        $this->box('book_details', [['id' => 'book_author', 'type' => 'text']]);
        DataPanel::register();
        DataPanel::check();

        $this->assertFalse(has_action('init', [DataPanel::class, 'check']));
        $this->assertFalse(has_filter('taw_metabox_ui', [DataPanel::class, 'placement']));
        $this->assertFalse(has_filter('block_editor_settings_all', [DataPanel::class, 'editorSettings']));
        $this->assertFalse(has_filter('rest_pre_insert_book', [DataPanel::class, 'validateRest']));
    }

    public function test_a_panel_fieldset_hooks_placement_settings_and_rest_checks_for_its_post_types(): void
    {
        $this->box('book_details', [['id' => 'book_author', 'type' => 'text']], ['ui' => 'panel', 'screens' => ['book', 'about-us']]);
        DataPanel::check();

        $this->assertSame(10, has_filter('taw_metabox_ui', [DataPanel::class, 'placement']));
        $this->assertSame(10, has_filter('block_editor_settings_all', [DataPanel::class, 'editorSettings']));
        $this->assertSame(10, has_filter('rest_pre_insert_book', [DataPanel::class, 'validateRest']));
        $this->assertSame(10, has_action('rest_after_insert_book', [DataPanel::class, 'clearHidden']));
        $this->assertSame(10, has_filter('rest_pre_insert_page', [DataPanel::class, 'validateRest']), 'slug screens are pages');
        $this->assertFalse(has_filter('rest_pre_insert_post', [DataPanel::class, 'validateRest']));
    }

    public function test_the_site_default_moves_supported_fieldsets_and_a_fieldset_can_opt_out(): void
    {
        Registry::instance()->add(Schema::settings()->fieldsetUi('panel'));
        $details = $this->box('book_details', [['id' => 'book_author', 'type' => 'text']]);
        $this->box('book_legacy', [['id' => 'book_legacy', 'type' => 'text']], ['ui' => 'metabox']);
        $this->box('book_map', [['id' => 'book_map', 'type' => 'map']]);

        $this->assertSame([$details], DataPanel::panelMetaboxes());
    }

    public function test_placement_only_in_the_block_editor(): void
    {
        $panel = $this->box('book_details', [['id' => 'book_author', 'type' => 'text']], ['ui' => 'panel']);
        $other = $this->box('book_other', [['id' => 'book_other', 'type' => 'text']]);
        $post  = new \WP_Post(['ID' => 7, 'post_type' => 'book']);

        $this->assertSame('panel', DataPanel::placement('metabox', $panel, $post));
        $this->assertSame('metabox', DataPanel::placement('metabox', $other, $post));

        Functions\when('use_block_editor_for_post')->justReturn(false);
        $this->assertSame('metabox', DataPanel::placement('metabox', $panel, $post), 'the classic editor keeps its metabox');
    }

    public function test_the_metabox_skips_add_meta_box_when_placed_in_the_panel(): void
    {
        $box = $this->box('book_details', [['id' => 'book_author', 'type' => 'text']], ['ui' => 'panel']);
        Functions\when('get_post')->justReturn(new \WP_Post(['ID' => 7, 'post_type' => 'book']));
        Functions\expect('add_meta_box')->never();
        add_filter('taw_metabox_ui', [DataPanel::class, 'placement'], 10, 3);
        \Brain\Monkey\Filters\expectApplied('taw_metabox_ui')->once()->andReturn('panel');

        $box->register();
    }

    public function test_the_metabox_is_added_as_before_otherwise(): void
    {
        $box = $this->box('book_details', [['id' => 'book_author', 'type' => 'text']]);
        Functions\when('get_post')->justReturn(new \WP_Post(['ID' => 7, 'post_type' => 'book']));
        Functions\when('esc_html')->returnArg(); // the metabox title
        Functions\expect('add_meta_box')->once();

        $box->register();
    }

    public function test_editor_settings_carry_the_descriptor_for_the_edited_post(): void
    {
        $this->box('book_details', [['id' => 'book_author', 'type' => 'text']], ['ui' => 'panel']);
        $this->box('about_hero', [['id' => 'hero_title', 'type' => 'text']], ['ui' => 'panel', 'screens' => ['page-about.php']]);
        $this->box('page_only', [['id' => 'page_only', 'type' => 'text']], ['ui' => 'panel', 'screens' => ['page']]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_option')->justReturn(0);

        $settings = DataPanel::editorSettings(['x' => 1], (object) ['post' => new \WP_Post(['ID' => 7, 'post_type' => 'book', 'post_name' => 'dune'])]);

        $this->assertSame(1, $settings['x']);
        $panel = $settings[DataPanel::SETTINGS_KEY];
        $this->assertSame(1, $panel['version']);
        $this->assertSame('book', $panel['postType']);
        $this->assertSame(['book_details', 'about_hero'], array_column($panel['fieldsets'], 'id'));
        $this->assertSame([true, false], array_column($panel['fieldsets'], 'active'), 'template-scoped fieldsets come along, inactive');
        $this->assertSame([true, false], array_column($panel['fieldsets'], 'always'));
        $this->assertSame(['page-about.php'], $panel['fieldsets'][1]['templates']);
    }

    public function test_editor_settings_are_untouched_without_a_post_or_panel_fieldsets(): void
    {
        $this->assertSame(['x' => 1], DataPanel::editorSettings(['x' => 1], (object) ['name' => 'core/edit-site']));
        $this->assertSame(['x' => 1], DataPanel::editorSettings(['x' => 1], (object) ['post' => new \WP_Post(['ID' => 7, 'post_type' => 'book'])]));
    }

    public function test_rest_saves_breaking_the_rules_get_a_400_naming_the_fields(): void
    {
        $this->box('book_details', [
            ['id' => 'book_author', 'type' => 'text', 'label' => 'Author', 'required' => true],
            ['id' => 'book_code', 'type' => 'text', 'label' => 'Code', 'readonly' => true],
        ], ['ui' => 'panel']);
        Functions\when('get_post')->justReturn(new \WP_Post(['ID' => 7, 'post_type' => 'book']));
        Functions\when('get_post_meta')->justReturn('');

        $request = new \WP_REST_Request('POST', '/wp/v2/book/7', ['id' => 7, 'meta' => ['_taw_book_code' => 'X']]);
        $result  = DataPanel::validateRest((object) ['ID' => 7], $request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(DataPanel::ERROR_CODE, $result->get_error_code());
        $this->assertSame(400, $result->error_data[DataPanel::ERROR_CODE]['status']);
        $this->assertSame(['_taw_book_author', '_taw_book_code'], array_column($result->error_data[DataPanel::ERROR_CODE]['fields'], 'field'));
    }

    public function test_valid_saves_and_autosaves_pass_through(): void
    {
        $this->box('book_details', [['id' => 'book_author', 'type' => 'text', 'required' => true]], ['ui' => 'panel']);
        Functions\when('get_post')->justReturn(new \WP_Post(['ID' => 7, 'post_type' => 'book']));
        Functions\when('get_post_meta')->justReturn('');
        $prepared = (object) ['ID' => 7];

        $this->assertSame($prepared, DataPanel::validateRest($prepared, new \WP_REST_Request('POST', '/wp/v2/book/7', ['id' => 7, 'meta' => ['_taw_book_author' => 'Ada']])));
        $this->assertSame($prepared, DataPanel::validateRest($prepared, new \WP_REST_Request('POST', '/wp/v2/book/7/autosaves', ['id' => 7])));
    }

    public function test_new_posts_are_checked_by_post_type(): void
    {
        $this->box('book_details', [['id' => 'book_author', 'type' => 'text', 'required' => true]], ['ui' => 'panel']);

        $result = DataPanel::validateRest((object) ['post_type' => 'book'], new \WP_REST_Request('POST', '/wp/v2/book', ['meta' => []]));

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_hidden_values_are_cleared_after_a_save(): void
    {
        $this->box('book_details', [
            ['id' => 'has_sale', 'type' => 'checkbox'],
            ['id' => 'sale_price', 'type' => 'number', 'conditions' => [['id' => 'has_sale', 'value' => '1']]],
        ], ['ui' => 'panel']);
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key): string => $key === '_taw_sale_price' ? '10' : '');
        Functions\expect('delete_post_meta')->once()->with(7, '_taw_sale_price');

        DataPanel::clearHidden(new \WP_Post(['ID' => 7, 'post_type' => 'book']), new \WP_REST_Request('POST', '/wp/v2/book/7'), false);
    }

    public function test_the_panel_script_loads_in_the_post_editor_for_panel_posts_only(): void
    {
        $root = sys_get_temp_dir() . '/taw-data-panel-build-' . getmypid();
        @mkdir($root . '/assets/data-panel/.vite', 0777, true);
        file_put_contents($root . '/assets/data-panel/.vite/manifest.json', (string) json_encode([
            DataPanel::SCRIPT_SOURCE => ['file' => 'data-panel-1.js', 'css' => ['data-panel-1.css']],
        ]));
        DataPanel::useVite(new \TAW\Core\Assets\Vite($root, 'https://site.test/vendor/taw/core', 'assets/data-panel'));
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        $scripts = [];
        Functions\when('wp_register_script')->alias(static function (string $handle, string $src, array $deps) use (&$scripts): bool {
            $scripts[$handle] = [$src, $deps];

            return true;
        });
        Functions\when('wp_register_style')->justReturn(true);
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script')->justReturn(null);
        $screen = new \stdClass();
        Functions\when('get_current_screen')->alias(static function () use (&$screen): object {
            return $screen;
        });

        try {
            $this->box('book_details', [['id' => 'book_author', 'type' => 'text']], ['ui' => 'panel']);

            $screen->base = 'edit';
            Functions\when('get_post')->justReturn(new \WP_Post(['ID' => 7, 'post_type' => 'book']));
            DataPanel::enqueueAssets();
            $this->assertSame([], $scripts, 'not on the posts list');

            $screen->base = 'post';
            Functions\when('get_post')->justReturn(new \WP_Post(['ID' => 8, 'post_type' => 'page']));
            DataPanel::enqueueAssets();
            $this->assertSame([], $scripts, 'not for a post without panel fieldsets');

            Functions\when('get_post')->justReturn(new \WP_Post(['ID' => 7, 'post_type' => 'book']));
            DataPanel::enqueueAssets();
            $this->assertSame(
                ['https://site.test/vendor/taw/core/assets/data-panel/data-panel-1.js', DataPanel::SCRIPT_DEPS],
                $scripts[DataPanel::SCRIPT_HANDLE]
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function test_the_committed_build_has_the_entry_php_loads(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/assets/data-panel/.vite/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey(DataPanel::SCRIPT_SOURCE, $manifest, 'run npm run build in resources/data-panel');
        $this->assertFileExists(dirname(__DIR__, 4) . '/assets/data-panel/' . $manifest[DataPanel::SCRIPT_SOURCE]['file']);
    }
}
