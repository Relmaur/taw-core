<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * hubspot_form stores `{portal_id, form_id, region}` as a single JSON
 * object — the config TAW\Core\Integrations\Hubspot::render() needs to
 * embed a form.
 */
final class HubspotFormFieldSanitizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('sanitize_text_field')->alias(
            static fn (mixed $v): string => trim((string) $v)
        );
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
    }

    public function test_sanitizes_a_complete_config(): void
    {
        $input = json_encode(['portal_id' => '12345678', 'form_id' => 'abc-def', 'region' => 'eu1']);

        $stored = json_decode(Metabox::sanitizeHubspotFormValue($input), true);

        $this->assertSame([
            'portal_id' => '12345678',
            'form_id'   => 'abc-def',
            'region'    => 'eu1',
        ], $stored);
    }

    public function test_blank_region_defaults_to_na1(): void
    {
        $input = json_encode(['portal_id' => '1', 'form_id' => '2', 'region' => '']);

        $stored = json_decode(Metabox::sanitizeHubspotFormValue($input), true);

        $this->assertSame('na1', $stored['region']);
    }

    public function test_missing_region_key_defaults_to_na1(): void
    {
        $input = json_encode(['portal_id' => '1', 'form_id' => '2']);

        $stored = json_decode(Metabox::sanitizeHubspotFormValue($input), true);

        $this->assertSame('na1', $stored['region']);
    }

    public function test_invalid_json_produces_empty_ids_with_default_region(): void
    {
        $stored = json_decode(Metabox::sanitizeHubspotFormValue('not json'), true);

        $this->assertSame(['portal_id' => '', 'form_id' => '', 'region' => 'na1'], $stored);
    }

    public function test_accepts_an_already_decoded_array(): void
    {
        $stored = json_decode(
            Metabox::sanitizeHubspotFormValue(['portal_id' => '9', 'form_id' => '8', 'region' => 'na2']),
            true
        );

        $this->assertSame(['portal_id' => '9', 'form_id' => '8', 'region' => 'na2'], $stored);
    }
}
