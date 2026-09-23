<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use TAW\Core\Schema\Field;
use TAW\Tests\TestCase;

/**
 * Field builders must produce exactly the arrays the Metabox engine already
 * accepts — the builder is sugar, never a second format (ADR-0004 § 2).
 */
final class FieldTest extends TestCase
{
    public function test_builder_output_equals_the_hand_written_array(): void
    {
        $built = Field::select('size')
            ->label('Size')
            ->description('Pick one')
            ->options(['s' => 'Small', 'l' => 'Large'])
            ->default('s')
            ->required()
            ->width(6)
            ->toArray();

        $this->assertSame([
            'id'          => 'size',
            'type'        => 'select',
            'label'       => 'Size',
            'description' => 'Pick one',
            'options'     => ['s' => 'Small', 'l' => 'Large'],
            'default'     => 's',
            'required'    => true,
            'width'       => 6,
        ], $built);
    }

    public function test_nested_fields_are_normalized_and_may_mix_builders_and_arrays(): void
    {
        $built = Field::repeater('awards')->fields([
            Field::text('name')->label('Name'),
            ['id' => 'year', 'type' => 'number'],
            Field::group('meta')->fields([Field::url('link')]),
        ])->toArray();

        $this->assertSame([
            'id'     => 'awards',
            'type'   => 'repeater',
            'fields' => [
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'year', 'type' => 'number'],
                ['id' => 'meta', 'type' => 'group', 'fields' => [['id' => 'link', 'type' => 'url']]],
            ],
        ], $built);
    }

    public function test_with_passes_unknown_keys_through_but_cannot_change_id_or_type(): void
    {
        $built = Field::text('title')
            ->with(['conditions' => [['field' => 'x', 'value' => '1']], 'id' => 'hacked', 'type' => 'wysiwyg'])
            ->toArray();

        $this->assertSame('title', $built['id']);
        $this->assertSame('text', $built['type']);
        $this->assertSame([['field' => 'x', 'value' => '1']], $built['conditions']);
    }

    public function test_every_shortcut_creates_its_own_type(): void
    {
        $this->assertSame('gradient_text', Field::gradientText('a')->toArray()['type']);
        $this->assertSame('hubspot_form', Field::hubspotForm('a')->toArray()['type']);
        $this->assertSame('post_select', Field::postSelect('a')->toArray()['type']);
        $this->assertSame('datepicker', Field::make('datepicker', 'a')->toArray()['type']);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown field type "slider"');

        Field::make('slider', 'volume');
    }

    public function test_invalid_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Field::text('has space');
    }

    public function test_only_group_and_repeater_accept_nested_fields(): void
    {
        $this->expectException(\LogicException::class);

        Field::text('title')->fields([Field::text('x')]);
    }

    public function test_a_non_field_entry_in_a_list_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Field::normalizeList(['not-a-field']);
    }
}
