<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Core\Rest\FieldMetaRegistrar;
use TAW\Core\Schema\CollisionReport;
use TAW\Core\Schema\Compiler;
use TAW\Core\Schema\Field;
use TAW\Core\Schema\Registry;
use TAW\Core\Schema\Schema;

/**
 * From registry to real WordPress registrations, at the right init
 * priorities (ADR-0004 § 3), reusing the existing Metabox / OptionsPage /
 * FieldMetaRegistrar engines (§ 2).
 */
final class CompilerTest extends SchemaTestCase
{
    public function test_register_hooks_every_step_at_its_init_priority(): void
    {
        Compiler::register();

        $this->assertSame(1, has_action('init', [Compiler::class, 'collect']));
        $this->assertSame(5, has_action('init', [Compiler::class, 'registerPostTypes']));
        $this->assertSame(6, has_action('init', [Compiler::class, 'registerTaxonomies']));
        $this->assertSame(8, has_action('init', [Compiler::class, 'compileFields']));
        $this->assertSame(15, has_action('init', [Compiler::class, 'reportCollisions']));
        $this->assertSame(99, has_action('init', [Compiler::class, 'maybeFlushRewriteRules']));
    }

    public function test_collect_hands_the_registry_to_taw_schema_register(): void
    {
        Actions\expectDone('taw_schema_register')->once()->with(Registry::instance());

        Compiler::collect();
    }

    public function test_post_types_register_after_freezing(): void
    {
        Registry::instance()->add(Schema::postType('book')->labels('Book', 'Books'));

        Functions\expect('register_post_type')
            ->once()
            ->andReturnUsing(function (string $key, array $args): void {
                $this->assertSame('book', $key);
                $this->assertSame('Books', $args['labels']['name']);
                $this->assertContains('custom-fields', $args['supports']);
            });

        Compiler::registerPostTypes();

        $this->assertTrue(Registry::instance()->isFrozen());
    }

    public function test_taxonomies_register_with_their_object_types(): void
    {
        Registry::instance()->add(Schema::taxonomy('genre')->for('book'));

        Functions\expect('register_taxonomy')
            ->once()
            ->with('genre', ['book'], \Mockery::on(fn (array $args): bool => $args['show_in_rest'] === true));

        Compiler::registerTaxonomies();
    }

    public function test_fieldsets_become_metaboxes_in_the_existing_field_registry(): void
    {
        Registry::instance()->add(
            Schema::fieldset('book_details')->title('Book details')->on('book')->fields([
                Field::text('subtitle')->label('Subtitle'),
                Field::group('publisher')->fields([Field::text('name')]),
            ])
        );

        Compiler::compileFields();

        $subtitle = Metabox::get_field_config('subtitle');
        $this->assertNotNull($subtitle);
        $this->assertSame('book_details', $subtitle['metabox_id']);
        $this->assertSame('_taw_', $subtitle['prefix']);
        $this->assertSame(['book'], $subtitle['screens']);
        $this->assertSame('publisher', Metabox::get_field_config('publisher_name')['parent_group'] ?? null);
        // The Metabox hooks itself into the edit screen, as a hand-written one does.
        $this->assertTrue(has_action('add_meta_boxes'));
        $this->assertTrue(has_action('save_post'));
    }

    public function test_options_pages_become_options_pages(): void
    {
        Registry::instance()->add(Schema::optionsPage('site')->fields([Field::text('company_phone')]));

        Compiler::compileFields();

        $this->assertSame('site', OptionsPage::getFieldConfig('company_phone')['option_page'] ?? null);
        $this->assertTrue(has_action('admin_menu'));
    }

    public function test_a_custom_prefix_reaches_the_rest_meta_key(): void
    {
        // End to end through the existing REST registrar: schema prefix →
        // Metabox registry → register_post_meta() key.
        Registry::instance()->add(
            Schema::fieldset('book_details')->on('book')->prefix('_lib_')->fields([Field::text('subtitle')])
        );
        Compiler::compileFields();

        Functions\when('post_type_exists')->alias(fn (string $type): bool => $type === 'book');
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('register_post_meta')->once()->with('book', '_lib_subtitle', \Mockery::type('array'));

        FieldMetaRegistrar::registerPostMeta();
    }

    public function test_collisions_are_reported_at_init_15(): void
    {
        Registry::instance()->add(Schema::fieldset('a')->on('book')->fields([Field::text('subtitle')]));
        Registry::instance()->add(Schema::fieldset('b')->on('movie')->fields([Field::text('subtitle')]));
        Compiler::compileFields();

        Compiler::reportCollisions();

        $this->assertCount(1, CollisionReport::collisions());
        $this->assertStringContainsString('"subtitle" is defined by both schema fieldsets "a" and "b"', $this->notices[0]);
    }

    // ── Rewrite flushing ─────────────────────────────────────────────────

    public function test_rewrite_rules_flush_when_post_types_change_and_only_then(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');
        $stored = null;
        Functions\when('get_option')->alias(function (string $name, $default = false) use (&$stored) {
            return $stored ?? $default;
        });
        Functions\when('update_option')->alias(function (string $name, $value) use (&$stored): bool {
            $stored = $value;
            return true;
        });
        Registry::instance()->add(Schema::postType('book'));

        Functions\expect('flush_rewrite_rules')->once()->with(false);
        Compiler::maybeFlushRewriteRules();   // first time: flush + store
        Compiler::maybeFlushRewriteRules();   // unchanged: no flush

        $this->assertIsString($stored);
    }

    public function test_sites_without_definitions_never_flush_or_write_an_option(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('get_option')->justReturn(null);
        Functions\expect('flush_rewrite_rules')->never();
        Functions\expect('update_option')->never();

        Compiler::maybeFlushRewriteRules();
    }

    public function test_rewrite_rules_never_flush_on_the_front_end(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\expect('flush_rewrite_rules')->never();
        Registry::instance()->add(Schema::postType('book'));

        Compiler::maybeFlushRewriteRules();
    }
}
