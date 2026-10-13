<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Fields;

use Brain\Monkey\Functions;
use TAW\Core\Fields\Value;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * Issue #213: the `json` field type, and `'on_invalid' => 'keep'` (a json
 * field's default): JSON that doesn't parse, or that the field's own
 * `validate` rejects, never replaces the stored value, and the rejected
 * input comes back in the field.
 */
final class JsonFieldTest extends TestCase
{
    /** @var array<string, mixed> "id|key" → value */
    private array $termMeta = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Metabox::forgetRejectedForTests();
        $this->termMeta = [];
        $this->transients = [];
        Functions\when('post_type_exists')->justReturn(false);
        Functions\when('wp_json_encode')->alias(static fn ($v, int $flags = 0) => json_encode($v, $flags));
        Functions\when('__')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(3);
        Functions\when('get_term_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->termMeta["{$id}|{$key}"] ?? '');
        Functions\when('update_term_meta')->alias(function (int $id, string $key, $value): bool {
            $this->termMeta["{$id}|{$key}"] = $value;
            return true;
        });
        Functions\when('delete_term_meta')->alias(function (int $id, string $key): bool {
            unset($this->termMeta["{$id}|{$key}"]);
            return true;
        });
        Functions\when('set_transient')->alias(function (string $key, $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key) => $this->transients[$key] ?? false);
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetRejectedForTests();
        Metabox::forgetInstances();
        $_POST = [];
        unset($_SERVER['HTTP_SEC_FETCH_DEST']);
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $fields */
    private function box(array $fields): Metabox
    {
        return new Metabox(['id' => 'preview', 'title' => 'Preview', 'screens' => ['term:genre'], 'fields' => $fields]);
    }

    /** @param array<string, string> $values */
    private function save(Metabox $box, array $values): void
    {
        $_POST = $values + ['preview_nonce' => 'n'];
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->alias(static fn ($v) => is_string($v) ? trim(strip_tags($v)) : '');
        Functions\when('get_term')->justReturn(new \WP_Term(['term_id' => 9, 'taxonomy' => 'genre']));
        Functions\when('current_user_can')->justReturn(true);
        $box->save_term(9);
    }

    public function test_storage_is_compact_json_that_keeps_empty_objects_apart_from_empty_lists(): void
    {
        $this->assertSame('{"hero":{},"items":[],"n":1.5,"s":"a/b é"}', Metabox::sanitizeJsonValue("{\n  \"hero\": {},\n  \"items\": [], \"n\": 1.5, \"s\": \"a\\/b \\u00e9\"\n}"));
        $this->assertSame('', Metabox::sanitizeJsonValue('{"broken": '), 'text that does not parse is never stored as JSON');
        $this->assertSame('', Metabox::sanitizeJsonValue('   '));
        $this->assertSame('{"a":1}', Metabox::sanitizeJsonValue(['a' => 1]), 'a value from code, REST or an import');
        $this->assertSame('"just text"', Metabox::sanitizeJsonValue('"just text"'), 'a JSON scalar is valid JSON');
    }

    public function test_the_editor_shows_stored_json_pretty_and_typed_text_as_it_is(): void
    {
        $this->assertSame("{\n    \"a\": {}\n}", Metabox::jsonForEditing('{"a":{}}'));
        $this->assertSame("{\n  \"a\": oops\n}", Metabox::jsonForEditing("{\n  \"a\": oops\n}"), 'a refilled rejected input, exactly as typed');
        $this->assertSame('', Metabox::jsonForEditing(''));
    }

    public function test_validation_parses_first_then_calls_validate_with_the_decoded_value(): void
    {
        $calls = [];
        $box = $this->box([['id' => 'request', 'type' => 'json', 'label' => 'Request', 'validate' => static function (mixed $decoded) use (&$calls) {
            $calls[] = $decoded;
            return isset($decoded['hero']) ? true : 'hero is missing';
        }]]);
        $field = $box->fields()[0];

        $this->assertSame('Request: invalid JSON (Syntax error).', $this->callMethod($box, 'validate_field', $field, '{"hero": '));
        $this->assertSame([], $calls, 'not called for JSON that does not parse');
        $this->assertSame('hero is missing', $this->callMethod($box, 'validate_field', $field, '{"items": []}'));
        $this->assertTrue($this->callMethod($box, 'validate_field', $field, '{"hero": {}}'));
        $this->assertSame([['items' => []], ['hero' => []]], $calls);
        $this->assertTrue($this->callMethod($box, 'validate_field', $field, ''), 'an empty field is not checked');
    }

    public function test_a_two_parameter_validate_also_gets_the_submitted_text(): void
    {
        $seen = null;
        $box = $this->box([['id' => 'request', 'type' => 'json', 'validate' => static function (mixed $decoded, string $raw) use (&$seen): bool {
            $seen = json_decode($raw);
            return true;
        }]]);
        $this->callMethod($box, 'validate_field', $box->fields()[0], '{"hero": {}}');
        $this->assertInstanceOf(\stdClass::class, $seen->hero ?? null, 'decode the text yourself to tell {} from []');

        $builtin = $this->box([['id' => 'list', 'type' => 'json', 'validate' => 'is_array']]);
        $this->assertTrue($this->callMethod($builtin, 'validate_field', $builtin->fields()[0], '[1, 2]'), 'a built-in gets one argument');
    }

    public function test_required_means_some_json(): void
    {
        $box = $this->box([['id' => 'request', 'type' => 'json', 'label' => 'Request', 'required' => true]]);

        $this->assertSame('Request is required.', $this->callMethod($box, 'validate_field', $box->fields()[0], '  '));
    }

    public function test_invalid_json_keeps_the_stored_value_and_comes_back_in_the_field(): void
    {
        $box = $this->box([['id' => 'request', 'type' => 'json', 'label' => 'Request'], ['id' => 'note', 'type' => 'text']]);
        $this->termMeta['9|_taw_request'] = '{"live":true}';

        $this->save($box, ['_taw_request' => "{\n  \"live\": fals\n}", '_taw_note' => 'Saved anyway']);

        $this->assertSame('{"live":true}', $this->termMeta['9|_taw_request'], 'the live value is untouched');
        $this->assertSame('Saved anyway', $this->termMeta['9|_taw_note'], 'the other fields still save');
        $this->assertNotEmpty($this->transients['taw_validation_errors_term_9'] ?? null);
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'empty';
        $this->callMethod($box, 'read_value', 9, '_taw_request');
        $this->assertArrayHasKey('taw_rejected_term_9_3', $this->transients, "the block editor's unseen render (a fetch) leaves it");

        Metabox::forgetRejectedForTests();
        $_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';
        $this->assertSame("{\n  \"live\": fals\n}", $this->callMethod($box, 'read_value', 9, '_taw_request'), 'the edit screen shows the rejected input');
        $this->assertArrayNotHasKey('taw_rejected_term_9_3', $this->transients, 'a page load takes it');
        ob_start();
        Metabox::render_rejected_note((new \ReflectionProperty($box, 'refilled'))->getValue($box)['_taw_request'] ?? null);
        $this->assertStringContainsString('Not saved: Request: invalid JSON (Syntax error).', strip_tags((string) ob_get_clean()));

        Metabox::forgetRejectedForTests();
        $this->assertSame('{"live":true}', $this->callMethod($box, 'read_value', 9, '_taw_request'), 'the next visit shows the stored value');
    }

    public function test_a_later_good_save_drops_the_rejected_input(): void
    {
        $box = $this->box([['id' => 'request', 'type' => 'json']]);
        $this->save($box, ['_taw_request' => '{"a": ']);
        $this->assertArrayHasKey('taw_rejected_term_9_3', $this->transients);

        $this->save($box, ['_taw_request' => '{"a": 1}']);

        $this->assertArrayNotHasKey('taw_rejected_term_9_3', $this->transients);
        $this->assertSame('{"a":1}', $this->termMeta['9|_taw_request']);
    }

    public function test_valid_json_is_stored_compact(): void
    {
        $box = $this->box([['id' => 'request', 'type' => 'json']]);

        $this->save($box, ['_taw_request' => "{\n    \"hero\": {}\n}"]);

        $this->assertSame('{"hero":{}}', $this->termMeta['9|_taw_request']);
    }

    public function test_on_invalid_keep_works_for_any_type_and_store_stays_the_default(): void
    {
        $reject = static fn (mixed $v) => $v === 'ok' ? true : 'Not ok';
        $box = $this->box([
            ['id' => 'kept', 'type' => 'text', 'validate' => $reject, 'on_invalid' => 'keep'],
            ['id' => 'stored', 'type' => 'text', 'validate' => $reject],
        ]);
        $this->termMeta['9|_taw_kept'] = 'old';
        $this->termMeta['9|_taw_stored'] = 'old';

        $this->save($box, ['_taw_kept' => 'bad', '_taw_stored' => 'bad']);

        $this->assertSame('old', $this->termMeta['9|_taw_kept']);
        $this->assertSame('bad', $this->termMeta['9|_taw_stored'], "'store' (the default for other types) saves the input, as before");
        $this->assertSame(['_taw_kept' => ['value' => 'bad', 'error' => 'Not ok']], $this->transients['taw_rejected_term_9_3']);
        $this->assertFalse(Metabox::keepsInvalid(['type' => 'json', 'on_invalid' => 'store']), 'a json field can opt out');
    }

    public function test_options_page_never_stores_invalid_json_and_refills_it(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, $default = false) => $name === '_taw_site_data' ? '{"a":1}' : $default);
        Functions\when('add_settings_error')->justReturn(null);
        $captured = [];
        Functions\when('register_setting')->alias(static function (string $group, string $name, array $args) use (&$captured): void {
            $captured[$name] = $args['sanitize_callback'];
        });
        $page = new OptionsPage(['id' => 'taw_site', 'fields' => [['id' => 'site_data', 'type' => 'json', 'label' => 'Site data']]]);
        $page->register_settings();

        $this->assertSame('{"a":1}', $captured['_taw_site_data']('{"a": '));
        $this->assertSame('{"a": ', Metabox::rejectedInput('option', 0)['_taw_site_data']['value'] ?? null);
        $this->assertSame('{"b":[]}', $captured['_taw_site_data']("{ \"b\": [] }"));
    }

    public function test_typed_reads_decode_and_print_nothing(): void
    {
        Functions\when('get_post_meta')->justReturn('{"hero":{"title":"Hi"}}');
        new Metabox(['id' => 'preview', 'title' => 'Preview', 'screens' => ['page'], 'fields' => [['id' => 'request', 'type' => 'json']]]);
        $value = (new \TAW\Core\Fields\PostFields(new \WP_Post(['ID' => 5, 'post_type' => 'page'])))->field('request');

        $this->assertInstanceOf(Value::class, $value);
        $this->assertSame(['hero' => ['title' => 'Hi']], $value->value());
        $this->assertSame('', $value->html());
    }

    public function test_a_json_sub_field_in_a_repeater_row_is_stored_compact(): void
    {
        $config = ['id' => 'rows', 'type' => 'repeater', 'fields' => [['id' => 'data', 'type' => 'json']]];

        $stored = Metabox::sanitizeForStorage($config, [['data' => "{ \"a\": {} }"], ['data' => '{"a": ']]);

        $this->assertSame([['data' => '{"a":{}}']], json_decode((string) $stored, true), 'invalid JSON in a row is dropped (rows are not validated)');
    }

    public function test_a_json_field_has_no_inline_editor_unless_asked(): void
    {
        $this->assertNull(Metabox::editorConfigOf(['type' => 'json']));
        $this->assertTrue(Metabox::editorConfigOf(['type' => 'json', 'editor' => true]));
    }
}
