<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Fields;

use Brain\Monkey\Functions;
use TAW\Core\Fields\Image;
use TAW\Core\Fields\PostRef;
use TAW\Core\Fields\Rows;
use TAW\Core\Fields\Value;
use TAW\Core\Metabox\Metabox;
use TAW\Taw;
use TAW\Tests\TestCase;

/**
 * The typed value API for posts (ADR-0009, data layer Phase 3 Step 1).
 */
final class PostFieldsTest extends TestCase
{
    /** @var array<string, mixed> "id|key" → stored value */
    private array $meta = [];

    /** @var list<string> */
    private array $notices = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        $this->meta = [];
        $this->notices = [];

        $posts = [
            7  => new \WP_Post(['ID' => 7, 'post_type' => 'book', 'post_name' => 'dune']),
            21 => new \WP_Post(['ID' => 21, 'post_type' => 'page', 'post_name' => 'contact']),
        ];
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['book', 'page'], true));
        Functions\when('get_post')->alias(static function ($post = null) use ($posts) {
            if ($post === null) {
                return $posts[7];
            }
            if ($post instanceof \WP_Post) {
                return $post;
            }

            return $posts[(int) $post] ?? null;
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["{$id}|{$key}"] ?? '');
        Functions\when('get_the_title')->alias(static fn (int $id): string => $id === 21 ? 'Contact & us' : '');
        Functions\when('get_permalink')->alias(static fn (int $id): string => "https://site.test/?p={$id}");
        Functions\when('wp_get_attachment_image_url')->alias(static fn (int $id, string $size = 'full') => $id === 49 ? "https://site.test/cover-{$size}.jpg" : false);
        Functions\when('wp_get_attachment_image_src')->alias(static fn (int $id, string $size = 'full') => $id === 49 ? ["https://site.test/cover-{$size}.jpg", 800, 600, false] : false);
        Functions\when('wp_get_attachment_image')->alias(static fn (int $id, $size = 'full', bool $icon = false, array $attr = []) => $id === 49 ? '<img src="cover-' . $size . '.jpg" class="' . ($attr['class'] ?? '') . '">' : '');
        Functions\when('esc_html')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_url')->alias(static fn ($url): string => str_starts_with((string) $url, 'javascript:') ? '' : htmlspecialchars((string) $url, ENT_QUOTES));
        Functions\when('wp_kses_post')->alias(static fn ($html): string => (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string) $html));
        Functions\when('wpautop')->alias(static fn (string $text): string => '<p>' . str_replace("\n\n", "</p>\n<p>", $text) . '</p>');
        Functions\when('_doing_it_wrong')->alias(function (string $function, string $message): void {
            $this->notices[] = "{$function}: {$message}";
        });

        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'book_subtitle', 'type' => 'text'],
            ['id' => 'book_site', 'type' => 'url'],
            ['id' => 'book_blurb', 'type' => 'wysiwyg'],
            ['id' => 'book_pages', 'type' => 'number'],
            ['id' => 'book_featured', 'type' => 'checkbox'],
            ['id' => 'book_cover', 'type' => 'image'],
            ['id' => 'book_gallery', 'type' => 'files'],
            ['id' => 'book_contact', 'type' => 'post_select', 'post_type' => 'page'],
            ['id' => 'book_related', 'type' => 'post_select', 'post_type' => 'page', 'multiple' => true],
            ['id' => 'book_address', 'type' => 'group', 'fields' => [
                ['id' => 'city', 'type' => 'text'],
                ['id' => 'zip', 'type' => 'number'],
            ]],
            ['id' => 'book_awards', 'type' => 'repeater', 'fields' => [
                ['id' => 'name', 'type' => 'text'],
                ['id' => 'logo', 'type' => 'image'],
                ['id' => 'won', 'type' => 'checkbox'],
                ['id' => 'links', 'type' => 'repeater', 'fields' => [['id' => 'href', 'type' => 'url']]],
            ]],
            ['id' => 'book_heading', 'type' => 'gradient_text'],
        ]]);
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        parent::tearDown();
    }

    private function store(string $field, mixed $value, int $post = 7): void
    {
        $this->meta["{$post}|_taw_{$field}"] = $value;
    }

    public function test_plain_values_echo_escaped_and_keep_the_raw_value(): void
    {
        $this->store('book_subtitle', 'Dune <script>x</script> & more');
        $this->store('book_site', 'javascript:alert(1)');
        $this->store('book_blurb', "<strong>Epic</strong>\n\nSecond<script>bad()</script>");

        $book = Taw::post(7);

        $this->assertSame('Dune &lt;script&gt;x&lt;/script&gt; &amp; more', (string) $book->field('book_subtitle'));
        $this->assertSame('Dune <script>x</script> & more', $book->field('book_subtitle')->raw());
        $this->assertSame('', (string) $book->field('book_site'), 'unsafe URL dropped');
        $this->assertSame("<strong>Epic</strong>\n\nSecond", (string) $book->field('book_blurb'), 'wp_kses_post, no paragraphs');
        $this->assertSame("<p><strong>Epic</strong></p>\n<p>Second</p>", $book->field('book_blurb')->paragraphs());
    }

    public function test_the_current_post_and_references_by_qualified_id_and_meta_key(): void
    {
        $this->store('book_subtitle', 'Dune');

        $this->assertSame('Dune', Taw::post()->field('book_subtitle')->text());
        $this->assertSame('Dune', Taw::post(7)->field('book_details.book_subtitle')->text());
        $this->assertSame('Dune', Taw::post(7)->field('_taw_book_subtitle')->text());
        $this->assertSame('text', Taw::post(7)->field('book_subtitle')->type());
    }

    public function test_scalars_typed(): void
    {
        $this->store('book_pages', '412');
        $this->store('book_featured', '1');
        $book = Taw::post(7);

        $this->assertSame(412, $book->field('book_pages')->int());
        $this->assertSame(412.0, $book->field('book_pages')->float());
        $this->assertSame(412, $book->field('book_pages')->value());
        $this->assertTrue($book->field('book_featured')->bool());
        $this->assertSame('', (string) $book->field('book_featured'), 'structured types print nothing');
        $this->assertFalse(Taw::post(7)->field('book_subtitle')->exists());
        $this->assertSame('Untitled', (string) Taw::post(7)->field('book_subtitle')->or('Untitled'));
    }

    public function test_images(): void
    {
        $this->store('book_cover', '49');
        $this->store('book_gallery', '[49,"50",0]');
        $book = Taw::post(7);

        $cover = $book->field('book_cover')->image();
        $this->assertInstanceOf(Image::class, $cover);
        $this->assertTrue($cover->exists());
        $this->assertSame(49, $cover->id());
        $this->assertSame('https://site.test/cover-large.jpg', $cover->url('large'));
        $this->assertSame([800, 600], [$cover->width(), $cover->height()]);
        $this->assertSame('<img src="cover-medium.jpg" class="c">', $cover->html('medium', ['class' => 'c']));
        $this->assertSame('<img src="cover-full.jpg" class="">', (string) $book->field('book_cover'), 'echo prints the <img>');
        $this->assertSame([49, 50], array_map(static fn (Image $i): int => $i->id(), $book->field('book_gallery')->images()));
        $this->assertSame(49, $book->field('book_gallery')->image()->id());

        $none = Taw::post(7)->field('book_cover_missing')->image();
        $this->assertFalse($none->exists());
        $this->assertSame(['', ''], [$none->url(), $none->html()]);
    }

    public function test_post_select_single_and_multiple(): void
    {
        $this->store('book_contact', '21');
        $this->store('book_related', '[21, 999]');
        $book = Taw::post(7);

        $contact = $book->field('book_contact')->post();
        $this->assertInstanceOf(PostRef::class, $contact);
        $this->assertSame(['Contact & us', 'https://site.test/?p=21', 'page'], [$contact->title(), $contact->url(), $contact->type()]);
        $this->assertSame('Contact &amp; us', (string) $book->field('book_contact'));
        $this->assertSame([21, 999], array_map(static fn (PostRef $p): int => $p->id(), $book->field('book_related')->posts()));
        $this->assertFalse($book->field('book_related')->posts()[1]->exists(), 'a deleted post');
        $this->assertSame('', $book->field('book_related')->posts()[1]->url());
        $this->assertSame('', (string) $book->field('book_related'));
    }

    public function test_groups_read_their_sub_fields(): void
    {
        $this->store('book_address_city', 'Monterrey');
        $this->store('book_address_zip', '64000');
        $address = Taw::post(7)->field('book_address');

        $this->assertSame('group', $address->type());
        $this->assertSame('Monterrey', (string) $address->field('city'));
        $this->assertSame(['city' => 'Monterrey', 'zip' => 64000], $address->value());
        $this->assertTrue($address->exists());
        $this->assertSame('Monterrey', Taw::post(7)->field('book_details.book_address')->field('city')->text());
    }

    public function test_repeater_rows_are_typed_by_their_sub_fields(): void
    {
        $this->store('book_awards', '[{"name":"Hugo <b>","logo":"49","won":"1","links":[{"href":"https://a.test"}]},{"name":"Nebula","logo":"","won":"","links":[]}]');
        $rows = Taw::post(7)->field('book_awards')->rows();

        $this->assertInstanceOf(Rows::class, $rows);
        $this->assertCount(2, $rows);
        $names = [];
        foreach ($rows as $row) {
            $names[] = (string) $row->field('name');
        }
        $this->assertSame(['Hugo &lt;b&gt;', 'Nebula'], $names);
        $first = $rows->first();
        $this->assertNotNull($first);
        $this->assertSame(49, $first->field('logo')->image()->id());
        $this->assertTrue($first->field('won')->bool());
        $this->assertSame('https://a.test', $first->field('links')->rows()->first()?->field('href')->text());
        $this->assertSame(
            [['name' => 'Hugo <b>', 'logo' => 49, 'won' => true, 'links' => [['href' => 'https://a.test']]], ['name' => 'Nebula', 'logo' => 0, 'won' => false, 'links' => []]],
            Taw::post(7)->field('book_awards')->value(),
            'value() decodes like REST and export'
        );
        $this->assertSame(Taw::post(7)->field('book_awards')->value(), $rows->value());
        $this->assertTrue(Taw::post(7)->field('book_gallery')->rows()->isEmpty());
    }

    public function test_missing_posts_and_unregistered_fields_never_throw(): void
    {
        $this->store('legacy_note', 'Hand-written');

        $this->assertSame('Hand-written', (string) Taw::post(7)->field('legacy_note'), 'unregistered: _taw_<id>, untyped');
        $this->assertNull(Taw::post(7)->field('legacy_note')->type());

        foreach ([Taw::post(false), Taw::post(0), Taw::post(12345)] as $missing) {
            $value = $missing->field('book_subtitle');
            $this->assertFalse($missing->exists());
            $this->assertSame(['', '', false, 0, []], [(string) $value, $value->text(), $value->bool(), $value->int(), $value->images()]);
            $this->assertCount(0, $value->rows());
            $this->assertFalse($value->image()->exists());
            $this->assertFalse($value->post()->exists());
            $this->assertSame('', (string) $value->field('city'));
        }
    }

    public function test_an_accessor_that_does_not_fit_the_type_converts_and_warns(): void
    {
        $this->store('book_subtitle', '49');

        $this->assertSame(49, Taw::post(7)->field('book_subtitle')->image()->id());
        $this->assertCount(0, Taw::post(7)->field('book_subtitle')->rows());
        $this->assertSame([
            'TAW\Core\Fields\Value::image(): Field "book_subtitle" is a text field.',
            'TAW\Core\Fields\Value::rows(): Field "book_subtitle" is a text field.',
        ], $this->notices);

        $this->notices = [];
        Taw::post(7)->field('legacy_note')->rows();
        Taw::post(7)->field('book_cover')->image();
        $this->assertSame([], $this->notices, 'untyped fields and fitting accessors stay quiet');
    }

    public function test_gradient_text_decodes_to_segments(): void
    {
        $this->store('book_heading', '[{"text":"Du","highlighted":true},{"text":"ne","highlighted":false}]');

        $this->assertSame([['text' => 'Du', 'highlighted' => true], ['text' => 'ne', 'highlighted' => false]], json_decode((string) Taw::post(7)->field('book_heading')->raw(), true));
        $this->assertSame('', (string) Taw::post(7)->field('book_heading'));
    }

    public function test_values_are_memoized_per_reader(): void
    {
        $book = Taw::post(7);

        $this->assertSame($book->field('book_subtitle'), $book->field('book_subtitle'));
        $this->assertInstanceOf(Value::class, Value::none());
    }
}
