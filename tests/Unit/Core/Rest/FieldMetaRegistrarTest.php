<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rest;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Rest\FieldMetaRegistrar;
use TAW\Tests\TestCase;

/**
 * REST writes of scalar-registered meta (the data panel's path) must store
 * what the metabox form stores, including gradient_text and hubspot_form,
 * which sanitizeValue() alone doesn't know.
 */
final class FieldMetaRegistrarTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $registered = [];

    /** @var array<string, array<string, mixed>> "post type:meta key" → args */
    private array $byType = [];

    /** @var array<string, array<string, mixed>> "post type:field name" → args */
    private array $restFields = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => in_array($type, ['book', 'movie'], true));
        Functions\when('register_rest_field')->alias(function (string $type, string $name, array $args): bool {
            $this->restFields["{$type}:{$name}"] = $args;
            return true;
        });
        Functions\when('register_post_meta')->alias(function (string $type, string $key, array $args): bool {
            $this->registered[$key] = $args;
            $this->byType["{$type}:{$key}"] = $args;
            return true;
        });
        Functions\when('wp_json_encode')->alias(static fn (mixed $data, int $flags = 0): string|false => json_encode($data, $flags));
        Functions\when('sanitize_text_field')->alias(static fn (mixed $value): string => is_array($value) ? '' : trim(strip_tags((string) $value)));
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        parent::tearDown();
    }

    public function test_scalar_meta_is_sanitized_like_the_metabox_form(): void
    {
        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'book_form', 'type' => 'hubspot_form'],
            ['id' => 'book_headline', 'type' => 'gradient_text'],
            ['id' => 'book_author', 'type' => 'text'],
        ]]);

        FieldMetaRegistrar::registerPostMeta();

        $sanitize = fn (string $key, mixed $value): mixed => ($this->registered[$key]['sanitize_callback'])($value);

        $this->assertSame(
            '{"portal_id":"123","form_id":"abc","region":"na1"}',
            $sanitize('_taw_book_form', '{"portal_id":"123","form_id":"abc","region":""}'),
            'empty region defaults to na1, as Metabox::sanitizeHubspotFormValue() does'
        );
        $this->assertSame(
            '[{"text":"Read ","highlighted":true}]',
            $sanitize('_taw_book_headline', '[{"text":"Read ","highlighted":"1"},{"text":"  ","highlighted":false}]'),
            'segments normalized: highlighted as a boolean, empty segments dropped'
        );
        $this->assertSame('Ada', $sanitize('_taw_book_author', ' <b>Ada</b> '));
    }

    public function test_fieldsets_sharing_an_id_register_their_own_type_per_post_type(): void
    {
        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'text'],
        ]]);
        new Metabox(['id' => 'movie_details', 'title' => 'Movie', 'screens' => ['movie'], 'fields' => [
            ['id' => 'subtitle', 'type' => 'number'],
        ]]);

        FieldMetaRegistrar::registerPostMeta();

        $this->assertSame('string', $this->byType['book:_taw_subtitle']['type'], 'book keeps its text field (was lost to movie\'s)');
        $this->assertSame('number', $this->byType['movie:_taw_subtitle']['type']);
        $this->assertSame('Ada', ($this->byType['book:_taw_subtitle']['sanitize_callback'])(' <b>Ada</b> '));
    }

    public function test_every_prefix_registers_and_the_taw_field_owns_the_rest_field(): void
    {
        new Metabox(['id' => 'legacy', 'title' => 'Legacy', 'screens' => ['book'], 'prefix' => 'acme_', 'fields' => [
            ['id' => 'gallery', 'type' => 'files'],
        ]]);
        new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => [
            ['id' => 'gallery', 'type' => 'repeater', 'fields' => [['id' => 'caption', 'type' => 'text']]],
        ]]);

        FieldMetaRegistrar::registerPostMeta();

        $this->assertArrayHasKey('book:acme_gallery', $this->byType);
        $this->assertArrayHasKey('book:_taw_gallery', $this->byType);
        $this->assertSame(['book:taw_gallery'], array_keys($this->restFields));
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'object']], $this->restFields['book:taw_gallery']['schema'], 'the repeater, not the files field');
    }
}
