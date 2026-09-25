<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use TAW\Core\Schema\Field;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

/**
 * The four definition kinds: key validation, defaults, and the exact arrays
 * they hand to WordPress / the Metabox and OptionsPage engines.
 */
final class DefinitionTest extends TestCase
{
    // ── Post types ────────────────────────────────────────────────────────

    public function test_post_type_defaults_make_it_reachable_by_the_block_editor_and_rest(): void
    {
        $args = Schema::postType('book')->toArray();

        $this->assertTrue($args['public']);
        $this->assertTrue($args['show_in_rest']);
        $this->assertSame(['title', 'editor', 'thumbnail', 'custom-fields'], $args['supports']);
    }

    public function test_custom_fields_support_is_always_added_even_when_supports_is_overridden(): void
    {
        // Without it WordPress hides registered meta from REST, and every TAW
        // field on the post type silently disappears from wp/v2.
        $this->assertSame(['title', 'custom-fields'], Schema::postType('book')->args(['supports' => ['title']])->toArray()['supports']);
        $this->assertSame(['custom-fields'], Schema::postType('book')->args(['supports' => false])->toArray()['supports']);
    }

    public function test_args_override_defaults(): void
    {
        $args = Schema::postType('book')->args(['public' => false, 'has_archive' => true])->toArray();

        $this->assertFalse($args['public']);
        $this->assertTrue($args['has_archive']);
    }

    public function test_labels_are_generated_and_explicit_labels_win(): void
    {
        $args = Schema::postType('book')
            ->labels('Book', 'Books')
            ->args(['labels' => ['add_new_item' => 'Add a book']])
            ->toArray();

        $this->assertSame('Books', $args['labels']['name']);
        $this->assertSame('Book', $args['labels']['singular_name']);
        $this->assertSame('Edit Book', $args['labels']['edit_item']);
        $this->assertSame('Add a book', $args['labels']['add_new_item']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPostTypeKeys(): array
    {
        return [
            'too long (WP limit 20)' => ['a_very_long_post_type_key'],
            'uppercase'              => ['Book'],
            'reserved by WordPress'  => ['post'],
            'reserved block type'    => ['wp_block'],
            'empty'                  => [''],
        ];
    }

    #[DataProvider('invalidPostTypeKeys')]
    public function test_invalid_post_type_keys_are_rejected(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Schema::postType($key);
    }

    // ── Taxonomies ────────────────────────────────────────────────────────

    public function test_taxonomy_compiles_object_types_and_rest_on_by_default(): void
    {
        $definition = Schema::taxonomy('genre')->for('book', 'movie')->for('book')->labels('Genre', 'Genres')->toArray();

        $this->assertSame(['book', 'movie'], $definition['object_type']);
        $this->assertTrue($definition['args']['show_in_rest']);
        $this->assertSame('Genres', $definition['args']['labels']['name']);
    }

    public function test_taxonomy_without_post_types_reports_a_problem(): void
    {
        $this->assertNotSame([], Schema::taxonomy('genre')->problems());
        $this->assertSame([], Schema::taxonomy('genre')->for('book')->problems());
    }

    public function test_taxonomy_named_like_a_public_query_var_is_rejected(): void
    {
        // A taxonomy called "year" would hijack ?year= archive queries.
        $this->expectException(\InvalidArgumentException::class);

        Schema::taxonomy('year');
    }

    // ── Fieldsets ─────────────────────────────────────────────────────────

    public function test_fieldset_compiles_to_the_exact_metabox_config(): void
    {
        $config = Schema::fieldset('book_details')
            ->title('Book details')
            ->on('book')
            ->context('side')
            ->prefix('_lib_')
            ->with(['icon' => 'dashicons-book', 'id' => 'ignored'])
            ->fields([Field::text('subtitle')->label('Subtitle'), ['id' => 'pages', 'type' => 'number']])
            ->toArray();

        $this->assertSame([
            'context' => 'side',
            'prefix'  => '_lib_',
            'icon'    => 'dashicons-book',
            'id'      => 'book_details',
            'title'   => 'Book details',
            'screens' => ['book'],
            'fields'  => [
                ['id' => 'subtitle', 'type' => 'text', 'label' => 'Subtitle'],
                ['id' => 'pages', 'type' => 'number'],
            ],
        ], $config);
    }

    public function test_fieldset_without_location_or_fields_reports_problems(): void
    {
        // Metabox would silently default to 'page'; the schema refuses instead.
        $this->assertCount(2, Schema::fieldset('empty')->problems());
    }

    public function test_registry_field_ids_include_group_sub_fields_like_metabox_does(): void
    {
        $ids = Schema::fieldset('fs')->on('book')->fields([
            Field::text('subtitle'),
            Field::group('address')->fields([Field::text('city'), Field::text('zip')]),
            Field::repeater('awards')->fields([Field::text('name')]),
        ])->registryFieldIds();

        // Repeater sub-fields are not registered by Metabox, so not listed.
        $this->assertSame(['subtitle', 'address', 'address_city', 'address_zip', 'awards'], $ids);
    }

    // ── Options pages ─────────────────────────────────────────────────────

    public function test_options_page_compiles_to_the_exact_options_page_config(): void
    {
        $config = Schema::optionsPage('site')
            ->title('Site settings')
            ->menuTitle('Site')
            ->capability('edit_theme_options')
            ->fields([Field::text('company_phone')])
            ->toArray();

        $this->assertSame([
            'menu_title' => 'Site',
            'capability' => 'edit_theme_options',
            'id'         => 'site',
            'title'      => 'Site settings',
            'fields'     => [['id' => 'company_phone', 'type' => 'text']],
        ], $config);
    }

    public function test_qualified_keys_keep_kinds_apart(): void
    {
        $this->assertSame('post_type:book', Schema::postType('book')->qualifiedKey());
        $this->assertSame('fieldset:book', Schema::fieldset('book')->qualifiedKey());
    }

    public function test_fieldsets_take_term_targets_mixed_with_post_types(): void
    {
        $fieldset = Schema::fieldset('genre_details')->on('book', 'term:genre');

        $this->assertSame(['book', 'term:genre'], $fieldset->screens());
        $this->assertSame(['genre'], $fieldset->taxonomies());

        $this->expectException(\InvalidArgumentException::class);
        Schema::fieldset('bad')->on('term:Not A Taxonomy');
    }
}
