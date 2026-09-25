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

    protected function setUp(): void
    {
        parent::setUp();
        $registry = new \ReflectionProperty(Metabox::class, 'fieldRegistry');
        $registry->setValue(null, []);
        Functions\when('post_type_exists')->alias(static fn (string $type): bool => $type === 'book');
        Functions\when('register_rest_field')->justReturn(true);
        Functions\when('register_post_meta')->alias(function (string $type, string $key, array $args): bool {
            $this->registered[$key] = $args;
            return true;
        });
        Functions\when('wp_json_encode')->alias(static fn (mixed $data, int $flags = 0): string|false => json_encode($data, $flags));
        Functions\when('sanitize_text_field')->alias(static fn (mixed $value): string => is_array($value) ? '' : trim(strip_tags((string) $value)));
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Metabox::class, 'fieldRegistry'))->setValue(null, []);
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
}
