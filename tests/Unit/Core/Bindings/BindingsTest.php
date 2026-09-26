<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use Brain\Monkey\Functions;
use TAW\Core\Bindings\Bindings;
use TAW\Core\Bindings\Target;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * The `taw/field` Block Bindings source (ADR-0010, data layer Phase 4 Step 1).
 */
final class BindingsTest extends TestCase
{
    /** @var array<string, mixed> "kind|id|key" → stored value */
    private array $meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private mixed $queried = null;

    private bool $canRead = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetRegistries();
        Bindings::reset();
        $this->meta = [];
        $this->options = [];
        $this->queried = null;
        $this->canRead = false;

        $posts = [
            5  => new \WP_Post(['ID' => 5, 'post_type' => 'page', 'post_author' => 3]),
            6  => new \WP_Post(['ID' => 6, 'post_type' => 'page', 'post_author' => 3]),
            7  => new \WP_Post(['ID' => 7, 'post_type' => 'page', 'post_author' => 3]),
            40 => new \WP_Post(['ID' => 40, 'post_type' => 'page']),
        ];
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => $type === 'page');
        Functions\when('get_post')->alias(static fn ($id) => $posts[$id instanceof \WP_Post ? $id->ID : (int) $id] ?? null);
        Functions\when('is_post_publicly_viewable')->alias(static fn (\WP_Post $post): bool => $post->ID !== 6);
        Functions\when('current_user_can')->alias(fn (): bool => $this->canRead);
        Functions\when('post_password_required')->alias(static fn (\WP_Post $post): bool => $post->ID === 7);
        Functions\when('get_queried_object')->alias(fn () => $this->queried);
        Functions\when('get_term')->alias(static fn ($id) => (int) $id === 9 ? new \WP_Term(['term_id' => 9, 'taxonomy' => 'genre']) : null);
        Functions\when('get_userdata')->alias(static fn (int $id) => $id === 3 ? new \WP_User(3) : false);
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["post|{$id}|{$key}"] ?? '');
        Functions\when('get_term_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["term|{$id}|{$key}"] ?? '');
        Functions\when('get_user_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["user|{$id}|{$key}"] ?? '');
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false) => array_key_exists($name, $this->options) ? $this->options[$name] : $default);
        Functions\when('wp_get_attachment_image_url')->alias(static fn (int $id, string $size = 'full') => $id === 49 ? "https://site.test/cover-{$size}.jpg" : false);
        Functions\when('get_the_title')->alias(static fn (int $id): string => [49 => 'Cover title', 40 => 'Related & co'][$id] ?? '');
        Functions\when('wp_get_attachment_caption')->alias(static fn (int $id) => $id === 49 ? 'A <caption>' : '');
        Functions\when('get_permalink')->alias(static fn (int $id) => $id === 40 ? 'https://site.test/related' : false);
        Functions\when('get_post_thumbnail_id')->alias(static fn (int $id): int => $id === 40 ? 49 : 0);
        Functions\when('esc_html')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_url_raw')->alias(static fn (string $url): string => preg_match('#^(https?:|mailto:|tel:|/|\#)#', $url) ? $url : '');
        Functions\when('wp_kses_post')->alias(static fn ($html): string => strip_tags((string) $html, '<p><strong><em><a><br>'));
        Functions\when('wp_strip_all_tags')->alias(static fn ($text): string => strip_tags((string) $text));
        Functions\when('_doing_it_wrong')->justReturn(null);

        new Metabox(['id' => 'book_box', 'title' => 'Book', 'screens' => ['page'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'text'],
            ['id' => 'blurb', 'type' => 'textarea'],
            ['id' => 'body', 'type' => 'wysiwyg'],
            ['id' => 'site', 'type' => 'url'],
            ['id' => 'cover', 'type' => 'image'],
            ['id' => 'cta', 'type' => 'link'],
            ['id' => 'related', 'type' => 'post_select'],
            ['id' => 'shelf', 'type' => 'post_select', 'multiple' => true],
            ['id' => 'released', 'type' => 'datepicker'],
            ['id' => 'featured', 'type' => 'checkbox'],
            ['id' => 'address', 'type' => 'group', 'fields' => [['id' => 'city', 'type' => 'text']]],
            ['id' => 'secret', 'type' => 'text', 'bindings' => false],
        ]]);
        new Metabox(['id' => 'genre_box', 'title' => 'Genre', 'screens' => ['term:genre'], 'fields' => [['id' => 'tagline', 'type' => 'text']]]);
        new Metabox(['id' => 'author_box', 'title' => 'Author', 'screens' => ['user'], 'fields' => [
            ['id' => 'phone', 'type' => 'text'],
            ['id' => 'bio', 'type' => 'text', 'bindings' => true],
        ]]);
        new OptionsPage(['id' => 'site', 'title' => 'Site', 'fields' => [['id' => 'company_phone', 'type' => 'text']]]);

        $this->meta = [
            'post|5|_taw_subtitle'   => '<b>Dune</b> & more',
            'post|5|_taw_blurb'      => "Line one\nLine <two>",
            'post|5|_taw_body'       => '<p>Hi <script>x()</script><strong>there</strong></p>',
            'post|5|_taw_site'       => 'https://a.test/x?y=1',
            'post|5|_taw_cover'      => '49',
            'post|5|_taw_cta'        => '{"url":"/buy","label":"Buy now","new_tab":true}',
            'post|5|_taw_related'    => '40',
            'post|5|_taw_shelf'      => '[40]',
            'post|5|_taw_released'   => '2026-09-25',
            'post|5|_taw_featured'   => '1',
            'post|5|_taw_address_city' => 'Mérida',
            'post|5|_taw_secret'     => 'hidden',
            'post|5|_taw_rogue'      => 'not registered',
            'post|6|_taw_subtitle'   => 'Private',
            'post|7|_taw_subtitle'   => 'Locked',
            'post|49|_wp_attachment_image_alt' => 'Cover alt',
            'term|9|_taw_tagline'    => 'Space & time',
            'user|3|_taw_phone'      => '555',
            'user|3|_taw_bio'        => 'Writes <books>',
        ];
        $this->options = ['_taw_company_phone' => '+52 999'];
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
     * @param array<string, mixed> $args
     */
    private function bind(array $args, string $block, string $attribute, int $postId = 5): mixed
    {
        $instance = (object) ['name' => $block, 'context' => ['postId' => $postId, 'postType' => 'page'], 'block_type' => null];

        return Bindings::getValue($args, $instance, $attribute);
    }

    public function test_text_fields_arrive_escaped_in_rich_text_and_plain_in_attributes(): void
    {
        $this->assertSame('&lt;b&gt;Dune&lt;/b&gt; &amp; more', $this->bind(['field' => 'subtitle'], 'core/heading', 'content'));
        $this->assertSame('&lt;b&gt;Dune&lt;/b&gt; &amp; more', $this->bind(['field' => 'book_box.subtitle'], 'core/button', 'text'), 'qualified id');
        $this->assertSame('<b>Dune</b> & more', $this->bind(['field' => 'subtitle'], 'core/image', 'alt'), 'the tag processor escapes attributes');
        $this->assertNull($this->bind(['field' => 'subtitle'], 'core/button', 'url'), 'text is not a URL');
        $this->assertSame("Line one<br>\nLine &lt;two&gt;", $this->bind(['field' => 'blurb'], 'core/paragraph', 'content'));
        $this->assertSame('<p>Hi x()<strong>there</strong></p>', $this->bind(['field' => 'body'], 'core/paragraph', 'content'), 'wysiwyg keeps safe HTML, no added <p>');
    }

    public function test_urls_images_and_links(): void
    {
        $this->assertSame('https://a.test/x?y=1', $this->bind(['field' => 'site'], 'core/button', 'url'));
        $this->assertSame('https://a.test/x?y=1', $this->bind(['field' => 'site'], 'core/paragraph', 'content'));
        $this->meta['post|5|_taw_site'] = 'javascript:alert(1)';
        $this->assertNull($this->bind(['field' => 'site'], 'core/button', 'url'), 'an unsafe URL binds nothing');

        $this->assertSame(49, $this->bind(['field' => 'cover'], 'core/image', 'id'));
        $this->assertSame('https://site.test/cover-full.jpg', $this->bind(['field' => 'cover'], 'core/image', 'url'));
        $this->assertSame('https://site.test/cover-large.jpg', $this->bind(['field' => 'cover', 'size' => 'large'], 'core/image', 'url'));
        $this->assertSame('Cover alt', $this->bind(['field' => 'cover'], 'core/image', 'alt'));
        $this->assertSame('Cover title', $this->bind(['field' => 'cover'], 'core/image', 'title'));
        $this->assertSame('A &lt;caption&gt;', $this->bind(['field' => 'cover'], 'core/image', 'caption'));
        $this->assertNull($this->bind(['field' => 'cover'], 'core/paragraph', 'content'));

        $this->assertSame('/buy', $this->bind(['field' => 'cta'], 'core/button', 'url'));
        $this->assertSame('Buy now', $this->bind(['field' => 'cta'], 'core/button', 'text'));
        $this->assertSame('_blank', $this->bind(['field' => 'cta'], 'core/button', 'linkTarget'));
        $this->assertSame('noopener', $this->bind(['field' => 'cta'], 'core/button', 'rel'));
        $this->meta['post|5|_taw_cta'] = '{"url":"/buy","label":"","new_tab":false}';
        $this->assertFalse($this->bind(['field' => 'cta'], 'core/button', 'linkTarget'), 'not a new tab: the target is removed');
        $this->assertSame('/buy', $this->bind(['field' => 'cta'], 'core/button', 'text'), 'no label: the URL');
    }

    public function test_post_selects_and_dates(): void
    {
        $this->assertSame('Related &amp; co', $this->bind(['field' => 'related'], 'core/heading', 'content'));
        $this->assertSame('https://site.test/related', $this->bind(['field' => 'related'], 'core/button', 'url'));
        $this->assertSame(49, $this->bind(['field' => 'related'], 'core/image', 'id'), 'the post\'s featured image');
        $this->assertSame('A &lt;caption&gt;', $this->bind(['field' => 'related'], 'core/image', 'caption'), 'and its caption');
        $this->assertSame('https://site.test/cover-medium.jpg', $this->bind(['field' => 'related', 'size' => 'medium'], 'core/image', 'url'));
        $this->assertNull($this->bind(['field' => 'shelf'], 'core/heading', 'content'), 'several posts don\'t bind');

        $this->assertSame('2026-09-25', $this->bind(['field' => 'released'], 'core/post-date', 'datetime'));
        $this->assertNull($this->bind(['field' => 'subtitle'], 'core/post-date', 'datetime'), 'only datepicker gives a date');
    }

    public function test_structured_fields_and_groups(): void
    {
        $this->assertNull($this->bind(['field' => 'featured'], 'core/paragraph', 'content'));
        $this->assertNull($this->bind(['field' => 'address'], 'core/paragraph', 'content'), 'a group needs a sub');
        $this->assertSame('Mérida', $this->bind(['field' => 'address', 'sub' => 'city'], 'core/paragraph', 'content'));
        $this->assertNull($this->bind(['field' => 'address', 'sub' => 'nope'], 'core/paragraph', 'content'));
    }

    public function test_privacy(): void
    {
        $this->assertNull($this->bind(['field' => 'subtitle'], 'core/heading', 'content', 6), 'a private post');
        $this->canRead = true;
        $this->assertSame('Private', $this->bind(['field' => 'subtitle'], 'core/heading', 'content', 6), 'unless the viewer can read it');
        $this->assertNull($this->bind(['field' => 'subtitle'], 'core/heading', 'content', 7), 'a password-protected post');
        $this->assertNull($this->bind(['field' => 'secret'], 'core/heading', 'content'), 'bindings: false');
        $this->assertNull($this->bind(['field' => 'rogue'], 'core/heading', 'content'), 'no untyped fallback');
        $this->assertNull($this->bind(['field' => '_taw_rogue'], 'core/heading', 'content'), 'no arbitrary meta keys');
        $this->assertNull($this->bind(['field' => 'phone', 'from' => 'user'], 'core/paragraph', 'content'), 'user fields are opt-in');
    }

    public function test_where_values_come_from(): void
    {
        $this->assertSame('+52 999', $this->bind(['field' => 'company_phone', 'from' => 'option'], 'core/paragraph', 'content', 0));

        $this->assertNull($this->bind(['field' => 'tagline', 'from' => 'term'], 'core/paragraph', 'content'), 'not a term archive');
        $this->queried = new \WP_Term(['term_id' => 9, 'taxonomy' => 'genre']);
        $this->assertSame('Space &amp; time', $this->bind(['field' => 'tagline', 'from' => 'term'], 'core/paragraph', 'content'));

        $this->queried = null;
        $this->assertSame('Writes &lt;books&gt;', $this->bind(['field' => 'bio', 'from' => 'user'], 'core/paragraph', 'content'), 'the post\'s author');
        $this->queried = new \WP_User(3);
        $this->assertSame('Writes &lt;books&gt;', $this->bind(['field' => 'bio', 'from' => 'user'], 'core/paragraph', 'content', 0), 'an author archive');

        $this->queried = new \WP_Post(['ID' => 5, 'post_type' => 'page']);
        $this->assertSame('&lt;b&gt;Dune&lt;/b&gt; &amp; more', $this->bind(['field' => 'subtitle'], 'core/heading', 'content', 0), 'no postId context: the queried post');
    }

    public function test_empty_or_bad_bindings_keep_the_saved_content(): void
    {
        $this->meta['post|5|_taw_subtitle'] = '';
        $this->assertNull($this->bind(['field' => 'subtitle'], 'core/heading', 'content'));
        $this->assertNull($this->bind([], 'core/heading', 'content'));
        $this->assertNull($this->bind(['field' => 'subtitle', 'from' => 'site'], 'core/heading', 'content'));
        $this->assertNull($this->bind(['field' => 'subtitle'], 'core/heading', 'content', 999), 'no such post');
    }

    public function test_attributes_other_blocks_add_are_classified_from_their_schema(): void
    {
        $this->assertSame('text', Target::for('acme/quote', 'citation', ['source' => 'rich-text'])->kind);
        $this->assertSame('url', Target::for('acme/card', 'link', ['source' => 'attribute', 'attribute' => 'href'])->kind);
        $this->assertSame('id', Target::for('acme/card', 'mediaId', ['type' => 'number'])->kind);
        $this->assertSame('plain', Target::for('acme/card', 'label', ['type' => 'string'])->kind);
    }

    public function test_the_source_is_registered_once_on_init(): void
    {
        $registered = [];
        Functions\when('__')->returnArg(1);
        Functions\when('register_block_bindings_source')->alias(static function (string $name, array $props) use (&$registered): bool {
            $registered[$name] = $props;

            return true;
        });

        Bindings::register();
        Bindings::register();
        $this->assertSame(10, has_action('init', [Bindings::class, 'registerSource']));

        Bindings::registerSource();
        $this->assertSame(['postId', 'postType'], $registered['taw/field']['uses_context']);
        $this->assertSame([Bindings::class, 'getValue'], $registered['taw/field']['get_value_callback']);
    }
}
