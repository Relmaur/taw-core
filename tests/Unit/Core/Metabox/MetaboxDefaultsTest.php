<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Fields\PostFields;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * `'defaults'` on a metabox or options page: what a field reads, and its
 * edit screen shows, while nothing is stored, so a theme's `?:` fallbacks
 * can move into config and render the same page.
 */
final class MetaboxDefaultsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        OptionsPage::resetForTests();
        $this->meta = [];
        Functions\when('post_type_exists')->alias(static fn (string $t): bool => in_array($t, ['page', 'book', 'movie'], true));
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $this->meta[$id . $key] ?? '');
        Functions\when('wp_json_encode')->alias(static fn ($v, int $flags = 0) => json_encode($v, $flags));
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        OptionsPage::resetForTests();
        parent::tearDown();
    }

    private function hero(): Metabox
    {
        return new Metabox(['id' => 'taw_hero', 'title' => 'Hero', 'screens' => ['page'], 'fields' => [
            ['id' => 'hero_heading', 'type' => 'text'],
            ['id' => 'hero_items', 'type' => 'repeater', 'fields' => [['id' => 'title', 'type' => 'text']]],
            ['id' => 'hero_count', 'type' => 'number'],
            ['id' => 'hero_empty', 'type' => 'text'],
        ], 'defaults' => [
            'hero_heading' => 'Welcome',
            'hero_items'   => [['title' => 'One'], ['title' => 'Two "quoted"']],
            'hero_count'   => '3',
            'not_a_field'  => 'ignored',
            'hero_empty'   => '',
        ]]);
    }

    public function test_get_returns_the_default_until_a_value_is_stored(): void
    {
        $box = $this->hero();

        $this->assertSame('Welcome', Metabox::get(5, 'hero_heading'));
        $this->meta['5_taw_hero_heading'] = 'Mine';
        $this->assertSame('Mine', Metabox::get(5, 'hero_heading'));
        $this->meta['5_taw_hero_count'] = '7';
        $this->assertSame('7', Metabox::get(5, 'hero_count'));

        $this->assertSame(['hero_heading', 'hero_items', 'hero_count'], array_keys($box->defaults()), 'only declared fields, and an empty default is none');
        $this->assertSame('', Metabox::get(5, 'not_a_field'));
    }

    public function test_empty_means_what_a_fallback_saw(): void
    {
        new Metabox(['id' => 'taw_team', 'title' => 'Team', 'screens' => ['page'], 'fields' => [
            ['id' => 'photo', 'type' => 'image'],
            ['id' => 'members', 'type' => 'repeater', 'fields' => [['id' => 'name', 'type' => 'text']]],
            ['id' => 'count', 'type' => 'number'],
            ['id' => 'show', 'type' => 'checkbox'],
        ], 'defaults' => ['photo' => 5510, 'members' => [['name' => 'Ana']], 'count' => '3', 'show' => '1']]);
        $this->meta['5_taw_photo'] = '0';       // an image field saved blank
        $this->meta['5_taw_members'] = '[]';    // a repeater saved with no rows
        $this->meta['5_taw_count'] = '0';
        $this->meta['5_taw_show'] = '0';        // unchecked

        $this->assertSame(5510, Metabox::get(5, 'photo'));
        $this->assertSame([['name' => 'Ana']], Metabox::get_repeater(5, 'members'));
        $this->assertSame('3', Metabox::get(5, 'count'), 'as `?:` did');
        $this->assertSame('0', Metabox::get(5, 'show'), "a checkbox's '0' is a choice");
        $this->assertFalse(Metabox::get_bool(5, 'show'));
    }

    public function test_structured_defaults_read_like_stored_json(): void
    {
        $this->hero();

        $this->assertSame([['title' => 'One'], ['title' => 'Two "quoted"']], Metabox::get_repeater(5, 'hero_items'));
        $this->assertSame('[{"title":"One"},{"title":"Two \"quoted\""}]', Metabox::get(5, 'hero_items'));
    }

    public function test_a_shared_key_takes_the_default_of_the_metabox_on_the_post(): void
    {
        new Metabox(['id' => 'book', 'title' => 'Book', 'screens' => ['book'], 'fields' => [['id' => 'subtitle', 'type' => 'text']], 'defaults' => ['subtitle' => 'A book']]);
        new Metabox(['id' => 'movie', 'title' => 'Movie', 'screens' => ['movie'], 'fields' => [['id' => 'subtitle', 'type' => 'text']], 'defaults' => ['subtitle' => 'A movie']]);
        Functions\when('get_post')->alias(static fn (int $id) => new \WP_Post(['ID' => $id, 'post_type' => $id === 2 ? 'movie' : 'book']));

        $this->assertSame('A book', Metabox::get(1, 'subtitle'));
        $this->assertSame('A movie', Metabox::get(2, 'subtitle'));
    }

    public function test_the_edit_screen_shows_the_default_so_saving_keeps_it(): void
    {
        $box = $this->hero();

        $this->assertSame('Welcome', $this->callMethod($box, 'read_value', 5, '_taw_hero_heading'));
        $this->meta['5_taw_hero_heading'] = 'Mine';
        $this->assertSame('Mine', $this->callMethod($box, 'read_value', 5, '_taw_hero_heading'));
    }

    public function test_typed_reads_fall_back_too(): void
    {
        $this->hero();
        $fields = new PostFields(new \WP_Post(['ID' => 5, 'post_type' => 'page']));

        $this->assertSame('Welcome', $this->callMethod($fields, 'read', '_taw_hero_heading'));
    }

    public function test_an_options_page_default(): void
    {
        $options = [];
        Functions\when('get_option')->alias(static function (string $key, $default = false) use (&$options) {
            return array_key_exists($key, $options) ? $options[$key] : $default;
        });
        new OptionsPage(['id' => 'taw_footer', 'fields' => [['id' => 'phone', 'type' => 'text'], ['id' => 'fax', 'type' => 'text']], 'defaults' => ['phone' => '555-0100']]);

        $this->assertSame('555-0100', OptionsPage::get('phone'));
        $this->assertSame('n/a', OptionsPage::get('fax', '_taw_', 'n/a'), 'no page default: the caller fallback still applies');
        $options['_taw_phone'] = '555-0199';
        $this->assertSame('555-0199', OptionsPage::get('phone'));
        $options['_taw_fax'] = '';
        $this->assertSame('', OptionsPage::get('fax', '_taw_', 'n/a'), 'a stored empty value stays empty, as before');
    }
}
