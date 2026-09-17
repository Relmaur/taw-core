<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * Regression coverage: an email value that WordPress's sanitize_email()
 * can't fix (e.g. missing "@") gets reduced to '' by sanitize() *before*
 * the type === 'email' check ran, and that check used to read
 * !empty($sanitized) — so the already-blanked value looked like "nothing
 * to validate" and a required, malformed email field was silently saved
 * as blank instead of rejected. The fix checks blankness of the raw
 * pre-sanitize value instead, so a non-blank malformed input is still
 * flagged even though it sanitizes down to ''.
 */
final class FormEmailValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_POST = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('__')->returnArg(1);
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
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

        // Minimal faithful stand-ins for the real WP functions: sanitize_email()
        // returns '' when it can't produce a usable address (in particular, no
        // '@' at all); is_email() requires local-part@domain.tld shape.
        Functions\when('sanitize_email')->alias(
            fn($email) => str_contains((string) $email, '@') ? $email : ''
        );
        Functions\when('is_email')->alias(
            fn($email) => (bool) preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email)
        );

        Functions\when('wp_send_json_success')->alias(function () {
            throw new \RuntimeException('__wp_send_json_success__');
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            throw new \RuntimeException('__wp_send_json_error__: ' . json_encode($data));
        });
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    private function makeForm(bool $required): Form
    {
        return Form::register([
            'id'         => 'test_form_' . bin2hex(random_bytes(4)),
            'rate_limit' => false,
            'fields'     => [
                ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => $required],
            ],
            'on_submit'  => function (array $data, int|false $postId): void {
            },
        ]);
    }

    public function test_email_missing_at_symbol_is_rejected_on_a_required_field(): void
    {
        $_POST['email'] = 'not-an-email';
        $form = $this->makeForm(required: true);

        try {
            $form->process();
            $this->fail('Expected process() to reach wp_send_json_error().');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('__wp_send_json_error__', $e->getMessage());
            $this->assertStringContainsString('"email"', $e->getMessage());
        }
    }

    public function test_valid_email_is_accepted(): void
    {
        $_POST['email'] = 'person@example.com';
        $form = $this->makeForm(required: true);

        try {
            $form->process();
            $this->fail('Expected process() to reach wp_send_json_success().');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('__wp_send_json_success__', $e->getMessage());
        }
    }

    public function test_blank_optional_email_is_not_flagged_as_invalid(): void
    {
        $_POST['email'] = '';
        $form = $this->makeForm(required: false);

        try {
            $form->process();
            $this->fail('Expected process() to reach wp_send_json_success().');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('__wp_send_json_success__', $e->getMessage());
        }
    }
}
