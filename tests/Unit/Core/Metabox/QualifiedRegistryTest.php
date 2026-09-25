<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * ADR-0008 decision 1–2: every field is also indexed by qualified id, and
 * fieldsFor() answers "which fields store a value on this post type" by meta
 * key, so two metaboxes sharing a bare id each keep their own config.
 */
final class QualifiedRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['page', 'book', 'movie'], true));
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        parent::tearDown();
    }

    private function twoFieldsetsSharingSubtitle(): void
    {
        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'text'],
        ]]);
        new Metabox(['id' => 'movie_details', 'title' => 'Movie', 'screens' => ['movie'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'number'],
        ]]);
    }

    public function test_each_fieldset_keeps_its_own_config_under_its_qualified_id(): void
    {
        $this->twoFieldsetsSharingSubtitle();

        $qualified = Metabox::getQualifiedRegistry();
        $this->assertSame('text', $qualified['book_details.subtitle']['type']);
        $this->assertSame('number', $qualified['movie_details.subtitle']['type']);
        $this->assertSame('_taw_subtitle', $qualified['book_details.subtitle']['meta_key']);
        $this->assertSame('subtitle', $qualified['book_details.subtitle']['field_key']);

        // The bare index is unchanged: the later metabox wins, as before.
        $this->assertSame('number', Metabox::get_field_config('subtitle')['type']);
        $this->assertArrayNotHasKey('qualified_id', Metabox::get_field_config('subtitle'));
    }

    public function test_fields_for_resolves_per_post_type(): void
    {
        $this->twoFieldsetsSharingSubtitle();

        $this->assertSame('text', Metabox::fieldsFor('post', 'book')['_taw_subtitle']['type']);
        $this->assertSame('number', Metabox::fieldsFor('post', 'movie')['_taw_subtitle']['type']);
        $this->assertSame([], Metabox::fieldsFor('post', 'page'));
        $this->assertSame([], Metabox::fieldsFor('term', 'genre'), 'terms come with storage contexts (Step 2)');
    }

    public function test_post_types_with_metabox_sees_both_fieldsets(): void
    {
        $this->twoFieldsetsSharingSubtitle();

        $types = Metabox::postTypesWithMetabox();
        sort($types);

        $this->assertSame(['book', 'movie'], $types, 'the bare registry alone lost "book"');
    }

    public function test_group_sub_fields_are_qualified_and_parents_store_nothing(): void
    {
        new Metabox(['id' => 'hero', 'title' => 'Hero', 'screens' => ['page'], 'fields' => [
            ['id' => 'cta', 'type' => 'group', 'fields' => [
                ['id' => 'text', 'type' => 'text'],
                ['id' => 'url', 'type' => 'url'],
            ]],
        ]]);

        $qualified = Metabox::getQualifiedRegistry();
        $this->assertSame('_taw_cta_text', $qualified['hero.cta_text']['meta_key']);
        $this->assertSame('text', $qualified['hero.cta_text']['id'], 'config id stays the sub-field id');
        $this->assertArrayHasKey('hero.cta', $qualified);

        $this->assertSame(['_taw_cta_text', '_taw_cta_url'], array_keys(Metabox::fieldsFor('post', 'page')));
    }

    public function test_other_prefixes_get_their_own_meta_key(): void
    {
        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'prefix' => '_book_', 'fields' => [
            ['id' => 'author', 'type' => 'text'],
        ]]);

        $this->assertSame(['_book_author'], array_keys(Metabox::fieldsFor('post', 'book')));
    }

    public function test_field_matches_by_qualified_id_meta_key_or_bare_id(): void
    {
        new Metabox(['id' => 'legacy', 'title' => 'Legacy', 'screens' => ['book'], 'prefix' => 'acme_', 'fields' => [
            ['id' => 'subtitle', 'type' => 'textarea'],
        ]]);
        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'text'],
        ]]);

        $this->assertSame('textarea', Metabox::fieldFor('post', 'book', 'legacy.subtitle')['type']);
        $this->assertSame('textarea', Metabox::fieldFor('post', 'book', 'acme_subtitle')['type']);

        $matches = Metabox::fieldMatches('post', 'book', 'subtitle');
        $this->assertSame(['book_details.subtitle', 'legacy.subtitle'], array_column($matches, 'qualified_id'), 'bare id: ambiguous, _taw_ first');
        $this->assertSame('text', Metabox::fieldFor('post', 'book', 'subtitle')['type']);

        $this->assertNull(Metabox::fieldFor('post', 'page', 'subtitle'));
    }

    public function test_meta_key_of_prefers_the_explicit_meta_key(): void
    {
        $this->assertSame('_taw_cta_text', Metabox::metaKeyOf(['id' => 'text', 'meta_key' => '_taw_cta_text']));
        $this->assertSame('acme_title', Metabox::metaKeyOf(['id' => 'title', 'prefix' => 'acme_']));
        $this->assertSame('_taw_title', Metabox::metaKeyOf(['id' => 'title']));
    }

    public function test_write_meta_stores_a_group_sub_field_under_its_compound_key(): void
    {
        new Metabox(['id' => 'hero', 'title' => 'Hero', 'screens' => ['page'], 'fields' => [
            ['id' => 'cta', 'type' => 'group', 'fields' => [['id' => 'text', 'type' => 'text']]],
        ]]);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_slash')->returnArg(1);
        $written = [];
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value) use (&$written): bool {
            $written[$key] = $value;
            return true;
        });

        Metabox::writeMeta(7, Metabox::fieldFor('post', 'page', 'cta_text'), 'Go');

        $this->assertSame(['_taw_cta_text' => 'Go'], $written, 'the bare config (id "text") used to write _taw_text');
    }
}
