<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\OptionsPage;

use Brain\Monkey\Functions;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * Covers the bare-id registry lookup and the update_option() write
 * primitive `fields:set`'s 'options' scope depends on (see
 * FieldsSetCommand/FieldsGetCommand) — added alongside the
 * gradient_text/hubspot_form field types, all driven by the same
 * populate-content doc-vs-reality gap: `fields:set` never actually had an
 * OptionsPage-writing path before this.
 *
 * The registry is seeded directly via reflection (same technique
 * MetaboxOrderResolutionTest uses for Metabox's own static registry)
 * rather than constructing a real OptionsPage instance, which would
 * require stubbing add_action/admin_menu/admin_init/admin_enqueue_scripts
 * just to populate a property this test doesn't otherwise need.
 */
final class OptionsPageFieldConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->seedFieldRegistry([]);
        parent::tearDown();
    }

    public function test_get_field_config_finds_a_field_by_bare_id(): void
    {
        $this->seedFieldRegistry([
            '_taw_company_phone' => ['id' => 'company_phone', 'type' => 'text', 'prefix' => '_taw_'],
        ]);

        $config = OptionsPage::getFieldConfig('company_phone');

        $this->assertNotNull($config);
        $this->assertSame('text', $config['type']);
    }

    public function test_get_field_config_returns_null_for_an_unregistered_id(): void
    {
        $this->seedFieldRegistry([]);

        $this->assertNull(OptionsPage::getFieldConfig('does_not_exist'));
    }

    public function test_write_option_sanitizes_and_stores_via_update_option(): void
    {
        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim((string) $v));

        $stored = [];
        Functions\when('update_option')->alias(function (string $key, mixed $value) use (&$stored) {
            $stored[$key] = $value;
            return true;
        });

        $config = ['id' => 'company_phone', 'type' => 'text', 'prefix' => '_taw_'];

        $sanitized = OptionsPage::writeOption($config, '  555-1234  ');

        $this->assertSame('555-1234', $sanitized);
        $this->assertSame(['_taw_company_phone' => '555-1234'], $stored);
    }

    public function test_write_option_uses_the_field_configs_own_prefix(): void
    {
        Functions\when('sanitize_text_field')->alias(static fn ($v) => (string) $v);

        $stored = [];
        Functions\when('update_option')->alias(function (string $key, mixed $value) use (&$stored) {
            $stored[$key] = $value;
        });

        OptionsPage::writeOption(['id' => 'foo', 'type' => 'text', 'prefix' => '_custom_'], 'bar');

        $this->assertArrayHasKey('_custom_foo', $stored);
    }

    /** @param array<string, array<string, mixed>> $registry */
    private function seedFieldRegistry(array $registry): void
    {
        $ref = new \ReflectionProperty(OptionsPage::class, 'fieldRegistry');
        $ref->setAccessible(true);
        $ref->setValue(null, $registry);
    }
}
