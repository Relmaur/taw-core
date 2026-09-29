<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Loop;

use Brain\Monkey\Functions;
use TAW\Core\Bindings\BindingContext;
use TAW\Core\Bindings\PreviewEndpoint;
use TAW\Core\Loop\EditorData;
use TAW\Core\Bindings\Bindings;
use TAW\Core\Bindings\InlineTags;
use TAW\Core\Bindings\Target;
use TAW\Core\Loop\Item;
use TAW\Core\Loop\Loop;
use TAW\Core\Loop\RowValues;
use TAW\Core\Loop\Sources;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * The TAW Loop (ADR-0014, data layer Phase 5 Step 1): sources, item values,
 * ordering, filtering, limits and paging. Rendering whole blocks needs
 * WP_Block and is checked on a real site.
 */
final class LoopTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<int, list<\WP_Term>> */
    private array $postTerms = [];

    /** @var array<string, mixed>|null The last WP_Query args. */
    public static ?array $queryArgs = null;

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        Bindings::reset();
        Loop::reset();
        self::$queryArgs = null;
        $_GET = [];

        $posts = [
            5 => new \WP_Post(['ID' => 5, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Dune']),
            6 => new \WP_Post(['ID' => 6, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Private']),
            7 => new \WP_Post(['ID' => 7, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Messiah']),
            8 => new \WP_Post(['ID' => 8, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Children']),
        ];
        $fiction = new \WP_Term(['term_id' => 24, 'name' => 'Fiction', 'slug' => 'fiction', 'taxonomy' => 'genre', 'count' => 3, 'description' => '<b>Made up</b>']);
        $classic = new \WP_Term(['term_id' => 25, 'name' => 'Classic', 'slug' => 'classic', 'taxonomy' => 'genre', 'count' => 1, 'description' => '']);
        $this->postTerms = [5 => [$fiction, $classic], 7 => [$fiction]];

        Functions\when('get_post')->alias(static fn ($id) => $posts[$id instanceof \WP_Post ? $id->ID : (int) $id] ?? null);
        Functions\when('is_post_publicly_viewable')->alias(static fn (\WP_Post $post): bool => $post->ID !== 6);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('post_password_required')->justReturn(false);
        Functions\when('get_queried_object')->justReturn(null);
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['book', 'post'], true));
        Functions\when('is_post_type_viewable')->alias(static fn (string $type): bool => $type === 'book');
        Functions\when('taxonomy_exists')->alias(static fn (string $tax): bool => $tax === 'genre');
        Functions\when('is_taxonomy_viewable')->justReturn(true);
        Functions\when('get_the_terms')->alias(fn (int $id, string $tax) => $this->postTerms[$id] ?? false);
        Functions\when('get_terms')->alias(static fn (array $args): array => [$classic, $fiction]);
        Functions\when('get_term_link')->alias(static fn (\WP_Term $term): string => "https://site.test/genre/{$term->slug}/");
        Functions\when('wp_list_pluck')->alias(static fn (array $list, string $field): array => array_map(static fn ($o) => $o->$field, $list));
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false) => match ($name) {
            'date_format'  => 'Y-m-d',
            'sticky_posts' => [7],
            default        => $default,
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["{$id}|{$key}"] ?? '');
        Functions\when('get_the_title')->alias(static fn ($post): string => $post instanceof \WP_Post ? $post->post_title : '');
        Functions\when('wp_strip_all_tags')->alias(static fn (string $text): string => trim(strip_tags($text)));
        Functions\when('esc_html')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_attr')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('add_query_arg')->alias(static fn (string $key, $value): string => $value === false ? '/books/' : "/books/?{$key}={$value}");
        Functions\when('wp_timezone')->alias(static fn (): \DateTimeZone => new \DateTimeZone('UTC'));
        Functions\when('current_datetime')->alias(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-29 12:00:00'));
        Functions\when('wp_date')->alias(static fn (string $format, ?int $ts = null): string => gmdate($format, $ts ?? 1790424000));
        Functions\when('wp_json_encode')->alias(static fn ($data, int $flags = 0) => json_encode($data, $flags));
        Functions\when('wp_attachment_is_image')->justReturn(true);
        Functions\when('wp_get_attachment_image_url')->alias(static fn (int $id, string $size = 'full'): string => "https://site.test/img-{$id}-{$size}.jpg");
        Functions\when('wp_get_attachment_image_src')->alias(static fn (int $id, string $size = 'full'): array => ["https://site.test/img-{$id}-{$size}.jpg", 640, 480, false]);
        Functions\when('__')->returnArg(1);
        Functions\when('_doing_it_wrong')->justReturn(null);

        new Metabox(['id' => 'book_box', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'book_year', 'type' => 'number', 'label' => 'Year'],
            ['id' => 'book_awards', 'type' => 'repeater', 'label' => 'Awards', 'fields' => [
                ['id' => 'name', 'type' => 'text', 'label' => 'Award'],
                ['id' => 'year', 'type' => 'number', 'label' => 'Year'],
                ['id' => 'won', 'type' => 'checkbox', 'label' => 'Won'],
                ['id' => 'jury', 'type' => 'repeater', 'label' => 'Jury', 'fields' => [['id' => 'person', 'type' => 'text', 'label' => 'Person']]],
            ]],
            ['id' => 'book_secret_rows', 'type' => 'repeater', 'bindings' => false, 'fields' => [['id' => 'x', 'type' => 'text']]],
            ['id' => 'book_related', 'type' => 'post_select', 'label' => 'Related', 'multiple' => true],
            ['id' => 'book_gallery', 'type' => 'files', 'label' => 'Gallery'],
        ]]);

        $this->meta = [
            '5|_taw_book_year'        => '1965',
            '7|_taw_book_year'        => '1969',
            '8|_taw_book_year'        => '1976',
            '5|_taw_book_awards'      => json_encode([
                ['name' => 'Hugo', 'year' => '1966', 'won' => '1', 'jury' => [['person' => 'Ann'], ['person' => 'Bo']]],
                ['name' => 'Nebula', 'year' => '1965', 'won' => ''],
                ['name' => 'Locus', 'year' => '1975', 'won' => '1'],
            ]),
            '5|_taw_book_secret_rows' => json_encode([['x' => 'a']]),
            '5|_taw_book_related'     => json_encode([8, 6, 7]),
            '5|_taw_book_gallery'     => json_encode([11, 12]),
        ];
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        Bindings::reset();
        Loop::reset();
        $_GET = [];
        parent::tearDown();
    }

    /**
     * The items' text for an expression, per item.
     *
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    private function texts(array $attributes, string $expr, int $postId = 5): array
    {
        $context = new BindingContext($postId);
        $plan = Loop::items($attributes, $context);

        return array_map(static fn (Item $item): string => (string) InlineTags::value(['expr' => $expr], $context->forItem($item)), $plan['items']);
    }

    public function test_repeater_rows_and_loop_positions(): void
    {
        $this->assertSame(
            ['1/3 Hugo 1966 1', '2/3 Nebula 1965', '3/3 Locus 1975 1'],
            array_map('trim', $this->texts(['source' => ['type' => 'repeater', 'field' => 'book_awards']], '@loop.index/@loop.count @row.name @row.year @loop.first.default(\'\')@loop.last')),
        );
    }

    public function test_row_values_escape_and_checkboxes_read_as_yes(): void
    {
        $item = Loop::items(['source' => ['type' => 'repeater', 'field' => 'book_awards']], new BindingContext(5))['items'][0];
        $context = (new BindingContext(5))->forItem($item);

        $this->assertSame('1', RowValues::text(['row' => 'won'], $context));
        $this->assertSame('Hugo', RowValues::forTarget(['row' => 'name'], $context, Target::for('core/paragraph', 'content')));
        $this->assertNull(RowValues::text(['row' => 'missing'], $context));
        $this->assertNull(RowValues::text(['row' => 'name'], new BindingContext(5)), 'outside a loop');
        $this->assertSame('1', RowValues::text(['loop' => 'even'], new BindingContext(5, new Item(Item::ROW, null, 5, 2, 3))));
        $this->assertNull(RowValues::text(['loop' => 'odd'], new BindingContext(5, new Item(Item::ROW, null, 5, 2, 3))));
    }

    public function test_order_limit_offset(): void
    {
        $source = ['type' => 'repeater', 'field' => 'book_awards'];

        $this->assertSame(['Locus', 'Hugo', 'Nebula'], $this->texts(['source' => $source, 'order' => ['by' => '@row.year', 'dir' => 'desc', 'as' => 'number']], '@row.name'));
        $this->assertSame(['Hugo', 'Locus', 'Nebula'], $this->texts(['source' => $source, 'order' => ['by' => '@row.name']], '@row.name'));
        $this->assertSame(['Locus', 'Nebula', 'Hugo'], $this->texts(['source' => $source, 'order' => ['dir' => 'desc']], '@row.name'), 'the source order, reversed');
        $this->assertSame(['Nebula'], $this->texts(['source' => $source, 'offset' => 1, 'limit' => 1], '@row.name'));
        $this->assertSame(['1/1'], $this->texts(['source' => $source, 'offset' => 2], '@loop.index/@loop.count'), 'positions count after limiting');
    }

    public function test_filter_with_a_condition(): void
    {
        $filter = ['match' => 'all', 'rules' => [['value' => '@row.won', 'op' => 'is_true'], ['value' => '@row.year', 'op' => 'lt', 'to' => 1970]]];

        $this->assertSame(['Hugo'], $this->texts(['source' => ['type' => 'repeater', 'field' => 'book_awards'], 'filter' => $filter], '@row.name'));
        $this->assertSame(['Nebula', 'Locus'], $this->texts(['source' => ['type' => 'repeater', 'field' => 'book_awards'], 'filter' => ['rules' => [['value' => '@loop.first', 'op' => 'is_false']]]], '@row.name'), '@loop in filters');
    }

    public function test_paging(): void
    {
        $attributes = ['loopId' => 4, 'source' => ['type' => 'repeater', 'field' => 'book_awards'], 'perPage' => 2];

        $plan = Loop::items($attributes, new BindingContext(5));
        $this->assertSame(['current' => 1, 'total' => 2, 'param' => 'taw-loop-4'], $plan['pages']);
        $this->assertCount(2, $plan['items']);

        $_GET['taw-loop-4'] = '2';
        $this->assertSame(['3/3 Locus'], $this->texts($attributes, '@loop.index/@loop.count @row.name'));
        $_GET['taw-loop-4'] = '99';
        $this->assertSame(2, Loop::items($attributes, new BindingContext(5))['pages']['current'], 'clamped');
    }

    public function test_nested_repeater_from_the_row(): void
    {
        $outer = Loop::items(['source' => ['type' => 'repeater', 'field' => 'book_awards']], new BindingContext(5))['items'][0];
        $context = (new BindingContext(5))->forItem($outer);
        $inner = Loop::items(['source' => ['type' => 'repeater', 'field' => 'jury', 'from' => 'row']], $context)['items'];

        $this->assertSame(['Ann', 'Bo'], array_map(static fn (Item $item): string => (string) RowValues::text(['row' => 'person'], $context->forItem($item)), $inner));
        $this->assertSame([], Loop::items(['source' => ['type' => 'repeater', 'field' => 'jury', 'from' => 'row']], new BindingContext(5))['items'], 'no outer row');
    }

    public function test_related_posts_skip_unreadable_ones_and_become_the_post(): void
    {
        $this->assertSame(['Children 1976', 'Messiah 1969'], $this->texts(['source' => ['type' => 'related', 'field' => 'book_related']], '@post.title @book_year'));
        $this->assertSame(['Children', 'Messiah'], $this->texts(['source' => ['type' => 'related', 'field' => 'book_related']], '@row.title'), '@row is the post');
    }

    public function test_terms_of_the_post_and_all_terms(): void
    {
        $this->assertSame(['Fiction (3) fiction', 'Classic (1) classic'], $this->texts(['source' => ['type' => 'terms', 'taxonomy' => 'genre']], '@row.name (@row.count) @row.slug'));
        $this->assertSame(['Classic', 'Fiction'], $this->texts(['source' => ['type' => 'terms', 'taxonomy' => 'genre', 'scope' => 'all']], '@row.name'));
        $this->assertSame(['https://site.test/genre/fiction/ Made up'], array_slice($this->texts(['source' => ['type' => 'terms', 'taxonomy' => 'genre']], '@row.url @row.description'), 0, 1));
        $this->assertSame([], $this->texts(['source' => ['type' => 'terms', 'taxonomy' => 'nope']], '@row.name'));
    }

    public function test_images(): void
    {
        $plan = Loop::items(['source' => ['type' => 'images', 'field' => 'book_gallery']], new BindingContext(5));
        $context = (new BindingContext(5))->forItem($plan['items'][1]);

        $this->assertCount(2, $plan['items']);
        $this->assertSame('https://site.test/img-12-thumbnail.jpg', RowValues::forTarget(['row' => 'image', 'size' => 'thumbnail'], $context, Target::for('core/image', 'url')));
        $this->assertSame(12, RowValues::forTarget(['row' => 'image'], $context, Target::for('core/image', 'id')));
        $this->assertSame('12', RowValues::text(['row' => 'id'], $context));
    }

    public function test_privacy_and_bad_sources(): void
    {
        $this->assertSame([], $this->texts(['source' => ['type' => 'repeater', 'field' => 'book_secret_rows']], '@row.x'), 'bindings: false');
        $this->assertSame([], $this->texts(['source' => ['type' => 'repeater', 'field' => 'book_awards']], '@row.name', 6), 'a private post');
        $this->assertSame([], $this->texts(['source' => ['type' => 'nope']], '@row.name'));
        $this->assertSame([], $this->texts(['source' => ['type' => 'repeater', 'field' => 'book_year']], '@row.name'), 'not a repeater');
    }

    public function test_query_args(): void
    {
        $args = Sources::queryArgs([
            'postType' => ['book', 'post', 'nope'], 'terms' => ['genre' => 'current', 'nope' => [1]], 'excludeCurrent' => true,
            'author' => 'current', 'search' => ' spice ', 'orderBy' => 'title', 'order' => 'asc', 'sticky' => 'exclude',
        ], new BindingContext(5), 50);

        $this->assertSame(['book'], $args['post_type'], 'only public, existing types');
        $this->assertSame('publish', $args['post_status']);
        $this->assertSame(50, $args['posts_per_page']);
        $this->assertSame([['taxonomy' => 'genre', 'field' => 'term_id', 'terms' => [24, 25]]], $args['tax_query']);
        $this->assertSame([5, 7], $args['post__not_in'], 'this post and the sticky ones');
        $this->assertSame(3, $args['author']);
        $this->assertSame('spice', $args['s']);
        $this->assertSame(['title', 'ASC'], [$args['orderby'], $args['order']]);
        $this->assertNull(Sources::queryArgs(['postType' => 'post'], new BindingContext(5), 10), 'not viewable');
        $this->assertNull(Sources::queryArgs(['postType' => 'book', 'terms' => ['genre' => 'current']], new BindingContext(8), 10), 'no terms to match');
        $this->assertSame('date', Sources::queryArgs(['postType' => 'book', 'orderBy' => 'meta_value; DROP'], new BindingContext(5), 10)['orderby']);
    }

    public function test_the_cap(): void
    {
        $rows = array_map(static fn (int $i): array => ['name' => "Row {$i}", 'year' => (string) $i], range(1, 250));
        $this->meta['5|_taw_book_awards'] = json_encode($rows);

        $this->assertCount(Loop::MAX_ITEMS, Loop::items(['source' => ['type' => 'repeater', 'field' => 'book_awards'], 'limit' => 500], new BindingContext(5))['items']);
    }

    public function test_item_empty_and_pagination_blocks(): void
    {
        $item = new Item(Item::ROW, null, 5, 1, 1);
        $block = static fn (array $context): object => (object) ['context' => $context];

        $this->assertSame('<li class="wp-block-taw-loop-item">x</li>', Loop::renderItem([], 'x', $block(['taw/loopItem' => $item])));
        $this->assertSame('', Loop::renderItem([], 'x', $block([])), 'outside a loop');
        $this->assertSame('<div class="wp-block-taw-loop-empty">none</div>', Loop::renderEmpty([], 'none', $block(['taw/loopEmpty' => true])));
        $this->assertSame('', Loop::renderEmpty([], 'none', $block([])));

        $nav = Loop::renderPagination([], '', $block(['taw/loopPages' => ['current' => 2, 'total' => 3, 'param' => 'taw-loop-4']]));
        $this->assertStringContainsString('href="/books/">Previous</a>', $nav, 'page 1 drops the parameter');
        $this->assertStringContainsString('aria-current="page">2</span>', $nav);
        $this->assertStringContainsString('href="/books/?taw-loop-4=3">Next</a>', $nav);
        $this->assertSame('', Loop::renderPagination([], '', $block(['taw/loopPages' => ['current' => 1, 'total' => 1, 'param' => 'p']])));
    }

    public function test_previews_inside_a_loop_read_its_first_item(): void
    {
        Functions\when('current_user_can')->alias(static fn (string $cap, int $id = 0): bool => $cap === 'edit_post' && $id === 5);
        $awards = ['source' => ['type' => 'repeater', 'field' => 'book_awards'], 'order' => ['by' => '@row.year', 'as' => 'number']];
        $item = static fn (array $extra): array => $extra + ['key' => 'k', 'postId' => 5, 'postType' => 'book', 'loops' => [$awards]];

        $this->assertSame('Nebula (1965) 1/3', PreviewEndpoint::resolve($item(['kind' => 'tag', 'args' => ['expr' => '@row.name (@row.year) @loop.index/@loop.count']])));
        $this->assertSame('Nebula', PreviewEndpoint::resolve($item(['args' => ['field' => '', 'row' => 'name'], 'block' => 'core/paragraph', 'attribute' => 'content'])));
        $this->assertSame(['shown' => true, 'errors' => []], PreviewEndpoint::resolve($item(['kind' => 'condition', 'args' => ['if' => ['rules' => [['value' => '@row.year', 'op' => 'lt', 'to' => 1966]]]]])));
        $this->assertSame(['value' => 'Ann', 'errors' => []], PreviewEndpoint::resolve($item(['kind' => 'expr', 'args' => ['expr' => '@row.person'], 'loops' => [
            ['source' => ['type' => 'repeater', 'field' => 'book_awards']],
            ['source' => ['type' => 'repeater', 'field' => 'jury', 'from' => 'row']],
        ]])), 'nested loops: each first item');
        $this->assertNull(PreviewEndpoint::resolve(['key' => 'k', 'postId' => 8, 'kind' => 'tag', 'args' => ['expr' => '@row.name'], 'loops' => [$awards]]), 'a post the user can\'t edit');
    }

    public function test_editor_data_lists_loop_sources(): void
    {
        Functions\when('get_taxonomies')->alias(static fn (array $args = [], string $output = 'names') => $output === 'objects'
            ? ['genre' => (object) ['labels' => (object) ['name' => 'Genres'], 'object_type' => ['book']], 'post_format' => (object) ['labels' => (object) ['name' => 'Formats'], 'object_type' => ['post']]]
            : ['genre' => 'genre']);
        Functions\when('get_post_types')->justReturn(['book' => (object) ['labels' => (object) ['singular_name' => 'Book']], 'attachment' => (object) ['labels' => (object) ['singular_name' => 'Media']]]);

        $data = EditorData::all();
        $book = $data['sources']['post']['book'];

        $this->assertSame(['book_awards', 'book_related', 'book_gallery'], array_column($book, 'field'), 'bindings: false is left out');
        $this->assertSame(['repeater', 'related', 'images'], array_column($book, 'type'));
        $this->assertSame(['name', 'year', 'won', 'jury'], array_column($book[0]['subs'], 'id'));
        $this->assertSame([['id' => 'person', 'label' => 'Person', 'type' => 'text']], $book[0]['subs'][3]['subs'], 'nested repeaters');
        $this->assertSame([['name' => 'book', 'label' => 'Book']], $data['postTypes']);
        $this->assertSame([['name' => 'genre', 'label' => 'Genres', 'postTypes' => ['book']]], $data['taxonomies']);
    }

    public function test_register_and_context_for_every_block(): void
    {
        Loop::register();
        Loop::register();

        $this->assertSame(10, has_action('init', [Loop::class, 'registerBlocks']));
        $this->assertSame(10, has_filter('register_block_type_args', [Loop::class, 'addContext']));
        $this->assertSame(['queryId', 'postId', 'taw/loopItem', 'postType'], Loop::addContext(['uses_context' => ['queryId', 'postId']])['uses_context']);
        $this->assertSame(['taw/loopItem', 'postId', 'postType'], Loop::addContext([])['uses_context']);
    }
}
