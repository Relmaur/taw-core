<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use PHPUnit\Framework\TestCase;
use TAW\Core\Content\FieldKeys;

/**
 * Content interchange 1.2 field keys (ADR-0008 decision 3): `_taw_` fields
 * keep their bare id, other prefixes use the full meta key, and 1.0/1.1
 * keys still resolve.
 */
final class FieldKeysTest extends TestCase
{
    /** @return array<string, array<string, mixed>> meta key → config, as Metabox::fieldsFor() returns it */
    private function registered(): array
    {
        return [
            '_taw_subtitle' => ['id' => 'subtitle', 'type' => 'text', 'prefix' => '_taw_', 'field_key' => 'subtitle', 'meta_key' => '_taw_subtitle'],
            '_taw_cta_text' => ['id' => 'text', 'type' => 'text', 'prefix' => '_taw_', 'field_key' => 'cta_text', 'meta_key' => '_taw_cta_text'],
            '_book_author'  => ['id' => 'author', 'type' => 'text', 'prefix' => '_book_', 'field_key' => 'author', 'meta_key' => '_book_author'],
        ];
    }

    private function bare(?array $config = null): callable
    {
        return static fn (string $id): ?array => $config;
    }

    public function test_export_keys_taw_fields_by_bare_id_and_others_by_meta_key(): void
    {
        $this->assertSame('subtitle', FieldKeys::forMetaKey('_taw_subtitle', $this->registered(), $this->bare())['key']);
        $this->assertSame('cta_text', FieldKeys::forMetaKey('_taw_cta_text', $this->registered(), $this->bare())['key']);
        $this->assertSame('_book_author', FieldKeys::forMetaKey('_book_author', $this->registered(), $this->bare())['key']);
    }

    public function test_export_keeps_unregistered_taw_meta_and_skips_everything_else(): void
    {
        $orphan = FieldKeys::forMetaKey('_taw_old_field', $this->registered(), $this->bare());
        $this->assertSame('old_field', $orphan['key']);
        $this->assertSame('text', $orphan['config']['type']);

        $this->assertSame('image', FieldKeys::forMetaKey('_taw_logo', [], $this->bare(['id' => 'logo', 'type' => 'image', 'prefix' => '_taw_']))['config']['type'], 'bare registry type, as before');
        $this->assertNull(FieldKeys::forMetaKey('_edit_lock', $this->registered(), $this->bare()));
        $this->assertNull(FieldKeys::forMetaKey('_book_unknown', $this->registered(), $this->bare()));
    }

    public function test_import_resolves_both_key_forms_to_meta_keys(): void
    {
        $this->assertSame('_taw_subtitle', FieldKeys::forKey('subtitle', $this->registered(), $this->bare())['meta_key']);
        $this->assertSame('_taw_cta_text', FieldKeys::forKey('cta_text', $this->registered(), $this->bare())['meta_key']);
        $this->assertSame('_book_author', FieldKeys::forKey('_book_author', $this->registered(), $this->bare())['meta_key']);
    }

    public function test_import_of_a_bare_id_nothing_registers_falls_back_to_taw(): void
    {
        $target = FieldKeys::forKey('old_field', $this->registered(), $this->bare());
        $this->assertSame('_taw_old_field', $target['meta_key']);
        $this->assertSame('text', $target['config']['type']);

        $other = FieldKeys::forKey('author', $this->registered(), $this->bare(['id' => 'author', 'type' => 'number', 'prefix' => '_book_']));
        $this->assertSame('_taw_author', $other['meta_key'], 'a bare key is always a _taw_ field');
        $this->assertSame('text', $other['config']['type'], "another prefix's bare config doesn't apply to _taw_ meta");
    }

    public function test_a_taw_bare_id_wins_over_a_meta_key_spelled_the_same(): void
    {
        $registered = [
            'acme_x'      => ['id' => 'x', 'type' => 'number', 'prefix' => 'acme_', 'field_key' => 'x', 'meta_key' => 'acme_x'],
            '_taw_acme_x' => ['id' => 'acme_x', 'type' => 'text', 'prefix' => '_taw_', 'field_key' => 'acme_x', 'meta_key' => '_taw_acme_x'],
        ];

        $this->assertSame('_taw_acme_x', FieldKeys::forKey('acme_x', $registered, $this->bare())['meta_key']);
    }
}
