<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Turnstile;
use TAW\Tests\TestCase;

/**
 * TAW_TURNSTILE_SITE_KEY/SECRET_KEY are real PHP constants (define()),
 * which are process-global and permanent once set — unlike a WP option,
 * a test can't "un-define" them for a later test in the same process.
 * This class defines them once (guarded) and only ever tests the
 * "configured" state; the "not configured" state is covered separately
 * in TurnstileNotConfiguredTest, isolated via @runInSeparateProcess so
 * neither test class's constant state can leak into the other regardless
 * of run order.
 */
final class TurnstileTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!defined('TAW_TURNSTILE_SITE_KEY')) {
            define('TAW_TURNSTILE_SITE_KEY', 'test-site-key');
        }
        if (!defined('TAW_TURNSTILE_SECRET_KEY')) {
            define('TAW_TURNSTILE_SECRET_KEY', 'test-secret-key');
        }
    }

    public function test_is_configured_when_both_constants_are_set(): void
    {
        $this->assertTrue(Turnstile::isConfigured());
    }

    public function test_site_key_returns_the_public_key(): void
    {
        $this->assertSame('test-site-key', Turnstile::siteKey());
    }

    public function test_verify_returns_false_for_an_empty_token_without_a_network_call(): void
    {
        Functions\expect('wp_remote_post')->never();

        $this->assertFalse(Turnstile::verify('', '127.0.0.1'));
    }

    public function test_verify_returns_true_on_a_successful_cloudflare_response(): void
    {
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":true}']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');

        $this->assertTrue(Turnstile::verify('a-real-token', '127.0.0.1'));
    }

    public function test_verify_returns_false_when_cloudflare_rejects_the_token(): void
    {
        Functions\when('wp_remote_post')->justReturn(['body' => '{"success":false}']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":false}');

        $this->assertFalse(Turnstile::verify('a-bad-token', '127.0.0.1'));
    }

    public function test_verify_fails_closed_on_a_network_error(): void
    {
        $fakeError = new class {
            public function get_error_message(): string
            {
                return 'connection timed out';
            }
        };

        Functions\when('wp_remote_post')->justReturn($fakeError);
        Functions\when('is_wp_error')->justReturn(true);

        $this->assertFalse(Turnstile::verify('a-real-token', '127.0.0.1'));
    }

    public function test_verify_fails_closed_on_a_malformed_response_body(): void
    {
        Functions\when('wp_remote_post')->justReturn(['body' => 'not json']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('not json');

        $this->assertFalse(Turnstile::verify('a-real-token', '127.0.0.1'));
    }
}
