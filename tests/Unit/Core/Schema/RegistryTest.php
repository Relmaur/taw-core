<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use TAW\Core\Schema\Registry;
use TAW\Core\Schema\Schema;
use TAW\Core\Schema\Source;

/**
 * Collection, precedence and freezing (ADR-0004 §§ 1, 3, 6).
 */
final class RegistryTest extends SchemaTestCase
{
    public function test_definitions_are_stored_by_qualified_key_and_grouped_by_kind(): void
    {
        $registry = Registry::instance();
        $registry->add(Schema::postType('book'));
        $registry->add(Schema::taxonomy('genre')->for('book'));
        $registry->add(Schema::fieldset('book')->on('book')->fields([['id' => 'x', 'type' => 'text']]));

        $this->assertSame('book', $registry->get('post_type:book')?->key());
        $this->assertCount(1, $registry->postTypes());
        $this->assertCount(1, $registry->taxonomies());
        $this->assertCount(1, $registry->fieldsets());
        $this->assertSame('php', $registry->sourceOf('post_type:book')?->describe());
        $this->assertSame([], $this->notices);
    }

    public function test_an_invalid_definition_is_refused_with_a_notice(): void
    {
        $added = Registry::instance()->add(Schema::taxonomy('genre'));

        $this->assertFalse($added);
        $this->assertNull(Registry::instance()->get('taxonomy:genre'));
        $this->assertStringContainsString('not attached to any post type', $this->notices[0]);
    }

    public function test_adding_after_freeze_is_refused_with_a_notice(): void
    {
        $registry = Registry::instance();
        $registry->freeze();

        $this->assertFalse($registry->add(Schema::postType('book')));
        $this->assertSame([], $registry->postTypes());
        $this->assertStringContainsString('after the schema registry froze', $this->notices[0]);
    }

    public function test_higher_rank_wins_regardless_of_arrival_order(): void
    {
        $registry = Registry::instance();
        $json = Source::json('/theme/taw-schema/book.json', Source::RANK_PARENT_THEME);

        // PHP first, then the lower-ranked JSON: JSON is refused.
        $registry->add(Schema::postType('book')->args(['has_archive' => 'php'])->override());
        $this->assertFalse($registry->add(Schema::postType('book')->args(['has_archive' => 'json']), $json));
        $this->assertSame('php', $registry->postTypes()[0]->toArray()['has_archive']);

        // JSON first, then PHP: PHP replaces it.
        Registry::resetForTests();
        $registry = Registry::instance();
        $registry->add(Schema::postType('book')->args(['has_archive' => 'json']), $json);
        $this->assertTrue($registry->add(Schema::postType('book')->args(['has_archive' => 'php'])->override()));
        $this->assertSame('php', $registry->postTypes()[0]->toArray()['has_archive']);
        $this->assertSame([], $this->notices, 'an override() winner must not trigger a notice');
    }

    public function test_an_undeclared_replacement_is_reported_naming_both_sources(): void
    {
        $registry = Registry::instance();
        $registry->add(Schema::postType('book'), Source::json('/child/taw-schema/book.json', Source::RANK_CHILD_THEME));
        $registry->add(Schema::postType('book'));

        $this->assertCount(1, $this->notices);
        $this->assertStringContainsString('json:/child/taw-schema/book.json', $this->notices[0]);
        $this->assertStringContainsString('defined twice', $this->notices[0]);
    }

    public function test_equal_rank_duplicates_keep_the_later_one(): void
    {
        $registry = Registry::instance();
        $registry->add(Schema::postType('book')->args(['menu_position' => 1]));
        $registry->add(Schema::postType('book')->args(['menu_position' => 2]));

        $this->assertSame(2, $registry->postTypes()[0]->toArray()['menu_position']);
        $this->assertCount(1, $this->notices);
    }

    public function test_same_key_in_different_kinds_does_not_clash(): void
    {
        $registry = Registry::instance();
        $registry->add(Schema::postType('book'));
        $registry->add(Schema::fieldset('book')->on('book')->fields([['id' => 'x', 'type' => 'text']]));

        $this->assertCount(1, $registry->postTypes());
        $this->assertCount(1, $registry->fieldsets());
        $this->assertSame([], $this->notices);
    }
}
