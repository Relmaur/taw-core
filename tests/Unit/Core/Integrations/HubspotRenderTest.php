<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Integrations;

use Brain\Monkey\Functions;
use TAW\Core\Integrations\Hubspot;
use TAW\Tests\TestCase;

final class HubspotRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_attr')->alias(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        Functions\when('esc_js')->alias(static fn ($v) => addslashes((string) $v));
    }

    public function test_renders_the_embed_script_for_a_complete_config(): void
    {
        $html = Hubspot::render(['portal_id' => '12345678', 'form_id' => 'abc-def', 'region' => 'eu1']);

        $this->assertStringContainsString('js.hsforms.net/forms/embed/v2.js', $html);
        $this->assertStringContainsString('region: "eu1"', $html);
        $this->assertStringContainsString('portalId: "12345678"', $html);
        $this->assertStringContainsString('formId: "abc-def"', $html);
        $this->assertMatchesRegularExpression('/<div id="taw-hubspot-form-[0-9a-f]{10}" class="taw-hubspot-form">/', $html);
    }

    public function test_accepts_a_raw_json_string(): void
    {
        $json = json_encode(['portal_id' => '1', 'form_id' => '2', 'region' => 'na1']);

        $this->assertStringContainsString('portalId: "1"', Hubspot::render($json));
    }

    public function test_missing_portal_id_renders_nothing(): void
    {
        $this->assertSame('', Hubspot::render(['form_id' => '2']));
    }

    public function test_missing_form_id_renders_nothing(): void
    {
        $this->assertSame('', Hubspot::render(['portal_id' => '1']));
    }

    public function test_empty_config_renders_nothing(): void
    {
        $this->assertSame('', Hubspot::render([]));
        $this->assertSame('', Hubspot::render(''));
    }

    public function test_is_configured_reflects_whether_both_ids_are_present(): void
    {
        $this->assertTrue(Hubspot::isConfigured(['portal_id' => '1', 'form_id' => '2']));
        $this->assertFalse(Hubspot::isConfigured(['portal_id' => '1']));
        $this->assertFalse(Hubspot::isConfigured([]));
    }

    public function test_blank_region_defaults_to_na1_in_output(): void
    {
        $html = Hubspot::render(['portal_id' => '1', 'form_id' => '2', 'region' => '']);

        $this->assertStringContainsString('region: "na1"', $html);
    }
}
