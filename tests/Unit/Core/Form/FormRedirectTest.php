<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Core\Log\Logger;
use TAW\Core\Log\LogSinkInterface;
use TAW\Tests\TestCase;

/**
 * `'redirect'` in a form's config: a successful submission's JSON carries
 * the checked URL as `data.redirect`, and the front-end script goes there.
 * A path resolves through home_url(); a URL must pass wp_validate_redirect()
 * (this site, or allowed_redirect_hosts), else it's ignored with one warning.
 * Errors and failures never carry it.
 *
 * wp_send_json_success()/wp_send_json_error() throw a marker carrying their
 * data, the way FormOnSubmitPostIdTest stops process() where WordPress
 * would exit.
 */
final class FormRedirectTest extends TestCase
{
    /** @var list<array{code: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];
        $this->logged = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('wp_insert_post')->justReturn(555);
        Functions\when('sanitize_key')->alias(
            fn(string $key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key))
        );
        Functions\when('get_option')->alias(
            fn(string $key, mixed $default = false) => $key === 'admin_email' ? 'admin@example.com' : $default
        );
        Functions\when('home_url')->alias(fn(string $path = '') => 'https://example.com' . $path);
        // This site is example.com; a theme allowed partner.example via allowed_redirect_hosts.
        Functions\when('wp_validate_redirect')->alias(
            fn(string $location, string $fallback = '') => in_array(parse_url($location, PHP_URL_HOST), ['example.com', 'partner.example'], true)
                && in_array(parse_url($location, PHP_URL_SCHEME), ['http', 'https'], true)
                ? $location
                : $fallback
        );

        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new \RuntimeException('__success__' . json_encode($data));
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            throw new \RuntimeException('__error__' . json_encode($data));
        });

        $logged = &$this->logged;
        Logger::setSinks(new class ($logged) implements LogSinkInterface {
            /** @param list<array{code: string, context: array<string, mixed>}> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function write(array $entry): void
            {
                $this->logged[] = ['code' => (string) $entry['code'], 'context' => (array) $entry['context']];
            }
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Logger::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: string, 1: mixed} ['success'|'error', the response data]
     */
    private function submit(array $config): array
    {
        $form = Form::register($config + [
            'id'         => 'test_form_' . bin2hex(random_bytes(4)),
            'rate_limit' => false,
            'fields'     => [],
        ]);

        try {
            $form->process();
        } catch (\RuntimeException $e) {
            if (preg_match('/^__(success|error)__(.*)$/s', $e->getMessage(), $m)) {
                return [$m[1], json_decode($m[2], true)];
            }
            throw $e;
        }
        $this->fail('process() should end in wp_send_json_success() or wp_send_json_error().');
    }

    public function test_a_site_relative_path_resolves_through_home_url(): void
    {
        [$outcome, $data] = $this->submit(['redirect' => '/thank-you/']);

        $this->assertSame('success', $outcome);
        $this->assertSame('https://example.com/thank-you/', $data['redirect']);
        $this->assertArrayHasKey('message', $data, 'the success message still goes out, for the status region');
    }

    public function test_a_path_without_a_leading_slash_is_still_site_relative(): void
    {
        [, $data] = $this->submit(['redirect' => 'thank-you/']);

        $this->assertSame('https://example.com/thank-you/', $data['redirect']);
    }

    public function test_an_absolute_url_on_this_site_is_kept(): void
    {
        [, $data] = $this->submit(['redirect' => 'https://example.com/gracias/?from=guide']);

        $this->assertSame('https://example.com/gracias/?from=guide', $data['redirect']);
    }

    public function test_an_allowed_redirect_host_is_kept(): void
    {
        [, $data] = $this->submit(['redirect' => 'https://partner.example/welcome/']);

        $this->assertSame('https://partner.example/welcome/', $data['redirect']);
    }

    public function test_an_external_host_is_ignored_with_one_warning_naming_only_the_form(): void
    {
        $_POST = ['email' => 'visitor@example.org'];
        [$outcome, $data] = $this->submit([
            'id'       => 'guide',
            'redirect' => 'https://elsewhere.test/phish/',
            'fields'   => [['id' => 'email', 'label' => 'Email', 'type' => 'text']],
        ]);

        $this->assertSame('success', $outcome);
        $this->assertArrayNotHasKey('redirect', $data);
        $this->assertSame('Thank you! Your message has been sent.', $data['message']);

        $warnings = array_values(array_filter($this->logged, fn(array $e) => $e['code'] === 'form.redirect_rejected'));
        $this->assertCount(1, $warnings);
        $this->assertSame(['form_id' => 'guide'], $warnings[0]['context']);
        $this->assertStringNotContainsString('visitor@example.org', (string) json_encode($this->logged));
    }

    public function test_a_protocol_relative_or_script_url_is_ignored(): void
    {
        [, $relative] = $this->submit(['redirect' => '//elsewhere.test/']);
        [, $script] = $this->submit(['redirect' => 'javascript:alert(1)']);

        $this->assertArrayNotHasKey('redirect', $relative);
        $this->assertArrayNotHasKey('redirect', $script);
    }

    public function test_a_non_string_value_is_ignored(): void
    {
        [, $data] = $this->submit(['redirect' => ['/thank-you/']]);

        $this->assertArrayNotHasKey('redirect', $data);
    }

    public function test_no_redirect_key_when_unset(): void
    {
        [$outcome, $data] = $this->submit([]);

        $this->assertSame('success', $outcome);
        $this->assertSame(['message' => 'Thank you! Your message has been sent.'], $data);
        $this->assertSame([], $this->logged);
    }

    public function test_validation_errors_never_carry_the_redirect(): void
    {
        [$outcome, $data] = $this->submit([
            'redirect' => '/thank-you/',
            'fields'   => [['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true]],
        ]);

        $this->assertSame('error', $outcome);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayNotHasKey('redirect', $data);
    }

    public function test_an_on_submit_rejection_never_carries_the_redirect(): void
    {
        [$outcome, $data] = $this->submit([
            'redirect'  => '/thank-you/',
            'on_submit' => function () {
                throw new \RuntimeException('That guide is no longer available.');
            },
        ]);

        $this->assertSame('error', $outcome);
        $this->assertSame(['general' => 'That guide is no longer available.'], $data);
    }

    public function test_the_script_goes_to_the_redirect_after_showing_the_message(): void
    {
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_js')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('admin_url')->returnArg(1);
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
        Functions\when('wp_parse_url')->alias(fn(string $url, int $c = -1) => parse_url($url, $c));

        $form = Form::register(['id' => 'test_form_' . bin2hex(random_bytes(4)), 'redirect' => '/thank-you/', 'fields' => []]);
        ob_start();
        $form->render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-taw-success role="status"', $html);
        $message = strpos($html, "successEl.classList.remove('taw-hidden')");
        $assign = strpos($html, 'window.location.assign(json.data.redirect)');
        $this->assertNotFalse($assign);
        $this->assertGreaterThan($message, $assign, 'the message is shown before leaving the page');
        $this->assertStringNotContainsString('/thank-you/', $html, 'the URL comes from the response, checked at submission time');
    }
}
