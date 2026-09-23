<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TAW\Core\Schema\Field;
use TAW\Core\Schema\Validator;

/**
 * The JSON format's rules, and that every error points at the offending
 * spot (a JSON pointer) so it can be found in a long file.
 */
final class ValidatorTest extends TestCase
{
    public function test_every_valid_fixture_passes(): void
    {
        foreach (glob(__DIR__ . '/fixtures/valid/{,*/}*.json', GLOB_BRACE) ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $this->assertSame([], Validator::validate($data), basename($file));
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidDefinitions(): array
    {
        $fieldset = ['version' => 1, 'kind' => 'fieldset', 'key' => 'fs', 'on' => ['book'], 'fields' => [['id' => 'a', 'type' => 'text']]];

        return [
            'not an object'            => [['a', 'b'], '/: a definition must be a JSON object'],
            'wrong version'            => [['version' => 2, 'kind' => 'post_type', 'key' => 'book'], '/version: must be 1'],
            'unknown kind'             => [['version' => 1, 'kind' => 'block', 'key' => 'x'], '/kind: must be one of'],
            'missing key'              => [['version' => 1, 'kind' => 'post_type'], '/key: must be a non-empty string'],
            'typo in a top-level key'  => [['version' => 1, 'kind' => 'post_type', 'key' => 'book', 'lables' => []], '/lables: unknown key for a post_type'],
            'key from another kind'    => [['version' => 1, 'kind' => 'post_type', 'key' => 'book', 'fields' => []], '/fields: unknown key for a post_type'],
            'args not an object'       => [['version' => 1, 'kind' => 'post_type', 'key' => 'book', 'args' => ['x']], '/args: must be an object'],
            'labels without plural'    => [['version' => 1, 'kind' => 'post_type', 'key' => 'book', 'labels' => ['singular' => 'Book']], '/labels/plural: must be a non-empty string'],
            'override not boolean'     => [['version' => 1, 'kind' => 'post_type', 'key' => 'book', 'override' => 'yes'], '/override: must be true or false'],
            'taxonomy without for'     => [['version' => 1, 'kind' => 'taxonomy', 'key' => 'genre'], '/for: must be a non-empty list of strings'],
            'fieldset without on'      => [array_diff_key($fieldset, ['on' => 1]), '/on: must be a non-empty list of strings'],
            'fieldset without fields'  => [array_diff_key($fieldset, ['fields' => 1]), '/fields: must be a non-empty list of fields'],
            'bad context'              => [$fieldset + ['context' => 'top'], '/context: must be one of normal, side, advanced'],
            'unknown field type'       => [array_replace($fieldset, ['fields' => [['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => 'slider']]]), '/fields/1/type: must be one of'],
            'bad field id'             => [array_replace($fieldset, ['fields' => [['id' => 'has space', 'type' => 'text']]]), '/fields/0/id: must use letters'],
            'duplicate field id'       => [array_replace($fieldset, ['fields' => [['id' => 'a', 'type' => 'text'], ['id' => 'a', 'type' => 'url']]]), '/fields/1/id: "a" is used twice'],
            'field label not a string' => [array_replace($fieldset, ['fields' => [['id' => 'a', 'type' => 'text', 'label' => 5]]]), '/fields/0/label: must be a string'],
            'required not boolean'     => [array_replace($fieldset, ['fields' => [['id' => 'a', 'type' => 'text', 'required' => 'yes']]]), '/fields/0/required: must be true or false'],
            'options not an object'    => [array_replace($fieldset, ['fields' => [['id' => 'a', 'type' => 'select', 'options' => ['x', 'y']]]]), '/fields/0/options: must be an object'],
            'repeater without fields'  => [array_replace($fieldset, ['fields' => [['id' => 'r', 'type' => 'repeater']]]), '/fields/0/fields: must be a non-empty list'],
            'nested error pointer'     => [array_replace($fieldset, ['fields' => [['id' => 'r', 'type' => 'repeater', 'fields' => [['id' => 'x', 'type' => 'nope']]]]]), '/fields/0/fields/0/type: must be one of'],
            'fields on a text field'   => [array_replace($fieldset, ['fields' => [['id' => 'a', 'type' => 'text', 'fields' => []]]]), '/fields/0/fields: only group and repeater'],
            'options page no fields'   => [['version' => 1, 'kind' => 'options_page', 'key' => 'site'], '/fields: must be a non-empty list of fields'],
        ];
    }

    #[DataProvider('invalidDefinitions')]
    public function test_invalid_definitions_report_a_pointed_error(mixed $data, string $expected): void
    {
        $errors = Validator::validate($data);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString($expected, implode("\n", $errors));
    }

    public function test_unknown_field_keys_pass_through_like_with(): void
    {
        // conditions, width, layout, editor… are Metabox options the format
        // doesn't enumerate; they must reach the engine untouched.
        $errors = Validator::validate([
            'version' => 1, 'kind' => 'fieldset', 'key' => 'fs', 'on' => ['book'],
            'fields'  => [['id' => 'a', 'type' => 'text', 'width' => 6, 'conditions' => [['field' => 'b', 'value' => '1']]]],
        ]);

        $this->assertSame([], $errors);
    }

    public function test_an_empty_json_object_counts_as_an_object(): void
    {
        // json_decode('{}', true) is [] — "args": {} must not be rejected.
        $this->assertSame([], Validator::validate(['version' => 1, 'kind' => 'post_type', 'key' => 'book', 'args' => []]));
    }

    public function test_field_types_are_the_metabox_types(): void
    {
        $this->assertSame(Field::TYPES, Validator::FIELD_TYPES);
    }
}
