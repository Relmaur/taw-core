<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Lens;

use Brain\Monkey\Functions;
use TAW\Core\Lens\FieldWriter;
use TAW\Core\Lens\Lens;
use TAW\Core\Log\Logger;
use TAW\Core\Log\LogSinkInterface;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * Lens build step 5: a field edited on the page is saved by `POST taw/v1/lens/field`, through the same write
 * primitives as `taw fields:set`, only when the page saw what's stored, and with what puts it back.
 */
final class FieldWriterTest extends TestCase
{
    /** @var array<string, mixed> Post meta by "id:key". */
    private array $meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, bool> Capabilities the developer has, by name. */
    private array $caps = ['edit_post' => true, 'edit_term' => true, 'edit_user' => true, 'manage_options' => true];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        OptionsPage::resetForTests();

        Functions\when('__')->returnArg();
        Functions\when('post_type_exists')->alias(static fn (string $t): bool => $t === 'page');
        Functions\when('get_post_type')->alias(static fn (int $id): string|false => $id === 12 ? 'page' : false);
        Functions\when('get_post')->alias(static fn (int $id) => $id === 12 ? new \WP_Post(['ID' => 12, 'post_type' => 'page', 'post_name' => 'home']) : null);
        Functions\when('current_user_can')->alias(fn (string $cap): bool => $this->caps[$cap] ?? false);
        Functions\when('get_metadata_raw')->alias(fn (string $type, int $id, string $key) => $this->meta["{$id}:{$key}"] ?? null);
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta["{$id}:{$key}"] = is_string($value) ? stripslashes($value) : $value;

            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta["{$id}:{$key}"]);

            return true;
        });
        Functions\when('get_option')->alias(fn (string $key, $default = false) => array_key_exists($key, $this->options) ? $this->options[$key] : $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);

            return true;
        });
        Functions\when('wp_slash')->alias(static fn ($v) => is_string($v) ? addslashes($v) : $v);
        Functions\when('sanitize_text_field')->alias(static fn ($v): string => trim(strip_tags((string) $v)));
        Functions\when('sanitize_textarea_field')->alias(static fn ($v): string => strip_tags((string) $v));
        Functions\when('wp_kses_post')->alias(static fn ($v): string => preg_replace('#<script.*?</script>#s', '', (string) $v) ?? '');
        Functions\when('wp_json_encode')->alias(static fn ($v, int $flags = 0) => json_encode($v, $flags));

        new Metabox(['id' => 'taw_hero', 'title' => 'Hero', 'screens' => ['page'], 'fields' => [
            ['id' => 'hero_title', 'label' => 'Title', 'type' => 'text'],
            ['id' => 'hero_intro', 'label' => 'Intro', 'type' => 'wysiwyg'],
            ['id' => 'hero_image', 'label' => 'Image', 'type' => 'image'],
            ['id' => 'hero_embed', 'label' => 'Embed', 'type' => 'textarea', 'sanitize' => 'code'],
            ['id' => 'faq', 'label' => 'FAQ', 'type' => 'repeater', 'fields' => [
                ['id' => 'q', 'label' => 'Question', 'type' => 'text'],
                ['id' => 'a', 'label' => 'Answer', 'type' => 'textarea'],
            ]],
        ], 'defaults' => ['hero_title' => 'Welcome']]);
        new OptionsPage(['id' => 'taw-settings', 'title' => 'TAW Settings', 'fields' => [
            ['id' => 'company_phone', 'label' => 'Phone', 'type' => 'text'],
        ], 'defaults' => ['company_phone' => '555-0100']]);

        $logged = &$this->logged;
        Logger::setSinks(new class ($logged) implements LogSinkInterface {
            /** @param list<string> $codes */
            public function __construct(private array &$codes)
            {
            }

            public function write(array $entry): void
            {
                $this->codes[] = (string) $entry['code'] . ' ' . (string) json_encode($entry['context']);
            }
        });
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        OptionsPage::resetForTests();
        parent::tearDown();
    }

    /** @param array<string, mixed> $body */
    private function write(array $body): array
    {
        return FieldWriter::write($body + ['store' => 'post', 'object' => 12]);
    }

    public function test_it_saves_a_text_field_sanitized_like_an_admin_save_and_hands_back_the_old_value(): void
    {
        $this->meta['12:_taw_hero_title'] = 'Old "title"';

        $result = $this->write(['key' => 'hero_title', 'value' => ' New <b>"title"</b> ', 'expect' => 'Old "title"']);

        self::assertSame(200, $result['status']);
        self::assertSame(['saved' => 'New "title"', 'default' => false, 'previous' => 'Old "title"'], $result['body']);
        self::assertSame('New "title"', $this->meta['12:_taw_hero_title'], 'slashed for update_post_meta, stored intact');
        self::assertStringStartsWith('lens.field_saved {"store":"post","object":12,"field":"hero_title"', $this->logged[0]);
    }

    public function test_nothing_is_written_when_the_field_changed_since_the_page_loaded(): void
    {
        $this->meta['12:_taw_hero_title'] = 'Someone else’s';

        $result = $this->write(['key' => 'hero_title', 'value' => 'Mine', 'expect' => 'What the page showed']);

        self::assertSame(409, $result['status']);
        self::assertSame('changed', $result['body']['error']);
        self::assertSame('Someone else’s', $result['body']['current']);
        self::assertSame('Someone else’s', $this->meta['12:_taw_hero_title']);
        self::assertSame([], $this->logged);
    }

    public function test_a_save_over_the_default_undoes_back_to_nothing_stored(): void
    {
        $saved = $this->write(['key' => 'hero_title', 'value' => 'Hello', 'expect' => 'Welcome']);
        self::assertSame(['saved' => 'Hello', 'default' => false, 'previous' => null], $saved['body'], 'the page showed the default');

        $undone = $this->write(['key' => 'hero_title', 'value' => null, 'expect' => 'Hello']);
        self::assertSame(200, $undone['status']);
        self::assertSame(['saved' => 'Welcome', 'default' => true, 'previous' => 'Hello'], $undone['body']);
        self::assertArrayNotHasKey('12:_taw_hero_title', $this->meta);
    }

    public function test_it_saves_a_rich_text_field_through_wp_kses_post(): void
    {
        $this->meta['12:_taw_hero_intro'] = 'We are a <strong>studio</strong>.';

        $result = $this->write(['key' => 'hero_intro', 'value' => 'We are <em>two</em>.<script>x</script>', 'expect' => 'We are a <strong>studio</strong>.']);

        self::assertSame('We are <em>two</em>.', $result['body']['saved']);
        self::assertSame('We are <em>two</em>.', $this->meta['12:_taw_hero_intro']);
    }

    public function test_it_saves_one_repeater_row_sub_field_and_keeps_the_other_rows(): void
    {
        $this->meta['12:_taw_faq'] = (string) json_encode([['q' => 'Why?', 'a' => 'Because.'], ['q' => 'How?', 'a' => 'Like this.']]);

        $result = $this->write(['key' => 'faq', 'row' => 1, 'sub' => 'q', 'value' => 'How so?', 'expect' => 'How?']);

        self::assertSame(['saved' => 'How so?', 'default' => false, 'previous' => 'How?'], $result['body']);
        self::assertSame([['q' => 'Why?', 'a' => 'Because.'], ['q' => 'How so?', 'a' => 'Like this.']], json_decode((string) $this->meta['12:_taw_faq'], true));

        self::assertSame(409, $this->write(['key' => 'faq', 'row' => 5, 'sub' => 'q', 'value' => 'x', 'expect' => ''])['status'], 'a row that isn’t there');
        self::assertSame(404, $this->write(['key' => 'faq', 'row' => 0, 'sub' => 'nope', 'value' => 'x'])['status']);
        self::assertSame(422, $this->write(['key' => 'faq', 'row' => 0, 'sub' => 'q', 'value' => null])['status'], 'a row is never removed');
    }

    public function test_it_saves_an_options_page_field_with_manage_options(): void
    {
        $result = FieldWriter::write(['store' => 'option', 'object' => 'taw-settings', 'key' => 'company_phone', 'value' => '555-0199', 'expect' => '555-0100']);

        self::assertSame(['saved' => '555-0199', 'default' => false, 'previous' => null], $result['body']);
        self::assertSame('555-0199', $this->options['_taw_company_phone']);

        $this->caps['manage_options'] = false;
        self::assertSame(403, FieldWriter::write(['store' => 'option', 'object' => 'taw-settings', 'key' => 'company_phone', 'value' => 'x'])['status']);
        $this->caps['manage_options'] = true;
        self::assertSame(404, FieldWriter::write(['store' => 'option', 'object' => 'other-page', 'key' => 'company_phone', 'value' => 'x'])['status']);
    }

    public function test_it_needs_the_capability_to_edit_what_the_field_is_stored_on(): void
    {
        $this->caps['edit_post'] = false;

        $result = $this->write(['key' => 'hero_title', 'value' => 'x']);

        self::assertSame(403, $result['status']);
        self::assertSame([], $this->meta);
    }

    public function test_it_refuses_fields_the_page_cant_edit_as_text(): void
    {
        self::assertSame('not-editable', $this->write(['key' => 'hero_image', 'value' => '5'])['body']['error']);
        self::assertSame('not-editable', $this->write(['key' => 'hero_embed', 'value' => '<iframe>'])['body']['error'], 'raw code never');
        self::assertSame(404, $this->write(['key' => 'nope', 'value' => 'x'])['status']);
        self::assertSame(404, $this->write(['object' => 99, 'key' => 'hero_title', 'value' => 'x'])['status'], 'no such post');
        self::assertSame([], $this->meta);
    }

    public function test_it_refuses_requests_it_doesnt_understand(): void
    {
        foreach ([
            ['store' => 'wp', 'key' => 'blogname', 'value' => 'x'],
            ['key' => '', 'value' => 'x'],
            ['key' => 'hero_title', 'value' => ['x']],
            ['key' => 'hero_title', 'value' => str_repeat('x', FieldWriter::VALUE_MAX + 1)],
            ['key' => 'faq', 'row' => 0, 'value' => 'x'],
            ['key' => 'faq', 'sub' => 'q', 'value' => 'x'],
            ['key' => 'faq', 'row' => -1, 'sub' => 'q', 'value' => 'x'],
            ['key' => 'hero_title', 'value' => 'x', 'expect' => 5],
        ] as $body) {
            self::assertSame(422, $this->write($body)['status'], (string) json_encode($body));
        }
    }

    public function test_edit_modes_and_rich_text_formats_follow_the_field_config(): void
    {
        self::assertSame('text', FieldWriter::editMode(['type' => 'text']));
        self::assertSame('text', FieldWriter::editMode(['type' => 'textarea']));
        self::assertSame('rich', FieldWriter::editMode(['type' => 'wysiwyg']));
        self::assertNull(FieldWriter::editMode(['type' => 'url']));
        self::assertNull(FieldWriter::editMode(['type' => 'text', 'sanitize' => 'code']));
        self::assertSame(['bold', 'italic', 'link', 'lists'], FieldWriter::formats(['type' => 'wysiwyg']));
        self::assertSame(['bold', 'italic', 'link', 'lists'], FieldWriter::formats(['type' => 'wysiwyg', 'blocks' => ['core/paragraph', 'core/list']]));
        self::assertSame(['bold', 'italic', 'link'], FieldWriter::formats(['type' => 'wysiwyg', 'blocks' => ['core/paragraph']]));
    }

    public function test_the_route_answers_with_the_writers_status_and_is_never_cached(): void
    {
        $this->meta['12:_taw_hero_title'] = 'Old';
        $request = new \WP_REST_Request('POST', '/taw/v1/lens/field', ['store' => 'post', 'object' => 12, 'key' => 'hero_title', 'value' => 'New', 'expect' => 'Stale']);

        $response = Lens::fieldRoute($request);

        self::assertSame(409, $response->get_status());
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }
}
