<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * Metabox::sanitizeRepeaterRows() (REST field, data panel, visual editor,
 * fields:set, Content\Importer) must store what the metabox form's own save
 * stores for the same rows.
 */
final class RepeaterRowsSanitizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_json_encode')->alias(static fn (mixed $data, int $flags = 0): string|false => json_encode($data, $flags));
        Functions\when('wp_unslash')->alias(static fn (mixed $value): mixed => is_string($value) ? stripslashes($value) : $value);
        Functions\when('sanitize_text_field')->alias(static fn (mixed $value): string => is_array($value) ? '' : trim(strip_tags((string) $value)));
        Functions\when('absint')->alias(static fn (mixed $value): int => abs((int) $value));
        Functions\when('wp_kses_post')->returnArg();
    }

    private const CONFIG = [
        'max'    => 3,
        'fields' => [
            ['id' => 'title', 'type' => 'text'],
            ['id' => 'cover', 'type' => 'image'],
            ['id' => 'gallery', 'type' => 'files'],
            ['id' => 'related', 'type' => 'post_select', 'multiple' => true],
            ['id' => 'headline', 'type' => 'gradient_text'],
            ['id' => 'tags', 'type' => 'repeater', 'fields' => [['id' => 'tag', 'type' => 'text']]],
        ],
    ];

    public function test_structured_sub_fields_keep_their_values(): void
    {
        $json = Metabox::sanitizeRepeaterRows(self::CONFIG, [[
            'title'    => ' Dune ',
            'cover'    => '37',
            'gallery'  => [37, '49', 0],
            'related'  => [5, 6],
            'headline' => [['text' => 'Read ', 'highlighted' => false], ['text' => 'more', 'highlighted' => true]],
            'tags'     => [['tag' => 'sf'], ['tag' => '<b>classic</b>']],
        ]]);

        $this->assertSame([[
            'title'    => 'Dune',
            'cover'    => 37,
            'gallery'  => '[37,49]',
            'related'  => '[5,6]',
            'headline' => '[{"text":"Read ","highlighted":false},{"text":"more","highlighted":true}]',
            'tags'     => [['tag' => 'sf'], ['tag' => 'classic']],
        ]], json_decode($json, true));
    }

    public function test_nested_rows_arrive_decoded_or_as_json_and_are_stored_as_arrays(): void
    {
        $fromJson  = Metabox::sanitizeRepeaterRows(self::CONFIG, '[{"title":"a","tags":[{"tag":"x"}]}]');
        $fromArray = Metabox::sanitizeRepeaterRows(self::CONFIG, [['title' => 'a', 'tags' => '[{"tag":"x"}]']]);

        $expected = [['title' => 'a', 'tags' => [['tag' => 'x']]]];
        $this->assertSame($expected, json_decode($fromJson, true));
        $this->assertSame($expected, json_decode($fromArray, true));
        $this->assertStringNotContainsString('\\"', $fromArray, 'no double-encoded JSON');
    }

    public function test_max_rows_and_empty_rows(): void
    {
        $rows = [['title' => 'a'], ['title' => ''], ['title' => 'b', 'tags' => []], ['title' => 'c'], ['title' => 'd']];

        $this->assertSame(
            [['title' => 'a'], ['title' => 'b', 'tags' => []]],
            json_decode(Metabox::sanitizeRepeaterRows(self::CONFIG, $rows), true),
            'the first 3 rows (max), minus the one with no content'
        );
    }
}
