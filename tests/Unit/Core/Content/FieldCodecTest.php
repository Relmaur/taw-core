<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use TAW\Core\Content\FieldCodec;
use TAW\Tests\TestCase;

/**
 * FieldCodec is the pure decode / attachment-reference layer between a
 * stored `_taw_*` value and the portable snapshot shape. No WordPress
 * calls, so every case here is a straight input → output assertion.
 */
final class FieldCodecTest extends TestCase
{
    public function test_decodes_repeater_json_to_rows(): void
    {
        $config = ['type' => 'repeater', 'fields' => [
            ['id' => 'name', 'type' => 'text'],
            ['id' => 'featured', 'type' => 'checkbox'],
        ]];

        $decoded = FieldCodec::decode($config, '[{"name":"Ada","featured":"1"},{"name":"Bo","featured":"0"}]');

        $this->assertSame([
            ['name' => 'Ada', 'featured' => true],
            ['name' => 'Bo', 'featured' => false],
        ], $decoded);
    }

    public function test_decodes_files_to_int_list_and_checkbox_to_bool_and_image_to_int(): void
    {
        $this->assertSame([12, 15], FieldCodec::decode(['type' => 'files'], '[12,"15"]'));
        $this->assertTrue(FieldCodec::decode(['type' => 'checkbox'], '1'));
        $this->assertFalse(FieldCodec::decode(['type' => 'checkbox'], ''));
        $this->assertSame(42, FieldCodec::decode(['type' => 'image'], '42'));
    }

    public function test_decodes_post_select_by_multiple_flag(): void
    {
        $this->assertSame(7, FieldCodec::decode(['type' => 'post_select'], '7'));
        $this->assertSame([7, 8], FieldCodec::decode(['type' => 'post_select', 'multiple' => true], '[7,8]'));
        $this->assertNull(FieldCodec::decode(['type' => 'post_select'], ''));
    }

    public function test_scalar_values_pass_through_untouched(): void
    {
        $this->assertSame('Hello "world"', FieldCodec::decode(['type' => 'text'], 'Hello "world"'));
        $this->assertSame('', FieldCodec::decode(['type' => 'text'], false));
    }

    public function test_collects_referenced_attachment_ids_including_nested_repeater(): void
    {
        $config = ['type' => 'repeater', 'fields' => [
            ['id' => 'photo', 'type' => 'image'],
            ['id' => 'docs', 'type' => 'files'],
        ]];
        $decoded = [
            ['photo' => 5, 'docs' => [9, 10]],
            ['photo' => 0, 'docs' => []],
        ];

        $ids = FieldCodec::referencedAttachmentIds($config, $decoded);

        sort($ids);
        $this->assertSame([5, 9, 10], $ids);
    }

    public function test_rewrites_attachment_ids_in_image_files_and_repeater(): void
    {
        $map = [5 => 105, 9 => 109];

        $this->assertSame(105, FieldCodec::rewriteAttachmentIds(['type' => 'image'], 5, $map));
        $this->assertSame(7, FieldCodec::rewriteAttachmentIds(['type' => 'image'], 7, $map)); // absent → unchanged
        $this->assertSame([105, 109, 3], FieldCodec::rewriteAttachmentIds(['type' => 'files'], [5, 9, 3], $map));

        $repeater = ['type' => 'repeater', 'fields' => [['id' => 'photo', 'type' => 'image']]];
        $this->assertSame(
            [['photo' => 105], ['photo' => 2]],
            FieldCodec::rewriteAttachmentIds($repeater, [['photo' => 5], ['photo' => 2]], $map)
        );
    }
}
