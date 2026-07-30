<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
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
}
