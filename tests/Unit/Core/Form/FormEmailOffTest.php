<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * 'email' => false: the form sends no email of its own (its on_submit
 * callback sends a designed one instead). Without an 'email' key it still
 * sends its plain-text email to admin_email.
 */
final class FormEmailOffTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->justReturn('admin@example.com');
        Functions\when('get_bloginfo')->justReturn('Test Site');
    }

    private function send(array $config): bool
    {
        $form = new Form(['id' => 'f', 'fields' => [['id' => 'name', 'label' => 'Name', 'type' => 'text']]] + $config);
        $m    = new \ReflectionMethod($form, 'sendEmail');
        $m->setAccessible(true);

        return (bool) $m->invoke($form, ['name' => 'Ana']);
    }

    public function test_email_false_sends_nothing_and_counts_as_sent(): void
    {
        Functions\expect('wp_mail')->never();

        $this->assertTrue($this->send(['email' => false]));
    }

    public function test_no_email_key_still_sends_the_plain_text_email(): void
    {
        Functions\expect('wp_mail')->once()->with('admin@example.com', \Mockery::any(), \Mockery::type('string'), \Mockery::any())->andReturn(true);

        $this->assertTrue($this->send([]));
    }
}
