<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * 'required_if' lets a field stay visible and submitted in every case (unlike
 * 'conditions', which hides and skips the field entirely) while only being
 * mandatory when its rule is met — e.g. an open-ended "message" box that's
 * optional in general but mandatory when a dropdown is set to "Other".
 */
final class FormRequiredIfTest extends TestCase
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
        Functions\when('sanitize_textarea_field')->returnArg(1);
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

    private function makeForm(): Form
    {
        return Form::register([
            'id'         => 'test_form_' . bin2hex(random_bytes(4)),
            'rate_limit' => false,
            'fields'     => [
                ['id' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true],
                [
                    'id'          => 'message',
                    'label'       => 'Message',
                    'type'        => 'textarea',
                    'required'    => false,
                    'required_if' => [
                        ['field' => 'category', 'operator' => '==', 'value' => 'other'],
                    ],
                ],
            ],
            'on_submit'  => function (array $data, int|false $postId): void {
            },
        ]);
    }

    public function test_blank_message_is_rejected_when_the_rule_is_met(): void
    {
        $_POST['category'] = 'other';
        $_POST['message']  = '';
        $form = $this->makeForm();

        try {
            $form->process();
            $this->fail('Expected process() to reach wp_send_json_error().');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('__wp_send_json_error__', $e->getMessage());
            $this->assertStringContainsString('"message"', $e->getMessage());
        }
    }

    public function test_blank_message_is_accepted_when_the_rule_is_not_met(): void
    {
        $_POST['category'] = 'billing';
        $_POST['message']  = '';
        $form = $this->makeForm();

        try {
            $form->process();
            $this->fail('Expected process() to reach wp_send_json_success().');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('__wp_send_json_success__', $e->getMessage());
        }
    }

    public function test_filled_message_is_accepted_regardless_of_the_rule(): void
    {
        $_POST['category'] = 'other';
        $_POST['message']  = 'Here are the details.';
        $form = $this->makeForm();

        try {
            $form->process();
            $this->fail('Expected process() to reach wp_send_json_success().');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('__wp_send_json_success__', $e->getMessage());
        }
    }
}
