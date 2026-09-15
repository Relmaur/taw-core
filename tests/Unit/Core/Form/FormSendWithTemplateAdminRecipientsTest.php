<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Core\Mail\Mailer;
use TAW\Tests\TestCase;

/**
 * Coverage for the 'email.to_self.to' config option — lets a form's admin
 * notification go to more than just admin_email (e.g. a second internal
 * mailbox), without touching the separate 'to_client' submitter confirmation.
 */
final class FormSendWithTemplateAdminRecipientsTest extends TestCase
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

    private function makeForm(array $emailConfig, ?array $fields = null): Form
    {
        return Form::register([
            'id' => 'test_form_' . bin2hex(random_bytes(4)),
            'fields' => $fields ?? [
                ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
            ],
            'email' => $emailConfig,
        ]);
    }

    public function test_defaults_to_admin_email_when_to_self_to_is_not_set(): void
    {
        $captured = [];
        $mailer   = \Mockery::mock('overload:' . Mailer::class);
        $mailer->shouldReceive('to')->andReturnUsing(function (string $to) use ($mailer, &$captured) {
            $captured[] = $to;
            return $mailer;
        });
        $mailer->shouldReceive('subject')->andReturnSelf();
        $mailer->shouldReceive('template')->andReturnSelf();
        $mailer->shouldReceive('setVariables')->andReturnSelf();
        $mailer->shouldReceive('send')->andReturn(true);

        $form = $this->makeForm([
            'to_self' => ['template' => 'admin-notification'],
        ]);

        $this->callMethod($form, 'sendWithTemplate', ['name' => 'Ada']);

        $this->assertSame(['admin@example.com'], $captured);
    }

    public function test_array_of_addresses_is_joined_into_a_single_comma_separated_recipient(): void
    {
        $captured = [];
        $mailer   = \Mockery::mock('overload:' . Mailer::class);
        $mailer->shouldReceive('to')->andReturnUsing(function (string $to) use ($mailer, &$captured) {
            $captured[] = $to;
            return $mailer;
        });
        $mailer->shouldReceive('subject')->andReturnSelf();
        $mailer->shouldReceive('template')->andReturnSelf();
        $mailer->shouldReceive('setVariables')->andReturnSelf();
        $mailer->shouldReceive('send')->andReturn(true);

        $form = $this->makeForm([
            'to_self' => [
                'template' => 'admin-notification',
                'to'       => ['ops@example.com', 'sales@example.com'],
            ],
        ]);

        $this->callMethod($form, 'sendWithTemplate', ['name' => 'Ada']);

        $this->assertSame(['ops@example.com, sales@example.com'], $captured);
    }

    public function test_string_to_self_to_is_passed_through_unchanged(): void
    {
        $captured = [];
        $mailer   = \Mockery::mock('overload:' . Mailer::class);
        $mailer->shouldReceive('to')->andReturnUsing(function (string $to) use ($mailer, &$captured) {
            $captured[] = $to;
            return $mailer;
        });
        $mailer->shouldReceive('subject')->andReturnSelf();
        $mailer->shouldReceive('template')->andReturnSelf();
        $mailer->shouldReceive('setVariables')->andReturnSelf();
        $mailer->shouldReceive('send')->andReturn(true);

        $form = $this->makeForm([
            'to_self' => [
                'template' => 'admin-notification',
                'to'       => 'ops@example.com, sales@example.com',
            ],
        ]);

        $this->callMethod($form, 'sendWithTemplate', ['name' => 'Ada']);

        $this->assertSame(['ops@example.com, sales@example.com'], $captured);
    }

    public function test_to_client_confirmation_is_unaffected_by_to_self_to(): void
    {
        $captured = [];
        $mailer   = \Mockery::mock('overload:' . Mailer::class);
        $mailer->shouldReceive('to')->andReturnUsing(function (string $to) use ($mailer, &$captured) {
            $captured[] = $to;
            return $mailer;
        });
        $mailer->shouldReceive('subject')->andReturnSelf();
        $mailer->shouldReceive('template')->andReturnSelf();
        $mailer->shouldReceive('setVariables')->andReturnSelf();
        $mailer->shouldReceive('send')->andReturn(true);

        $form = $this->makeForm(
            [
                'to_self' => [
                    'template' => 'admin-notification',
                    'to'       => ['ops@example.com', 'sales@example.com'],
                ],
                'to_client' => ['template' => 'submitter-confirmation'],
            ],
            [
                ['id' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['id' => 'email', 'label' => 'Email', 'type' => 'email'],
            ]
        );

        $this->callMethod($form, 'sendWithTemplate', [
            'name'  => 'Ada',
            'email' => 'client@example.com',
        ]);

        $this->assertSame(['ops@example.com, sales@example.com', 'client@example.com'], $captured);
    }
}
