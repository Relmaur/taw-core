<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Core\Mail\Mailer;
use TAW\Tests\TestCase;

/**
 * Regression coverage for a real HTML-injection gap: sendWithTemplate()
 * used to concatenate raw, unescaped field labels/values straight into the
 * {{all_fields}} HTML block (and individually into {{field_id}} /
 * {{client_name}} placeholders) that gets emailed to both the site admin
 * and the submitter themselves — their own data echoed back to them. A
 * field value containing markup broke the email's layout for both
 * recipients.
 *
 * Mailer is overloaded via Mockery's "new SomeClass()" interception so we
 * can capture the exact variables handed to setVariables() without needing
 * real compiled mail templates on disk.
 */
final class FormSendWithTemplateEscapingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('get_option')->justReturn('admin@example.com');
        Functions\when('get_site_url')->justReturn('https://example.test');
        Functions\when('esc_html')->alias(
            fn(mixed $text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')
        );
    }

    private function makeForm(): Form
    {
        return Form::register([
            'id' => 'test_form_' . bin2hex(random_bytes(4)),
            'fields' => [
                ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['id' => 'email', 'label' => 'Email', 'type' => 'email'],
            ],
            'email' => [
                'to_self'   => ['template' => 'admin-notification'],
                'to_client' => ['template' => 'submitter-confirmation'],
            ],
        ]);
    }

    public function test_field_value_containing_markup_is_escaped_in_all_fields_and_placeholders(): void
    {
        $captured = [];

        // Note: no exact call-count constraints (once()/twice()) here — Mockery's
        // overload verification is per-instance and false-fails ("called 0 times")
        // once a class is instantiated more than once in a test, even though the
        // calls genuinely go through. $captured's own assertCount() below is what
        // actually verifies both sends happened.
        $mailer = \Mockery::mock('overload:' . Mailer::class);
        $mailer->shouldReceive('to')->andReturnSelf();
        $mailer->shouldReceive('subject')->andReturnSelf();
        $mailer->shouldReceive('template')->andReturnSelf();
        $mailer->shouldReceive('send')->andReturn(true);
        $mailer->shouldReceive('setVariables')->andReturnUsing(
            function (array $vars) use ($mailer, &$captured) {
                $captured[] = $vars;
                return $mailer;
            }
        );

        $form = $this->makeForm();

        $formData = [
            'name'  => '<script>alert(1)</script>',
            'email' => 'attacker@example.com',
        ];

        $result = $this->callMethod($form, 'sendWithTemplate', $formData);

        $this->assertTrue($result);
        // One call for the admin notification, one for the submitter confirmation.
        $this->assertCount(2, $captured);

        foreach ($captured as $vars) {
            $this->assertStringNotContainsString('<script>', $vars['all_fields']);
            $this->assertStringContainsString(
                '&lt;script&gt;alert(1)&lt;/script&gt;',
                $vars['all_fields']
            );
            $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $vars['name']);
        }

        // client_name is assigned separately from formData['name'] for the
        // submitter-confirmation send only — it bypassed the escaping loop
        // entirely before the fix.
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $captured[1]['client_name']);
    }
}
