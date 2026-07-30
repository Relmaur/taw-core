<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Tests\TestCase;

/**
 * Covers the webhook URL/secret resolution precedence for per-form
 * webhooks: an admin-configured per-form override (Settings -> Form
 * Webhook) > the form's own code-level 'webhook' config default > the
 * site-wide default webhook (fallback for any form with neither).
 * get_option() is faked with a simple in-memory array so each precedence
 * tier can be exercised independently, without a real WordPress install.
 */
final class SubmissionsHandlerWebhookTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        // SubmissionsHandler's constructor registers several hooks — no-op them.
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);

        Functions\when('get_option')->alias(
            fn(string $key, mixed $default = false) => $this->options[$key] ?? $default
        );

        // Close enough to WP core's real sanitize_key() for these tests' purposes.
        Functions\when('sanitize_key')->alias(
            fn(string $key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key))
        );

        // handleSettingsSave()'s own dependencies — a real submission goes
        // through all of these, not just get_option().
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('add_settings_error')->justReturn(true);

        Functions\when('update_option')->alias(function (string $key, mixed $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $key) {
            unset($this->options[$key]);
            return true;
        });
    }

    private function handler(): SubmissionsHandler
    {
        return new SubmissionsHandler();
    }

    public function test_per_form_admin_override_wins_over_code_config_and_global(): void
    {
        $this->options['taw_form_webhook_url_contact'] = 'https://form-specific.example.com';
        $this->options[SubmissionsHandler::WEBHOOK_URL] = 'https://global-default.example.com';

        $result = $this->callMethod(
            $this->handler(),
            'resolveWebhookUrl',
            'contact',
            ['url' => 'https://code-config.example.com']
        );

        $this->assertSame('https://form-specific.example.com', $result);
    }

    public function test_code_config_wins_when_no_admin_override_is_set(): void
    {
        $this->options[SubmissionsHandler::WEBHOOK_URL] = 'https://global-default.example.com';

        $result = $this->callMethod(
            $this->handler(),
            'resolveWebhookUrl',
            'contact',
            ['url' => 'https://code-config.example.com']
        );

        $this->assertSame('https://code-config.example.com', $result);
    }

    public function test_global_default_used_when_no_form_specific_source_exists(): void
    {
        $this->options[SubmissionsHandler::WEBHOOK_URL] = 'https://global-default.example.com';

        $result = $this->callMethod($this->handler(), 'resolveWebhookUrl', 'contact', []);

        $this->assertSame('https://global-default.example.com', $result);
    }

    public function test_empty_string_when_nothing_is_configured_anywhere(): void
    {
        $result = $this->callMethod($this->handler(), 'resolveWebhookUrl', 'contact', []);

        $this->assertSame('', $result);
    }

    public function test_different_forms_have_independent_admin_overrides(): void
    {
        $this->options['taw_form_webhook_url_contact']    = 'https://contact.example.com';
        $this->options['taw_form_webhook_url_newsletter'] = 'https://newsletter.example.com';

        $this->assertSame(
            'https://contact.example.com',
            $this->callMethod($this->handler(), 'resolveWebhookUrl', 'contact', [])
        );
        $this->assertSame(
            'https://newsletter.example.com',
            $this->callMethod($this->handler(), 'resolveWebhookUrl', 'newsletter', [])
        );
    }

    public function test_secret_follows_the_same_precedence_as_url(): void
    {
        $this->options['taw_form_webhook_secret_contact'] = 'form-secret';
        $this->options[SubmissionsHandler::WEBHOOK_SECRET] = 'global-secret';

        $result = $this->callMethod(
            $this->handler(),
            'resolveWebhookSecret',
            'contact',
            ['secret' => 'code-secret']
        );

        $this->assertSame('form-secret', $result);
    }

    public function test_secret_falls_back_from_code_config_to_global(): void
    {
        $this->options[SubmissionsHandler::WEBHOOK_SECRET] = 'global-secret';

        $this->assertSame(
            'code-secret',
            $this->callMethod($this->handler(), 'resolveWebhookSecret', 'contact', ['secret' => 'code-secret'])
        );
        $this->assertSame(
            'global-secret',
            $this->callMethod($this->handler(), 'resolveWebhookSecret', 'contact', [])
        );
    }

    /**
     * Regression test for a production-breaking bug: the sitewide default
     * field and the per-form override table both used to render an <input>
     * named off the same base key (self::WEBHOOK_URL /
     * 'taw_form_webhook_url') — one scalar ("taw_form_webhook_url"), one
     * array-notation ("taw_form_webhook_url[$formId]"). A real browser
     * submits both simultaneously, and PHP's request-body parser coerces
     * the whole $_POST entry into an array when it sees the array-notation
     * name arrive later in the body — silently clobbering the scalar value.
     * handleSettingsSave() then called trim() on that array and fataled
     * with a TypeError, on every save, on any site with at least one
     * registered form (i.e. virtually every real site).
     *
     * The per-form fields now use a distinct 'taw_per_form_webhook_*'
     * prefix specifically so this collision can't happen. This test submits
     * both fields at once, exactly as a real browser would, and asserts
     * the save succeeds and both values persist to their own distinct
     * option keys.
     */
    public function test_handle_settings_save_does_not_collide_default_and_per_form_fields(): void
    {
        $formId = 'test_form_' . bin2hex(random_bytes(4));
        Form::register(['id' => $formId, 'fields' => []]);

        $_POST = [
            'taw_webhook_settings_nonce' => 'any-nonce-value',
            SubmissionsHandler::WEBHOOK_URL => 'https://global-default.example.com',
            SubmissionsHandler::WEBHOOK_SECRET => '',
            'taw_clear_webhook_secret' => '',
            'taw_per_form_webhook_url' => [$formId => 'https://per-form.example.com'],
            'taw_per_form_webhook_secret' => [$formId => 'per-form-secret'],
            'taw_clear_per_form_webhook_secret' => [],
        ];

        $this->handler()->handleSettingsSave();

        $this->assertSame(
            'https://global-default.example.com',
            $this->options[SubmissionsHandler::WEBHOOK_URL] ?? null
        );
        $this->assertSame(
            'https://per-form.example.com',
            $this->options['taw_form_webhook_url_' . $formId] ?? null
        );
        $this->assertSame(
            'per-form-secret',
            $this->options['taw_form_webhook_secret_' . $formId] ?? null
        );

        $_POST = [];
    }
}
