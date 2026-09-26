<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use Brain\Monkey\Functions;
use TAW\Core\Bindings\BindingContext;
use TAW\Core\Bindings\Bindings;
use TAW\Core\Bindings\InlineTags;
use TAW\Core\Bindings\PreviewEndpoint;
use TAW\Core\Bindings\Reference;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * Inline dynamic tags (ADR-0011, data layer Phase 7a Step 1).
 */
final class InlineTagsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $meta = [];

    private bool $canRead = false;

    /** Blocks rendered by get_the_excerpt() (the recursion guard's case). */
    private int $nestedRenders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetRegistries();
        Bindings::reset();
        $this->meta = [];
        $this->canRead = false;
        $this->nestedRenders = 0;

        $posts = [
            5 => new \WP_Post(['ID' => 5, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Dune']),
            6 => new \WP_Post(['ID' => 6, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Private']),
            7 => new \WP_Post(['ID' => 7, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Locked']),
            8 => new \WP_Post(['ID' => 8, 'post_type' => 'book', 'post_author' => 3, 'post_title' => 'Second']),
        ];
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => $type === 'book');
        Functions\when('get_post')->alias(static fn ($id) => $posts[$id instanceof \WP_Post ? $id->ID : (int) $id] ?? null);
        Functions\when('is_post_publicly_viewable')->alias(static fn (\WP_Post $post): bool => $post->ID !== 6);
        Functions\when('current_user_can')->alias(fn (string $cap, int $id = 0): bool => $this->canRead || ($cap === 'edit_post' && $id === 5));
        Functions\when('post_password_required')->alias(static fn (\WP_Post $post): bool => $post->ID === 7);
        Functions\when('get_queried_object')->justReturn(null);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["{$id}|{$key}"] ?? '');
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false) => match ($name) {
            'date_format'       => 'Y-m-d',
            '_taw_company_name' => 'ACME & Sons',
            default             => $default,
        });
        Functions\when('get_the_title')->alias(static fn (\WP_Post $post): string => $post->ID === 5 ? 'Dune &#8211; <em>Part One</em>' : $post->post_title);
        Functions\when('get_the_date')->alias(static fn (string $format, \WP_Post $post): string => "date({$format})#{$post->ID}");
        Functions\when('get_the_modified_date')->alias(static fn (string $format, \WP_Post $post): string => "modified({$format})#{$post->ID}");
        Functions\when('get_permalink')->alias(static fn (\WP_Post $post): string => "https://site.test/books/{$post->ID}/");
        Functions\when('get_the_excerpt')->alias(function (\WP_Post $post): string {
            // Generating an excerpt renders the post's blocks, tags included.
            $this->nestedRenders++;
            return InlineTags::renderBlock('<p><span class="taw-tag" data-taw-tag=\'{"tag":"post.excerpt"}\'>stale</span></p>') === '<p><span class="taw-tag" data-taw-tag=\'{"tag":"post.excerpt"}\'>stale</span></p>'
                ? 'A <b>desert</b> planet.'
                : 'recursed';
        });
        Functions\when('get_the_author_meta')->alias(static fn (string $field, int $id): string => $id === 3 ? 'Frank Herbert' : '');
        Functions\when('get_post_type_object')->alias(static fn (string $type) => (object) ['labels' => (object) ['singular_name' => 'Book']]);
        Functions\when('get_bloginfo')->alias(static fn (string $show): string => ['name' => 'Books &amp; More', 'description' => 'Read <b>everything</b>'][$show] ?? '');
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://site.test' . $path);
        Functions\when('wp_date')->alias(static fn (string $format, ?int $ts = null): string => $format === 'Y' && $ts === null ? '2026' : gmdate($format, (int) $ts));
        Functions\when('wp_timezone')->alias(static fn (): \DateTimeZone => new \DateTimeZone('UTC'));
        Functions\when('wp_strip_all_tags')->alias(static fn (string $text): string => trim(strip_tags($text)));
        Functions\when('esc_html')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_json_encode')->alias(static fn ($data, int $flags = 0) => json_encode($data, $flags));
        Functions\when('__')->returnArg(1);
        Functions\when('_doing_it_wrong')->justReturn(null);

        new Metabox(['id' => 'book_box', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'book_year', 'type' => 'number', 'label' => 'Year'],
            ['id' => 'book_released', 'type' => 'datepicker', 'label' => 'Released'],
            ['id' => 'book_blurb', 'type' => 'wysiwyg', 'label' => 'Blurb'],
            ['id' => 'book_note', 'type' => 'text', 'label' => 'Note'],
            ['id' => 'book_secret', 'type' => 'text', 'bindings' => false],
        ]]);
        new OptionsPage(['id' => 'site', 'title' => 'Site', 'fields' => [['id' => 'company_name', 'type' => 'text', 'label' => 'Company']]]);

        $this->meta = [
            '5|_taw_book_year'     => '1965',
            '5|_taw_book_released' => '1965-08-01',
            '5|_taw_book_blurb'    => '<p>Spice &amp; <strong>sand</strong></p>',
            '5|_taw_book_note'     => '<script>alert(1)</script>',
            '5|_taw_book_secret'   => 'hidden',
            '8|_taw_book_year'     => '1969',
            '6|_taw_book_year'     => '2000',
        ];
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

    /** @param array<string, mixed> $args */
    private static function tag(array $args, string $stored = 'old'): string
    {
        return '<span class="taw-tag" data-taw-tag="' . htmlspecialchars((string) json_encode($args), ENT_QUOTES) . '">' . $stored . '</span>';
    }

    private static function render(string $html, int $postId = 5): string
    {
        return InlineTags::renderBlock($html, [], (object) ['context' => ['postId' => $postId]]);
    }

    public function test_post_and_site_properties_render_as_escaped_text(): void
    {
        $html = '<p>' . implode(' | ', array_map(static fn (string $t): string => self::tag(['tag' => $t]), [
            'post.id', 'post.title', 'post.date', 'post.url', 'post.author', 'post.type', 'site.name', 'site.tagline', 'site.url', 'site.year',
        ])) . '</p>';

        $this->assertSame(
            '<p><span class="taw-tag">5</span> | <span class="taw-tag">Dune – Part One</span> | <span class="taw-tag">date(Y-m-d)#5</span>'
            . ' | <span class="taw-tag">https://site.test/books/5/</span> | <span class="taw-tag">Frank Herbert</span> | <span class="taw-tag">Book</span>'
            . ' | <span class="taw-tag">Books &amp; More</span> | <span class="taw-tag">Read everything</span> | <span class="taw-tag">https://site.test/</span>'
            . ' | <span class="taw-tag">2026</span></p>',
            self::render($html)
        );
    }

    public function test_dates_take_the_tag_format(): void
    {
        $this->assertSame('<span class="taw-tag">modified(F j, Y)#5</span>', self::render(self::tag(['tag' => 'post.modified', 'format' => 'F j, Y'])));
        $this->assertSame('<span class="taw-tag">August 1, 1965</span>', self::render(self::tag(['field' => 'book_released', 'format' => 'F j, Y'])));
        $this->assertSame('<span class="taw-tag">1965-08-01</span>', self::render(self::tag(['field' => 'book_released'])), 'the site date format');
    }

    public function test_fields_render_as_plain_escaped_text(): void
    {
        $this->assertSame('<span class="taw-tag">1965</span>', self::render(self::tag(['field' => 'book_year'])));
        $this->assertSame('<span class="taw-tag">Spice &amp; sand</span>', self::render(self::tag(['field' => 'book_blurb'])));
        $this->assertSame('<span class="taw-tag">&lt;script&gt;alert(1)&lt;/script&gt;</span>', self::render(self::tag(['field' => 'book_note'])));
        $this->assertSame('<span class="taw-tag">ACME &amp; Sons</span>', self::render(self::tag(['field' => 'company_name', 'from' => 'option'])));
    }

    public function test_each_query_loop_item_reads_its_own_post(): void
    {
        $html = '<p>' . self::tag(['field' => 'book_year']) . '</p>';

        $this->assertSame('<p><span class="taw-tag">1965</span></p>', self::render($html, 5));
        $this->assertSame('<p><span class="taw-tag">1969</span></p>', self::render($html, 8));
    }

    public function test_privacy_and_opt_outs(): void
    {
        $this->assertSame('<span class="taw-tag"></span>', self::render(self::tag(['field' => 'book_year']), 6), 'a private post');
        $this->canRead = true;
        $this->assertSame('<span class="taw-tag">2000</span>', self::render(self::tag(['field' => 'book_year']), 6), 'readable for its editors');
        $this->canRead = false;

        $this->assertSame('<span class="taw-tag">Locked</span>', self::render(self::tag(['tag' => 'post.title']), 7), 'a protected post shows its title');
        $this->assertSame('<span class="taw-tag"></span>', self::render(self::tag(['tag' => 'post.excerpt']), 7), 'but not its excerpt');
        $this->assertSame('<span class="taw-tag"></span>', self::render(self::tag(['field' => 'book_secret'])), 'bindings: false');
    }

    public function test_fallbacks_and_broken_tags(): void
    {
        $this->assertSame('<span class="taw-tag">n/a &lt;b&gt;</span>', self::render(self::tag(['field' => 'book_missing', 'fallback' => 'n/a <b>'])));
        $this->assertSame('<span class="taw-tag"></span>', self::render(self::tag(['tag' => 'post.nope'])));
        $this->assertSame('<span class="taw-tag"></span>', self::render('<span class="taw-tag" data-taw-tag="{not json">old</span>'));
        $this->assertSame('<b><span class="taw-tag" title="x">1965</span></b> & more', self::render('<b><span class="taw-tag" title="x" data-taw-tag=\'{"field":"book_year"}\'>old</span></b> & more'));
    }

    public function test_the_excerpt_does_not_recurse(): void
    {
        $this->assertSame('<span class="taw-tag">A desert planet.</span>', self::render(self::tag(['tag' => 'post.excerpt'])));
        $this->assertSame(1, $this->nestedRenders);
    }

    public function test_html_without_tags_is_untouched(): void
    {
        $html = '<p class="taw-tag">A <span class="other">plain</span> paragraph</p>';

        $this->assertSame($html, self::render($html));
    }

    public function test_rich_text_blocks_get_the_post_context(): void
    {
        $paragraph = InlineTags::addContext(['attributes' => ['content' => ['type' => 'rich-text', 'source' => 'rich-text']], 'uses_context' => ['queryId']]);
        $spacer = InlineTags::addContext(['attributes' => ['height' => ['type' => 'string']]]);

        $this->assertSame(['queryId', 'postId', 'postType'], $paragraph['uses_context']);
        $this->assertArrayNotHasKey('uses_context', $spacer);
    }

    public function test_property_tags_stay_out_of_block_bindings(): void
    {
        $this->assertNull(Bindings::getValue(['tag' => 'post.title'], (object) ['name' => 'core/paragraph', 'context' => ['postId' => 5]], 'content'));
        $this->assertNull(Reference::fromArgs(['tag' => 'post.password']));
    }

    public function test_previews_resolve_tags_for_editable_posts(): void
    {
        $item = static fn (array $args, int $postId = 5): array => ['key' => 'k', 'kind' => 'tag', 'args' => $args, 'postId' => $postId, 'postType' => 'book'];

        $this->assertSame('Dune – Part One', PreviewEndpoint::resolve($item(['tag' => 'post.title'])));
        $this->assertSame('1965', PreviewEndpoint::resolve($item(['field' => 'book_year'])));
        $this->assertNull(PreviewEndpoint::resolve($item(['field' => 'book_year'], 8)), 'a post the user can\'t edit');
        $this->assertSame('—', PreviewEndpoint::resolve($item(['field' => 'book_missing', 'fallback' => '—'])));
        $this->assertSame('Dune – Part One', InlineTags::value(['tag' => 'post.title'], new BindingContext(5)));
    }

    // --- Expressions (ADR-0012) -----------------------------------------

    private static function expr(string $expression): string
    {
        return self::tag(['expr' => $expression]);
    }

    public function test_an_expression_chip_mixes_text_and_values(): void
    {
        $this->assertSame(
            '<span class="taw-tag">Published on date(F j, Y)#5 by Frank Herbert · 1965 &amp; ACME &amp; Sons</span>',
            self::render(self::expr("Published on @post.date.format('F j, Y') by @post.author · @book_year & @option.company_name"))
        );
        $this->assertSame('<span class="taw-tag">August 1, 1965</span>', self::render(self::expr("@book_released.format('F j, Y')")));
        $this->assertSame('<span class="taw-tag">&lt;b&gt;Dune – Part One&lt;/b&gt;</span>', self::render(self::expr('<b>@post.title</b>')), 'text parts are escaped too');
    }

    public function test_expression_functions(): void
    {
        $this->assertSame('<span class="taw-tag">FRANK HERBERT / books &amp; more</span>', self::render(self::expr('@post.author.upper() / @site.name.lower()')));
        $this->assertSame('<span class="taw-tag">Frank…</span>', self::render(self::expr('@post.author.truncate(5)')));
        $this->assertSame('<span class="taw-tag">n/a</span>', self::render(self::expr("@book_missing.default('n/a')")));
        $this->assertSame('<span class="taw-tag">Frank Herbert</span>', self::render(self::expr("@post.author.default('n/a')")), 'default only when empty');
    }

    public function test_expression_errors_and_privacy_render_empty(): void
    {
        $this->assertSame('<span class="taw-tag">Title:</span>', self::render(self::expr('Title: @post.title.shout()')));
        $this->assertSame('<span class="taw-tag">x</span>', self::render(self::expr("@post.title.shout().default('x')")));
        $this->assertSame('<span class="taw-tag">Year</span>', self::render(self::expr('Year @book_year'), 6), 'a private post');
        $this->assertSame('<span class="taw-tag">Secret:</span>', self::render(self::expr('Secret: @book_secret')), 'bindings: false');
        $this->assertSame('<span class="taw-tag">Fallback</span>', self::render(self::tag(['expr' => '@site.owner', 'fallback' => 'Fallback'])));
    }

    public function test_an_expression_with_excerpts_does_not_recurse(): void
    {
        $this->assertSame('<span class="taw-tag">A desert planet. / A desert planet.</span>', self::render(self::expr('@post.excerpt / @post.excerpt')));
        $this->assertSame(2, $this->nestedRenders);
    }

    public function test_an_expression_as_block_text(): void
    {
        $block = static fn (string $name): object => (object) ['name' => $name, 'context' => ['postId' => 5]];

        $this->assertSame('By Frank Herbert &amp; co', Bindings::getValue(['expr' => 'By @post.author & co'], $block('core/paragraph'), 'content'));
        $this->assertSame('Cover of Dune – Part One & co', Bindings::getValue(['expr' => 'Cover of @post.title & co'], $block('core/image'), 'alt'), 'attributes get plain text');
        $this->assertNull(Bindings::getValue(['expr' => '@post.url'], $block('core/button'), 'url'), 'not for URLs');
        $this->assertNull(Bindings::getValue(['expr' => '@book_missing'], $block('core/paragraph'), 'content'), 'empty keeps the saved text');
    }

    public function test_previews_evaluate_expressions_with_errors(): void
    {
        $item = static fn (string $expression, int $postId = 5): array => ['key' => 'k', 'kind' => 'expr', 'args' => ['expr' => $expression], 'postId' => $postId, 'postType' => 'book'];

        $this->assertSame(['value' => 'Year 1965', 'errors' => []], PreviewEndpoint::resolve($item('Year @book_year')));
        $this->assertSame(['value' => 'Hi', 'errors' => [['code' => 'unknown_function', 'at' => 15]]], PreviewEndpoint::resolve($item('Hi @post.title.nope()')));
        $this->assertNull(PreviewEndpoint::resolve($item('Year @book_year', 8)), 'a post the user can\'t edit');
        $this->assertSame('Year 1965', PreviewEndpoint::resolve(['key' => 'k', 'kind' => 'tag', 'args' => ['expr' => 'Year @book_year'], 'postId' => 5]), 'an expression chip\'s preview');
    }

    public function test_register_hooks_render_and_context_filters(): void
    {
        Bindings::register();

        $this->assertSame(10, has_filter('render_block', [InlineTags::class, 'renderBlock']));
        $this->assertSame(10, has_filter('register_block_type_args', [InlineTags::class, 'addContext']));
    }
}
