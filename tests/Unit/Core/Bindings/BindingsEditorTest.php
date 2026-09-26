<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use Brain\Monkey\Functions;
use TAW\Core\Bindings\Bindings;
use TAW\Core\Bindings\EditorFields;
use TAW\Core\Bindings\PreviewEndpoint;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * The editor side of `taw/field` (ADR-0010 decision 8, Phase 4 Step 2): the
 * fields the Attributes panel offers, and the preview endpoint.
 */
final class BindingsEditorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var list<int> Posts the current user may edit. */
    private array $editable = [5];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetRegistries();
        Bindings::reset();
        $this->editable = [5];

        $posts = [5 => new \WP_Post(['ID' => 5, 'post_type' => 'book', 'post_author' => 3]), 8 => new \WP_Post(['ID' => 8, 'post_type' => 'book']), 12 => new \WP_Post(['ID' => 12, 'post_type' => 'book'])];
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => $type === 'book');
        Functions\when('taxonomy_exists')->alias(static fn (string $taxonomy): bool => $taxonomy === 'genre');
        Functions\when('get_post')->alias(static fn ($id) => $posts[$id instanceof \WP_Post ? $id->ID : (int) $id] ?? null);
        Functions\when('is_post_publicly_viewable')->justReturn(true);
        Functions\when('post_password_required')->justReturn(false);
        Functions\when('current_user_can')->alias(fn (string $cap, int $id = 0): bool => $cap === 'edit_posts' || in_array($id, $this->editable, true) || $cap === 'read_post');
        Functions\when('get_queried_object')->justReturn(null);
        Functions\when('get_posts')->alias(static fn (array $q) => $q['post_type'] === 'book' ? [12] : []);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["{$id}|{$key}"] ?? '');
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false) => $name === '_taw_company_phone' ? '+52 999' : $default);
        Functions\when('esc_html')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_json_encode')->alias(static fn ($data, int $flags = 0) => json_encode($data, $flags));
        Functions\when('__')->returnArg(1);
        Functions\when('_doing_it_wrong')->justReturn(null);

        new Metabox(['id' => 'book_box', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'text', 'label' => 'Subtitle'],
            ['id' => 'cover', 'type' => 'image', 'label' => 'Cover'],
            ['id' => 'awards', 'type' => 'repeater', 'fields' => [['id' => 'name', 'type' => 'text']]],
            ['id' => 'shelf', 'type' => 'post_select', 'multiple' => true],
            ['id' => 'address', 'type' => 'group', 'label' => 'Address', 'fields' => [['id' => 'city', 'type' => 'text', 'label' => 'City']]],
            ['id' => 'secret', 'type' => 'text', 'bindings' => false],
        ]]);
        new Metabox(['id' => 'genre_box', 'title' => 'Genre', 'screens' => ['term:genre'], 'fields' => [['id' => 'tagline', 'type' => 'text', 'label' => 'Tagline']]]);
        new Metabox(['id' => 'author_box', 'title' => 'Author', 'screens' => ['user'], 'fields' => [
            ['id' => 'phone', 'type' => 'text'],
            ['id' => 'bio', 'type' => 'text', 'label' => 'Bio', 'bindings' => true],
        ]]);
        new OptionsPage(['id' => 'site', 'title' => 'Site', 'fields' => [
            ['id' => 'company_phone', 'type' => 'text', 'label' => 'Phone'],
            ['id' => 'social', 'type' => 'group', 'label' => 'Social', 'fields' => [['id' => 'x', 'type' => 'url', 'label' => 'X']]],
        ]]);

        $this->meta = ['5|_taw_subtitle' => 'Dune', '8|_taw_subtitle' => 'Not yours', '12|_taw_subtitle' => 'Latest book'];
    }

    protected function tearDown(): void
    {
        $this->resetRegistries();
        Bindings::reset();
        parent::tearDown();
    }

    private function resetRegistries(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        foreach (['fieldRegistry', 'groupRegistry'] as $property) {
            (new \ReflectionProperty(OptionsPage::class, $property))->setValue(null, []);
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private static function labels(array $entries): array
    {
        return array_map(static fn (array $e): string => $e['label'] . ' [' . $e['type'] . ']', $entries);
    }

    public function test_the_attributes_panel_offers_bindable_fields_only(): void
    {
        $fields = EditorFields::all();

        $this->assertSame(['Subtitle [string]', 'Cover [string]', 'Cover (image ID) [number]', 'Address › City [string]'], self::labels($fields['post']['book']));
        $this->assertSame(['field' => 'address', 'from' => 'post', 'sub' => 'city'], $fields['post']['book'][3]['args']);
        $this->assertSame(['Phone [string]', 'Social › X [string]'], self::labels($fields['option']));
        $this->assertSame(['field' => 'social', 'from' => 'option', 'sub' => 'x'], $fields['option'][1]['args']);
        $this->assertSame(['Tagline [string]'], self::labels($fields['term']));
        $this->assertSame(['Bio [string]'], self::labels($fields['user']), 'user fields only with bindings: true');
    }

    public function test_previews_use_the_front_end_resolver_for_editable_posts(): void
    {
        $item = static fn (int $postId, array $args = ['field' => 'subtitle'], string $postType = 'book'): array => ['key' => "k{$postId}", 'args' => $args, 'block' => 'core/heading', 'attribute' => 'content', 'postId' => $postId, 'postType' => $postType];

        $this->assertSame('Dune', PreviewEndpoint::resolve($item(5)));
        $this->assertNull(PreviewEndpoint::resolve($item(8)), 'a post the user can\'t edit');
        $this->assertSame('Latest book', PreviewEndpoint::resolve($item(0)), 'a template: the latest post of its type');
        $this->assertSame('+52 999', PreviewEndpoint::resolve($item(0, ['field' => 'company_phone', 'from' => 'option'], '')));
        $this->assertNull(PreviewEndpoint::resolve(['key' => 'x', 'args' => ['field' => 'subtitle'], 'block' => '', 'attribute' => 'content']));

        $response = PreviewEndpoint::handle(new \WP_REST_Request('POST', '/taw/v1/bindings/preview', ['items' => [$item(5), $item(8), 'junk']]));
        $this->assertEquals((object) ['k5' => 'Dune', 'k8' => null], $response->get_data()['values']);
    }

    public function test_the_route_and_editor_script_are_hooked_with_the_source(): void
    {
        Bindings::register();

        $this->assertSame(10, has_action('init', [Bindings::class, 'registerSource']));
        $this->assertSame(10, has_action('rest_api_init', [PreviewEndpoint::class, 'registerRoute']));
        $this->assertSame(10, has_action('enqueue_block_editor_assets', [Bindings::class, 'enqueueEditor']));
    }
}
