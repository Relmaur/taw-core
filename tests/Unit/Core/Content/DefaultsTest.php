<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use Brain\Monkey\Functions;
use TAW\Core\Content\Defaults;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * "Save defaults to the database": only empty fields are written, a
 * journal goes first, and undo reverses exactly those writes.
 */
final class DefaultsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        OptionsPage::resetForTests();
        $this->meta = [];
        $this->options = [];
        $this->uploads = sys_get_temp_dir() . '/taw-defaults-' . uniqid();
        mkdir($this->uploads);

        Functions\when('post_type_exists')->alias(static fn (string $t): bool => $t === 'book');
        Functions\when('wp_json_encode')->alias(static fn ($v, int $flags = 0) => json_encode($v, $flags));
        Functions\when('get_post_meta')->alias(fn (int $id, string $key) => $this->meta[$id . '|' . $key] ?? '');
        Functions\when('metadata_exists')->alias(fn (string $t, int $id, string $key): bool => array_key_exists($id . '|' . $key, $this->meta));
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta[$id . '|' . $key] = is_string($value) ? stripslashes($value) : $value;
            return true;
        });
        Functions\when('delete_post_meta')->alias(function (int $id, string $key): bool {
            unset($this->meta[$id . '|' . $key]);
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
        Functions\when('wp_unslash')->alias(static fn ($v) => is_string($v) ? stripslashes($v) : $v);
        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim(strip_tags((string) $v)));
        Functions\when('sanitize_textarea_field')->alias(static fn ($v) => strip_tags((string) $v));
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploads]);
        Functions\when('wp_mkdir_p')->alias(static fn (string $d): bool => mkdir($d, 0777, true));
        Functions\when('get_posts')->justReturn([
            new \WP_Post(['ID' => 1, 'post_type' => 'book', 'post_name' => 'first']),
            new \WP_Post(['ID' => 2, 'post_type' => 'book', 'post_name' => 'second']),
        ]);

        new Metabox(['id' => 'book_intro', 'title' => 'Intro', 'screens' => ['book'], 'fields' => [
            ['id' => 'intro_heading', 'type' => 'text'],
            ['id' => 'intro_text', 'type' => 'textarea'],
            ['id' => 'intro_items', 'type' => 'repeater', 'fields' => [['id' => 'title', 'type' => 'text']]],
        ], 'defaults' => [
            'intro_heading' => 'Hello',
            'intro_text'    => 'Plans <strong>for you</strong>',
            'intro_items'   => [['title' => 'Say "hi"']],
        ]]);
        new OptionsPage(['id' => 'taw_footer', 'title' => 'Footer', 'fields' => [['id' => 'phone', 'type' => 'text']], 'defaults' => ['phone' => '555-0100']]);

        $this->meta['1|_taw_intro_heading'] = 'Already mine';
        $this->meta['2|_taw_intro_items'] = '[]'; // saved with no rows: still empty
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        OptionsPage::resetForTests();
        exec('rm -rf ' . escapeshellarg($this->uploads));
        parent::tearDown();
    }

    public function test_plan_lists_only_empty_fields_and_flags_what_saving_changes(): void
    {
        $plan = (new Defaults())->plan();

        $where = array_map(static fn (array $r): string => $r['label'] . ' ' . $r['field'], $plan['records']);
        $this->assertSame([
            'book · first intro_text', 'book · first intro_items',
            'book · second intro_heading', 'book · second intro_text', 'book · second intro_items',
            'Footer phone',
        ], $where, 'a field with a value is never listed');
        $this->assertSame([2, 1], [$plan['posts'], $plan['options']]);

        $text = $plan['records'][0];
        $this->assertTrue($text['altered'], 'a textarea drops the tags');
        $this->assertSame('Plans for you', $text['value']);
        $this->assertFalse($plan['records'][1]['altered']);
        $this->assertStringStartsWith('book · first · intro_text: saving changes', $plan['warnings'][0]);
    }

    public function test_apply_writes_a_journal_then_the_defaults(): void
    {
        $report = (new Defaults())->apply();

        $this->assertSame(6, $report['written']);
        $this->assertNull($report['error']);
        $this->assertFileExists((string) $report['journal']);
        $this->assertSame('Already mine', $this->meta['1|_taw_intro_heading']);
        $this->assertSame('Hello', $this->meta['2|_taw_intro_heading']);
        $this->assertSame([['title' => 'Say "hi"']], json_decode($this->meta['2|_taw_intro_items'], true), 'quotes survive the slash round trip');
        $this->assertSame('555-0100', $this->options['_taw_phone']);

        $this->assertSame([], (new Defaults())->plan()['records'], 'a second run has nothing to do');
    }

    public function test_undo_reverses_its_writes_and_keeps_later_edits(): void
    {
        $this->meta['2|_taw_intro_text'] = '';
        $journal = (string) (new Defaults())->apply()['journal'];
        $this->meta['2|_taw_intro_heading'] = 'Edited since';

        $result = (new Defaults())->undo(basename($journal));

        $this->assertSame(['restored' => 5, 'kept' => 1, 'error' => null], $result);
        $this->assertSame('Edited since', $this->meta['2|_taw_intro_heading']);
        $this->assertArrayNotHasKey('1|_taw_intro_text', $this->meta, 'absent before, absent again');
        $this->assertSame('', $this->meta['2|_taw_intro_text'], 'an empty row before stays an empty row');
        $this->assertArrayNotHasKey('_taw_phone', $this->options);
        $this->assertNull((new Defaults())->latestJournal(), 'an undone journal is spent');
        $this->assertNotNull((new Defaults())->undo('../../etc/passwd')['error']);
    }
}
