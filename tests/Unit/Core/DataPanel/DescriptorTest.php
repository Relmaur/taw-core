<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\DataPanel;

use Brain\Monkey\Functions;
use TAW\Core\DataPanel\Descriptor;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\Field;
use TAW\Tests\TestCase;

final class DescriptorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['page', 'post', 'book'], true));
        Metabox::forgetInstances();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $extra
     */
    private function box(array $fields, array $extra = []): Metabox
    {
        return new Metabox(array_replace(['id' => 'book_details', 'title' => 'Book details', 'screens' => ['book'], 'fields' => $fields], $extra));
    }

    public function test_every_field_type_is_supported(): void
    {
        $fields = array_map(static fn (string $type): array => ['id' => "f_{$type}", 'type' => $type], Field::TYPES);

        $this->assertTrue(Descriptor::supports($this->box($fields)));
        $this->assertCount(count(Field::TYPES), Descriptor::fieldset($this->box($fields))['fields'] ?? []);
    }

    public function test_an_unknown_type_anywhere_keeps_the_whole_fieldset_a_metabox(): void
    {
        $this->assertFalse(Descriptor::supports($this->box([['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => 'map']])));
        $this->assertFalse(Descriptor::supports($this->box([['id' => 'rows', 'type' => 'repeater', 'fields' => [['id' => 'x', 'type' => 'map']]]])));
        $this->assertNull(Descriptor::fieldset($this->box([['id' => 'b', 'type' => 'map']])));
    }

    public function test_a_group_inside_a_repeater_keeps_the_fieldset_a_metabox(): void
    {
        $group = ['id' => 'size', 'type' => 'group', 'fields' => [['id' => 'w', 'type' => 'number']]];

        $this->assertFalse(Descriptor::supports($this->box([['id' => 'rows', 'type' => 'repeater', 'fields' => [$group]]])));
        $this->assertFalse(Descriptor::supports($this->box([['id' => 'rows', 'type' => 'repeater', 'fields' => [
            ['id' => 'inner', 'type' => 'repeater', 'fields' => [$group]],
        ]]])));
        $this->assertTrue(Descriptor::supports($this->box([$group, ['id' => 'rows', 'type' => 'repeater', 'fields' => [
            ['id' => 'inner', 'type' => 'repeater', 'fields' => [['id' => 'x', 'type' => 'text']]],
        ]]])), 'top-level groups and nested repeaters are fine');
    }

    public function test_the_fieldset_shape(): void
    {
        $descriptor = Descriptor::fieldset($this->box(
            [['id' => 'book_author', 'type' => 'text', 'label' => 'Author']],
            ['icon' => 'dashicons-book', 'tabs' => [['label' => 'Main', 'fields' => ['book_author']]], 'screens' => ['book', 'page-about.php']]
        ));

        $this->assertSame('book_details', $descriptor['id']);
        $this->assertSame('Book details', $descriptor['title']);
        $this->assertSame('dashicons-book', $descriptor['icon']);
        $this->assertSame(['page-about.php'], $descriptor['templates']);
        $this->assertSame([['label' => 'Main', 'icon' => '', 'fields' => ['book_author']]], $descriptor['tabs']);
    }

    public function test_bindings_say_where_each_value_lives(): void
    {
        $fields = Descriptor::fieldset($this->box([
            ['id' => 'book_author', 'type' => 'text'],
            ['id' => 'book_links', 'type' => 'repeater', 'fields' => [['id' => 'url', 'type' => 'url']]],
            ['id' => 'book_files', 'type' => 'files'],
            ['id' => 'book_related', 'type' => 'post_select'],
            ['id' => 'book_meta', 'type' => 'group', 'fields' => [['id' => 'isbn', 'type' => 'text']]],
        ], ['prefix' => '_acme_']))['fields'];

        $this->assertSame(['meta' => '_acme_book_author'], $fields[0]['binding']);
        $this->assertSame(['field' => 'taw_book_links'], $fields[1]['binding']);
        $this->assertArrayNotHasKey('binding', $fields[1]['fields'][0], 'repeater sub-fields are row keys');
        $this->assertSame(['field' => 'taw_book_files'], $fields[2]['binding']);
        $this->assertSame(['field' => 'taw_book_related'], $fields[3]['binding']);
        $this->assertArrayNotHasKey('binding', $fields[4], 'a group stores nothing itself');
        $this->assertSame(['meta' => '_acme_book_meta_isbn'], $fields[4]['fields'][0]['binding']);
    }

    public function test_options_pass_through_and_php_callables_never_do(): void
    {
        $field = Descriptor::field([
            'id'         => 'genre',
            'type'       => 'select',
            'label'      => 'Genre',
            'required'   => true,
            'options'    => ['fiction' => 'Fiction', 'poetry' => 'Poetry'],
            'default'    => 'fiction',
            'validate'   => static fn (): bool => true,
            'sanitize'   => 'sanitize_text_field',
            'show_on'    => static fn (): bool => true,
            'conditions' => [['id' => 'kind', 'value' => 'book'], ['field' => 'x', 'operator' => '!empty', 'value' => static fn () => 1]],
        ], '_taw_');

        $this->assertSame('Genre', $field['label']);
        $this->assertTrue($field['required']);
        $this->assertSame(['fiction' => 'Fiction', 'poetry' => 'Poetry'], $field['options']);
        $this->assertSame('fiction', $field['default']);
        $this->assertTrue($field['validated']);
        $this->assertArrayNotHasKey('validate', $field);
        $this->assertArrayNotHasKey('sanitize', $field);
        $this->assertArrayNotHasKey('show_on', $field);
        $this->assertSame([
            ['field' => 'kind', 'operator' => '==', 'value' => 'book'],
            ['field' => 'x', 'operator' => '!empty', 'value' => null],
        ], $field['conditions']);
        $this->assertNotFalse(json_encode($field));
    }
}
