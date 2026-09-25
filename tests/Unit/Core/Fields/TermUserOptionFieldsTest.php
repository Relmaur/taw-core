<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Fields;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Taw;
use TAW\Tests\TestCase;

/**
 * The typed value API for terms, users and options (ADR-0009, data layer
 * Phase 3 Step 2).
 */
final class TermUserOptionFieldsTest extends TestCase
{
    /** @var array<string, mixed> "kind|id|key" → stored value */
    private array $meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private mixed $queried = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetRegistries();
        $this->meta = [];
        $this->options = [];
        $this->queried = null;

        $terms = [9 => new \WP_Term(['term_id' => 9, 'taxonomy' => 'genre', 'slug' => 'sci-fi']), 4 => new \WP_Term(['term_id' => 4, 'taxonomy' => 'category'])];
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => $type === 'book');
        Functions\when('get_term')->alias(static fn ($id) => $terms[(int) $id] ?? null);
        Functions\when('get_userdata')->alias(static fn (int $id) => $id === 3 ? new \WP_User(3) : false);
        Functions\when('get_queried_object')->alias(fn () => $this->queried);
        Functions\when('get_term_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["term|{$id}|{$key}"] ?? '');
        Functions\when('get_user_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["user|{$id}|{$key}"] ?? '');
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false) => array_key_exists($name, $this->options) ? $this->options[$name] : $default);
        Functions\when('esc_html')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
        Functions\when('esc_url')->alias(static fn ($url): string => htmlspecialchars((string) $url, ENT_QUOTES));
        Functions\when('wp_get_attachment_image_url')->alias(static fn (int $id) => $id === 49 ? 'https://site.test/logo.png' : false);
        Functions\when('_doing_it_wrong')->justReturn(null);

        new Metabox(['id' => 'genre_details', 'title' => 'Genre', 'screens' => ['term:genre'], 'fields' => [
            ['id' => 'genre_tagline', 'type' => 'text'],
            ['id' => 'genre_icon', 'type' => 'image'],
            ['id' => 'genre_meta', 'type' => 'group', 'fields' => [['id' => 'color', 'type' => 'color']]],
        ]]);
        new Metabox(['id' => 'author_details', 'title' => 'Author', 'screens' => ['user'], 'fields' => [
            ['id' => 'author_links', 'type' => 'repeater', 'fields' => [['id' => 'href', 'type' => 'url'], ['id' => 'label', 'type' => 'text']]],
            ['id' => 'author_featured', 'type' => 'checkbox'],
        ]]);
        new OptionsPage(['id' => 'site', 'title' => 'Site', 'fields' => [
            ['id' => 'company_phone', 'type' => 'text'],
            ['id' => 'company_logo', 'type' => 'image'],
            ['id' => 'social', 'type' => 'group', 'fields' => [['id' => 'x', 'type' => 'url'], ['id' => 'instagram', 'type' => 'url']]],
        ]]);
        new OptionsPage(['id' => 'legacy', 'title' => 'Legacy', 'prefix' => '_old_', 'fields' => [
            ['id' => 'company_phone', 'type' => 'number'],
        ]]);
    }

    protected function tearDown(): void
    {
        $this->resetRegistries();
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

    public function test_terms_read_their_fieldsets_through_term_meta(): void
    {
        $this->meta['term|9|_taw_genre_tagline'] = 'Space & time';
        $this->meta['term|9|_taw_genre_icon'] = '49';
        $this->meta['term|9|_taw_genre_meta_color'] = '#123456';

        $genre = Taw::term(9);

        $this->assertTrue($genre->exists());
        $this->assertSame('Space &amp; time', (string) $genre->field('genre_tagline'));
        $this->assertSame('text', $genre->field('genre_details.genre_tagline')->type());
        $this->assertSame('https://site.test/logo.png', $genre->field('genre_icon')->image()->url());
        $this->assertSame('#123456', $genre->field('genre_meta')->field('color')->text());
        $this->assertSame(['color' => '#123456'], $genre->field('genre_meta')->value());
    }

    public function test_the_queried_term_and_missing_terms(): void
    {
        $this->meta['term|9|_taw_genre_tagline'] = 'Space';
        $this->queried = new \WP_Term(['term_id' => 9, 'taxonomy' => 'genre']);

        $this->assertSame('Space', Taw::term()->field('genre_tagline')->text());
        $this->assertNull(Taw::term(4)->field('genre_tagline')->type(), 'a category: the genre fieldset does not apply');

        $this->queried = new \WP_Post(['ID' => 1]);
        $this->assertFalse(Taw::term()->exists(), 'not a term archive');
        $this->assertFalse(Taw::term(999)->exists());
        $this->assertSame('', (string) Taw::term(false)->field('genre_tagline'));
    }

    public function test_users_read_their_fieldsets_through_user_meta(): void
    {
        $this->meta['user|3|_taw_author_links'] = '[{"href":"https://a.test","label":"Site"}]';
        $this->meta['user|3|_taw_author_featured'] = '1';

        $author = Taw::user(3);

        $this->assertSame(3, $author->id());
        $this->assertTrue($author->field('author_featured')->bool());
        $row = $author->field('author_links')->rows()->first();
        $this->assertNotNull($row);
        $this->assertSame(['https://a.test', 'Site'], [(string) $row->field('href'), (string) $row->field('label')]);
        $this->assertSame($author->field('author_links')->value(), Taw::user(new \WP_User(3))->field('author_links')->value());
        $this->assertFalse(Taw::user(77)->exists());
        $this->assertFalse(Taw::user(0)->field('author_featured')->bool());
    }

    public function test_options_by_id_or_option_name_the_taw_prefix_first(): void
    {
        $this->options['_taw_company_phone'] = '555 <1>';
        $this->options['_old_company_phone'] = '42';
        $this->options['_taw_company_logo'] = '49';

        $this->assertSame('555 &lt;1&gt;', (string) Taw::option('company_phone'));
        $this->assertSame('555 <1>', Taw::option('_taw_company_phone')->raw());
        $this->assertSame(42, Taw::option('_old_company_phone')->value(), 'by option name: that page\'s number field');
        $this->assertSame(42, Taw::options('legacy')->field('company_phone')->int(), 'one page: its own field');
        $this->assertSame('text', Taw::options('site')->field('company_phone')->type());
        $this->assertSame('https://site.test/logo.png', Taw::option('company_logo')->image()->url());
    }

    public function test_option_groups_and_unregistered_options(): void
    {
        $this->options['_taw_social_x'] = 'https://x.test/a';
        $this->options['_taw_hand_made'] = 'Kept';

        $social = Taw::option('social');
        $this->assertSame('group', $social->type());
        $this->assertSame('https://x.test/a', (string) $social->field('x'));
        $this->assertSame(['x' => 'https://x.test/a', 'instagram' => ''], $social->value());
        $this->assertSame('https://x.test/a', Taw::options('site')->field('social')->field('x')->text());

        $this->assertSame('Kept', (string) Taw::option('hand_made'), 'unregistered: _taw_<id>, untyped');
        $this->assertNull(Taw::option('hand_made')->type());
        $this->assertTrue(Taw::option('nothing_here')->isEmpty());
        $this->assertNull(Taw::options('legacy')->field('social')->type(), 'the group is on another page');
    }
}
