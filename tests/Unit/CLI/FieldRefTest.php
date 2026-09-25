<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Brain\Monkey\Functions;
use TAW\CLI\FieldRef;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * `fields:get` / `fields:set` field argument (ADR-0008): qualified id, meta
 * key or bare id, resolved for the post's own type.
 */
final class FieldRefTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['page', 'book', 'movie'], true));
        Functions\when('get_post_type')->alias(static fn (int $id): string => $id === 1 ? 'book' : 'movie');

        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'text'],
            ['id' => 'cta', 'type' => 'group', 'fields' => [['id' => 'text', 'type' => 'text']]],
        ]]);
        new Metabox(['id' => 'legacy', 'title' => 'Legacy', 'screens' => ['book'], 'prefix' => 'acme_', 'fields' => [
            ['id' => 'subtitle', 'type' => 'textarea'],
        ]]);
        new Metabox(['id' => 'movie_details', 'title' => 'Movie', 'screens' => ['movie'], 'fields' => [
            ['id' => 'tagline', 'type' => 'text'],
        ]]);
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        parent::tearDown();
    }

    public function test_a_bare_id_shared_by_two_prefixes_is_ambiguous(): void
    {
        $resolved = FieldRef::resolve(1, 'subtitle');

        $this->assertNull($resolved['config']);
        $this->assertSame(['book_details.subtitle (_taw_subtitle)', 'legacy.subtitle (acme_subtitle)'], $resolved['ambiguous']);
    }

    public function test_qualified_id_and_meta_key_pick_one_field(): void
    {
        $this->assertSame('acme_subtitle', FieldRef::resolve(1, 'legacy.subtitle')['config']['meta_key']);
        $this->assertSame('_taw_subtitle', FieldRef::resolve(1, '_taw_subtitle')['config']['meta_key']);
        $this->assertSame('_taw_cta_text', FieldRef::resolve(1, 'cta_text')['config']['meta_key']);
    }

    public function test_a_field_of_another_post_type_falls_back_to_the_bare_registry(): void
    {
        $resolved = FieldRef::resolve(2, 'cta_text');

        $this->assertSame('_taw_cta_text', $resolved['config']['meta_key'], 'compound key, not _taw_text');
        $this->assertSame('cta_text', $resolved['config']['field_key']);
        $this->assertSame(['config' => null, 'ambiguous' => []], FieldRef::resolve(2, 'nope'));
    }
}
