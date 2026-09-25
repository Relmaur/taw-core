<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\CollisionReport;
use TAW\Core\Schema\Field;
use TAW\Core\Schema\Schema;

/**
 * Bare-id collisions in Metabox's field registry, in every direction the
 * schema can cause or suffer one (ADR-0004 § 7).
 */
final class CollisionReportTest extends SchemaTestCase
{
    public function test_schema_field_replacing_an_earlier_legacy_metabox_is_detected(): void
    {
        new Metabox(['id' => 'legacy_box', 'title' => 'Legacy', 'screens' => ['page'], 'fields' => [['id' => 'subtitle', 'type' => 'text']]]);

        CollisionReport::checkBeforeCompile(Schema::fieldset('book_details')->on('book')->fields([Field::text('subtitle')]));

        $this->assertCount(1, CollisionReport::collisions());
        $this->assertStringContainsString('shares its id with metabox "legacy_box"', CollisionReport::collisions()[0]);
    }

    public function test_legacy_metabox_registered_later_overwriting_a_schema_field_is_detected(): void
    {
        $fieldset = Schema::fieldset('book_details')->on('book')->fields([Field::text('subtitle')]);
        CollisionReport::checkBeforeCompile($fieldset);
        new Metabox($fieldset->toArray());

        // e.g. a MetaBlock's metabox at init:10, after the schema compiled at init:8.
        new Metabox(['id' => 'hero', 'title' => 'Hero', 'screens' => ['page'], 'fields' => [['id' => 'subtitle', 'type' => 'text']]]);
        CollisionReport::checkAfterAllMetaboxes();

        $this->assertCount(1, CollisionReport::collisions());
        $this->assertStringContainsString('Metabox "hero" (registered after the schema)', CollisionReport::collisions()[0]);
    }

    public function test_group_sub_field_compound_ids_are_checked_too(): void
    {
        new Metabox(['id' => 'legacy_box', 'title' => 'Legacy', 'screens' => ['page'], 'fields' => [['id' => 'address_city', 'type' => 'text']]]);

        CollisionReport::checkBeforeCompile(
            Schema::fieldset('contact')->on('page')->fields([Field::group('address')->fields([Field::text('city')])])
        );

        $this->assertCount(1, CollisionReport::collisions());
    }

    public function test_distinct_ids_and_a_fieldset_recompiling_itself_are_not_collisions(): void
    {
        new Metabox(['id' => 'legacy_box', 'title' => 'Legacy', 'screens' => ['page'], 'fields' => [['id' => 'hero_title', 'type' => 'text']]]);
        $fieldset = Schema::fieldset('book_details')->on('book')->fields([Field::text('subtitle')]);

        CollisionReport::checkBeforeCompile($fieldset);
        new Metabox($fieldset->toArray());
        CollisionReport::checkAfterAllMetaboxes();

        $this->assertSame([], CollisionReport::collisions());
    }

    public function test_two_legacy_metaboxes_colliding_are_out_of_scope(): void
    {
        // Existing taw-theme sites must see no new runtime output (ADR-0004 § 7);
        // legacy-vs-legacy is reported by bin/taw schema:validate instead.
        new Metabox(['id' => 'a', 'title' => 'A', 'screens' => ['page'], 'fields' => [['id' => 'title', 'type' => 'text']]]);
        new Metabox(['id' => 'b', 'title' => 'B', 'screens' => ['page'], 'fields' => [['id' => 'title', 'type' => 'text']]]);

        CollisionReport::checkAfterAllMetaboxes();
        CollisionReport::report();

        $this->assertSame([], CollisionReport::collisions());
        $this->assertSame([], $this->notices);
    }
}
