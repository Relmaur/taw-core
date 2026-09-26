<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use Brain\Monkey\Functions;
use TAW\Core\Editing\Bypass;
use TAW\Core\Editing\ContentLayer;
use TAW\Core\Editing\Policy;
use TAW\Core\Editing\Resolver;
use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

/**
 * The reference cases come from ml-theme--custom-gutenberg's ThemeMode tests
 * (limitToThemeBlocks / lockLayout), generalized to per-post-type rules.
 */
final class ContentLayerTest extends TestCase
{
    private const REGISTERED = ['core/paragraph', 'core/heading', 'core/html', 'core/image', 'acme/hero', 'acme/slider'];

    /** @var array<string, array<int, array<string, mixed>>> content => parse_blocks() result */
    private array $parsed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg();
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('parse_blocks')->alias(fn (string $content): array => $this->parsed[$content] ?? []);
    }

    private function layer(EditingPolicy $definition, array $postTypeRules = []): ContentLayer
    {
        return $this->layerFor(Resolver::resolve($definition, $postTypeRules));
    }

    private function layerFor(Policy $policy): ContentLayer
    {
        return new ContentLayer($policy, new Bypass('taw_unlock_editing', []), static fn (): array => self::REGISTERED);
    }

    private static function context(string $postType, string $status = 'publish'): object
    {
        return (object) ['post' => new \WP_Post(['ID' => 7, 'post_type' => $postType, 'post_status' => $status])];
    }

    // --- allowed_block_types_all --------------------------------------

    public function test_open_policy_leaves_the_allow_list_alone(): void
    {
        $layer = $this->layer(Schema::editing());

        $this->assertTrue($layer->allowedBlockTypes(true, self::context('page')));
        $this->assertSame(['x/y'], $layer->allowedBlockTypes(['x/y'], self::context('page')));
    }

    public function test_globs_expand_against_the_registry(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['allow' => ['core/heading', 'acme/*']]));

        $this->assertSame(['core/heading', 'acme/hero', 'acme/slider'], $layer->allowedBlockTypes(true, self::context('page')));
    }

    public function test_a_narrower_list_from_another_filter_is_respected(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['allow' => ['core/*']]));

        $this->assertSame(['core/paragraph'], $layer->allowedBlockTypes(['core/paragraph', 'acme/hero'], self::context('page')));
    }

    public function test_false_stays_false(): void
    {
        $layer = $this->layer(Schema::editing()->preset('locked'));

        $this->assertFalse($layer->allowedBlockTypes(false, self::context('page')));
    }

    public function test_only_the_rules_post_type_is_restricted(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['allow' => ['core/heading']]));

        $this->assertTrue($layer->allowedBlockTypes(true, self::context('post')));
    }

    public function test_custom_html_off_removes_core_html_everywhere_even_without_a_post(): void
    {
        $layer = $this->layer(Schema::editing()->layer('features', ['customHtml' => false]));

        $expected = ['core/paragraph', 'core/heading', 'core/image', 'acme/hero', 'acme/slider'];
        $this->assertSame($expected, $layer->allowedBlockTypes(true, self::context('post')));
        $this->assertSame($expected, $layer->allowedBlockTypes(true, (object) ['name' => 'core/edit-site']));
    }

    public function test_bypass_users_get_every_block(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $layer = $this->layer(Schema::editing()->preset('locked'));

        $this->assertTrue($layer->allowedBlockTypes(true, self::context('page')));
    }

    // --- block_editor_settings_all -------------------------------------

    public function test_template_goes_to_new_posts_only_by_default(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['template' => [['acme/hero']], 'lock' => 'all']));

        $new = $layer->editorSettings([], self::context('page', 'auto-draft'));
        $this->assertSame([['acme/hero']], $new['template']);
        $this->assertSame('all', $new['templateLock']);

        $existing = $layer->editorSettings([], self::context('page'));
        $this->assertArrayNotHasKey('template', $existing, 'Existing posts must not be compared against the template.');
        $this->assertSame('all', $existing['templateLock']);
    }

    public function test_template_on_every_post_when_new_posts_only_is_false(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['template' => [['acme/hero']], 'newPostsOnly' => false]));

        $this->assertSame([['acme/hero']], $layer->editorSettings([], self::context('page'))['template']);
    }

    public function test_presets_set_the_lock_on_pages(): void
    {
        // contentOnly is enforced as "all" + the content-only editor script (WP 7.1 ignores a root contentOnly lock).
        $this->assertSame('all', $this->layer(Schema::editing()->preset('structured'))->editorSettings([], self::context('page'))['templateLock']);
        $this->assertSame('all', $this->layer(Schema::editing()->preset('locked'))->editorSettings([], self::context('page'))['templateLock']);
        $this->assertSame([], $this->layer(Schema::editing()->preset('guided'))->editorSettings([], self::context('page')));
    }

    public function test_post_type_rules_apply_to_their_post_type(): void
    {
        $layer = $this->layer(Schema::editing(), ['event' => ['lock' => 'insert']]);

        $this->assertSame(['templateLock' => 'insert'], $layer->editorSettings([], self::context('event')));
        $this->assertSame([], $layer->editorSettings([], self::context('page')));
    }

    public function test_settings_outside_the_post_editor_are_untouched(): void
    {
        $layer = $this->layer(Schema::editing()->preset('locked'));

        $this->assertSame(['x' => 1], $layer->editorSettings(['x' => 1], (object) ['name' => 'core/edit-site']));
    }

    public function test_bypass_users_get_no_lock_or_template(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $layer = $this->layer(Schema::editing()->content('page', ['template' => [['acme/hero']], 'lock' => 'all']));

        $this->assertSame([], $layer->editorSettings([], self::context('page', 'auto-draft')));
    }

    // --- rest_pre_insert_{post_type} -----------------------------------

    public function test_save_adding_a_disallowed_block_is_refused(): void
    {
        $this->parsed['NEW'] = [
            ['blockName' => 'core/heading', 'innerHTML' => '<h2>a</h2>', 'innerBlocks' => []],
            ['blockName' => 'acme/slider', 'innerHTML' => '', 'innerBlocks' => []],
        ];
        Functions\when('get_post_field')->justReturn('');

        $layer = $this->layer(Schema::editing()->content('page', ['allow' => ['core/*']]));
        $result = $layer->checkSave((object) ['post_content' => 'NEW'], 'page');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('taw_editing_block_not_allowed', $result->get_error_code());
        $this->assertStringContainsString('acme/slider', $result->get_error_message());
        $this->assertSame(['status' => 400, 'blocks' => ['acme/slider']], $result->error_data['taw_editing_block_not_allowed']);
    }

    public function test_blocks_already_in_the_saved_post_still_save(): void
    {
        $this->parsed['OLD'] = [['blockName' => 'acme/slider', 'innerHTML' => '', 'innerBlocks' => []]];
        $this->parsed['EDITED'] = [
            ['blockName' => 'acme/slider', 'innerHTML' => '', 'innerBlocks' => []],
            ['blockName' => 'core/paragraph', 'innerHTML' => '<p>new</p>', 'innerBlocks' => []],
        ];
        Functions\expect('get_post_field')->once()->with('post_content', 42)->andReturn('OLD');

        $layer = $this->layer(Schema::editing()->content('page', ['allow' => ['core/*']]));
        $prepared = (object) ['ID' => 42, 'post_content' => 'EDITED'];

        $this->assertSame($prepared, $layer->checkSave($prepared, 'page'));
    }

    public function test_raw_html_can_not_sneak_in_as_freeform(): void
    {
        $this->parsed['RAW'] = [['blockName' => null, 'innerHTML' => '<iframe src="x"></iframe>', 'innerBlocks' => []]];
        Functions\when('get_post_field')->justReturn('');

        $layer = $this->layer(Schema::editing()->preset('guided'));

        $this->assertInstanceOf(\WP_Error::class, $layer->checkSave((object) ['post_content' => 'RAW'], 'page'));
    }

    public function test_custom_html_off_is_enforced_on_save_for_every_post_type(): void
    {
        $this->parsed['HTML'] = [['blockName' => 'core/html', 'innerHTML' => '<b>x</b>', 'innerBlocks' => []]];
        Functions\when('get_post_field')->justReturn('');

        $layer = $this->layer(Schema::editing()->layer('features', ['customHtml' => false]));

        $this->assertInstanceOf(\WP_Error::class, $layer->checkSave((object) ['post_content' => 'HTML'], 'post'));
    }

    public function test_saves_without_content_or_under_an_open_rule_are_untouched(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['allow' => ['core/*']]));

        $titleOnly = (object) ['ID' => 3, 'post_title' => 'x'];
        $this->assertSame($titleOnly, $layer->checkSave($titleOnly, 'page'));

        $error = new \WP_Error('earlier', 'x');
        $this->assertSame($error, $layer->checkSave($error, 'page'));

        $open = (object) ['post_content' => 'ANY'];
        $this->assertSame($open, $layer->checkSave($open, 'post'), 'post has an open rule');
    }

    public function test_bypass_users_can_save_anything(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $layer = $this->layer(Schema::editing()->preset('locked'));
        $prepared = (object) ['post_content' => 'NEW'];

        $this->assertSame($prepared, $layer->checkSave($prepared, 'page'));
    }

    public function test_save_checks_hook_every_rest_post_type(): void
    {
        Functions\expect('get_post_types')->once()->with(['show_in_rest' => true])->andReturn(['page' => 'page', 'book' => 'book']);

        $this->layer(Schema::editing())->registerSaveChecks();

        $this->assertNotFalse(has_filter('rest_pre_insert_page'));
        $this->assertNotFalse(has_filter('rest_pre_insert_book'));
    }

    public function test_insert_lock_is_passed_through_unchanged(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['lock' => 'insert']));

        $this->assertSame('insert', $layer->editorSettings([], self::context('page'))['templateLock']);
    }

    // --- content-only editor script ------------------------------------

    private static function screen(string $base, string $postType): void
    {
        Functions\when('get_current_screen')->justReturn((object) ['base' => $base, 'post_type' => $postType]);
    }

    public function test_content_only_script_is_enqueued_for_locked_users_on_content_only_post_types(): void
    {
        self::screen('post', 'page');
        Functions\when('get_template_directory')->justReturn(\TAW\Helpers\Framework::path());
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/t');
        Functions\when('get_stylesheet_directory')->justReturn(\TAW\Helpers\Framework::path());
        Functions\expect('wp_enqueue_script')->once()->with(
            ContentLayer::CONTENT_ONLY_HANDLE,
            'https://example.test/wp-content/themes/t/assets/editing-content-only.js',
            ['wp-data', 'wp-block-editor'],
            \Mockery::type('string'),
            true
        );

        $this->layer(Schema::editing()->preset('structured'))->enqueueContentOnly();
        $this->addToAssertionCount(1);
    }

    public function test_content_only_script_is_not_enqueued_elsewhere(): void
    {
        Functions\expect('wp_enqueue_script')->never();

        self::screen('post', 'post');   // post is open under the structured preset
        $this->layer(Schema::editing()->preset('structured'))->enqueueContentOnly();

        self::screen('post', 'page');   // locked (all), not contentOnly
        $this->layer(Schema::editing()->preset('locked'))->enqueueContentOnly();

        self::screen('site-editor', 'page');
        $this->layer(Schema::editing()->preset('structured'))->enqueueContentOnly();

        self::screen('post', 'page');   // bypass
        Functions\when('current_user_can')->justReturn(true);
        $this->layer(Schema::editing()->preset('structured'))->enqueueContentOnly();

        $this->addToAssertionCount(1);
    }

    // --- allowBound (ADR-0010 decision 10) -------------------------------

    /** @return array<string, mixed> A parse_blocks() entry, bound to a TAW field when $field is given. */
    private static function block(string $name, ?string $field = null): array
    {
        $attrs = $field === null ? [] : ['metadata' => ['bindings' => ['content' => ['source' => 'taw/field', 'args' => ['field' => $field]]]]];

        return ['blockName' => $name, 'attrs' => $attrs, 'innerHTML' => '<p>x</p>', 'innerBlocks' => []];
    }

    private function boundLayer(): ContentLayer
    {
        return $this->layer(Schema::editing()->content('page', ['allow' => ['core/heading'], 'allowBound' => ['core/paragraph', 'core/image', 'core/html']]));
    }

    public function test_allow_bound_blocks_stay_insertable_and_reach_the_editor(): void
    {
        $layer = $this->boundLayer();

        $this->assertSame(['core/paragraph', 'core/heading', 'core/html', 'core/image'], $layer->allowedBlockTypes(true, self::context('page')));
        $this->assertSame(['tawAllowBound' => ['core/paragraph', 'core/html', 'core/image']], $layer->editorSettings([], self::context('page')));
        $this->assertSame([], $layer->editorSettings([], self::context('post')), 'other post types: nothing');
    }

    public function test_allow_bound_blocks_the_allow_list_already_allows_are_not_bound_only(): void
    {
        $layer = $this->layer(Schema::editing()->content('page', ['allow' => ['core/*'], 'allowBound' => ['core/paragraph']]));

        $this->assertSame([], $layer->editorSettings([], self::context('page')));
    }

    public function test_a_save_may_add_allow_bound_blocks_only_bound(): void
    {
        Functions\when('get_post_field')->justReturn('');
        $this->parsed['BOUND'] = [self::block('core/heading'), self::block('core/paragraph', 'headline')];
        $this->parsed['PLAIN'] = [self::block('core/paragraph', 'headline'), self::block('core/paragraph'), self::block('core/paragraph', '')];
        $layer = $this->boundLayer();

        $bound = (object) ['post_content' => 'BOUND'];
        $this->assertSame($bound, $layer->checkSave($bound, 'page'));

        $result = $layer->checkSave((object) ['post_content' => 'PLAIN'], 'page');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('taw_editing_block_not_bound', $result->get_error_code());
        $this->assertSame(['status' => 400, 'blocks' => ['core/paragraph']], $result->error_data['taw_editing_block_not_bound']);
    }

    public function test_existing_unbound_blocks_still_save_but_no_new_ones(): void
    {
        $this->parsed['OLD'] = [self::block('core/paragraph'), self::block('core/paragraph', 'headline')];
        $this->parsed['SAME'] = [self::block('core/paragraph', 'headline'), self::block('core/paragraph'), self::block('core/paragraph', 'intro')];
        $this->parsed['MORE'] = [self::block('core/paragraph'), self::block('core/paragraph'), self::block('core/paragraph', 'headline')];
        Functions\when('get_post_field')->justReturn('OLD');
        $layer = $this->boundLayer();

        $same = (object) ['ID' => 42, 'post_content' => 'SAME'];
        $this->assertSame($same, $layer->checkSave($same, 'page'), 'legacy unbound paragraph + a new bound one');
        $this->assertInstanceOf(\WP_Error::class, $layer->checkSave((object) ['ID' => 42, 'post_content' => 'MORE'], 'page'), 'a second unbound paragraph');
    }

    public function test_blocks_outside_both_lists_are_still_refused_first(): void
    {
        Functions\when('get_post_field')->justReturn('');
        $this->parsed['BOTH'] = [self::block('acme/slider'), self::block('core/paragraph')];

        $result = $this->boundLayer()->checkSave((object) ['post_content' => 'BOTH'], 'page');

        $this->assertSame('taw_editing_block_not_allowed', $result->get_error_code());
        $this->assertSame(['status' => 400, 'blocks' => ['acme/slider']], $result->error_data['taw_editing_block_not_allowed']);
    }

    public function test_custom_html_off_wins_over_allow_bound(): void
    {
        Functions\when('get_post_field')->justReturn('');
        $this->parsed['HTML'] = [self::block('core/html', 'headline')];
        $layer = $this->layer(Schema::editing()->layer('features', ['customHtml' => false])->content('page', ['allow' => ['core/heading'], 'allowBound' => ['core/*']]));

        $this->assertNotContains('core/html', $layer->allowedBlockTypes(true, self::context('page')));
        $this->assertSame('taw_editing_block_not_allowed', $layer->checkSave((object) ['post_content' => 'HTML'], 'page')->get_error_code());
    }

    public function test_bypass_users_ignore_allow_bound(): void
    {
        Functions\when('get_post_field')->justReturn('');
        Functions\when('current_user_can')->justReturn(true);
        $this->parsed['PLAIN'] = [self::block('core/paragraph')];
        $prepared = (object) ['post_content' => 'PLAIN'];

        $this->assertSame($prepared, $this->boundLayer()->checkSave($prepared, 'page'));
        $this->assertSame([], $this->boundLayer()->editorSettings([], self::context('page')));
    }

    public function test_register_hooks_the_editor_and_rest(): void
    {
        $layer = $this->layer(Schema::editing());
        $layer->register();

        $this->assertSame(20, has_filter('allowed_block_types_all', [$layer, 'allowedBlockTypes']));
        $this->assertSame(20, has_filter('block_editor_settings_all', [$layer, 'editorSettings']));
        $this->assertSame(10, has_action('rest_api_init', [$layer, 'registerSaveChecks']));
        $this->assertSame(10, has_action('enqueue_block_editor_assets', [$layer, 'enqueueContentOnly']));
    }
}
