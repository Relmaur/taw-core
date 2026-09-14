<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * Regression coverage: process() used to discard
 * SubmissionsHandler::saveSubmission()'s return value entirely, so an
 * 'on_submit' callback only ever received $data — never the post ID of
 * the taw_submission record just created for it, with no way to
 * correlate a callback's own side effects back to that record without
 * re-querying by form_id + a timestamp guess.
 *
 * wp_send_json_success()/wp_send_json_error() are mocked to throw a
 * marker exception instead of dying, the way the real WP functions do,
 * so process() can be exercised end-to-end and the test can assert
 * control reached the expected point without a real process exit.
 */
final class FormOnSubmitPostIdTest extends TestCase
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
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('wp_mail')->justReturn(true);
        Functions\when('sanitize_key')->alias(
            fn(string $key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key))
        );

        // 'admin_email' resolves for sendEmail()'s fallback recipient; every
        // other key (webhook URL/secret option lookups) gets its own passed-in
        // default back, so fireWebhook() sees no webhook configured and skips
        // straight to marking '_taw_webhook_status' => 'skipped' rather than
        // reaching wp_remote_post() (not mocked here on purpose — this test
        // isn't exercising webhook delivery).
        Functions\when('get_option')->alias(
            fn(string $key, mixed $default = false) => $key === 'admin_email' ? 'admin@example.com' : $default
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

    private function assertProcessReachedSuccess(Form $form): void
    {
        try {
            $form->process();
            $this->fail('Expected process() to reach wp_send_json_success().');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('__wp_send_json_success__', $e->getMessage());
        }
    }

    public function test_on_submit_callback_receives_the_new_submission_post_id(): void
    {
        Functions\when('wp_insert_post')->justReturn(555);

        $received = 'not-called';

        $form = Form::register([
            'id'         => 'test_form_' . bin2hex(random_bytes(4)),
            'rate_limit' => false,
            'fields'     => [],
            'on_submit'  => function (array $data, int|false $postId) use (&$received) {
                $received = $postId;
            },
        ]);

        $this->assertProcessReachedSuccess($form);
        $this->assertSame(555, $received);
    }

    public function test_on_submit_callback_receives_false_when_the_save_itself_failed(): void
    {
        Functions\when('wp_insert_post')->justReturn(false);

        $received = 'not-called';

        $form = Form::register([
            'id'         => 'test_form_' . bin2hex(random_bytes(4)),
            'rate_limit' => false,
            'fields'     => [],
            'on_submit'  => function (array $data, int|false $postId) use (&$received) {
                $received = $postId;
            },
        ]);

        $this->assertProcessReachedSuccess($form);
        $this->assertFalse($received);
    }

    /**
     * A callback written against the pre-fix, one-argument signature
     * (function (array $data): void) must keep working unchanged — PHP
     * silently ignores extra arguments passed to a closure declaring
     * fewer parameters than it's invoked with.
     */
    public function test_a_single_argument_callback_still_works_unchanged(): void
    {
        Functions\when('wp_insert_post')->justReturn(555);

        $received = 'not-called';

        $form = Form::register([
            'id'         => 'test_form_' . bin2hex(random_bytes(4)),
            'rate_limit' => false,
            'fields'     => [],
            'on_submit'  => function (array $data) use (&$received) {
                $received = $data;
            },
        ]);

        $this->assertProcessReachedSuccess($form);
        $this->assertSame([], $received);
    }
}
