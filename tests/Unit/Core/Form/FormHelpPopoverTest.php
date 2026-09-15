<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * Covers renderHelp() — the "?" trigger + popover any field's 'help' config
 * key renders next to its label. Isolated from renderField() itself since
 * that method pulls in a lot of unrelated per-type rendering; renderHelp()
 * is a small, independently testable unit exactly like the validation
 * helpers in FormMessagesTest.
 */
final class FormHelpPopoverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('esc_attr__')->returnArg(1);
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->alias(
            fn(mixed $text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')
        );
    }

    private function makeForm(): Form
    {
        return Form::register(['id' => 'test_form_' . bin2hex(random_bytes(4))]);
    }

    public function test_renders_nothing_when_help_not_set(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderHelp', ['id' => 'name', 'label' => 'Name']);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_renders_nothing_when_help_is_empty_string(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderHelp', ['id' => 'name', 'help' => '']);
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_renders_trigger_and_popup_when_help_set(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderHelp', ['id' => 'consent', 'help' => 'Plain-language summary.']);
        $output = ob_get_clean();

        $this->assertStringContainsString('taw-help-trigger', $output);
        $this->assertStringContainsString('taw-help-popup', $output);
        $this->assertStringContainsString('Plain-language summary.', $output);
    }

    public function test_help_text_is_escaped(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderHelp', ['id' => 'consent', 'help' => '<script>alert(1)</script>']);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
    }

    public function test_newlines_become_line_breaks(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderHelp', ['id' => 'consent', 'help' => "Line one.\nLine two."]);
        $output = ob_get_clean();

        $this->assertStringContainsString('Line one.<br', $output);
        $this->assertStringContainsString('Line two.', $output);
    }

    public function test_default_trigger_has_no_click_class_or_aria_expanded(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderHelp', ['id' => 'consent', 'help' => 'Summary.']);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('taw-help--click', $output);
        $this->assertStringNotContainsString('aria-expanded', $output);
    }

    public function test_trigger_on_click_adds_click_class_and_aria_attrs(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderHelp', [
            'id'               => 'consent',
            'help'             => 'Summary.',
            'trigger_on_click' => true,
        ]);
        $output = ob_get_clean();

        $this->assertStringContainsString('taw-help--click', $output);
        $this->assertStringContainsString('aria-haspopup="true"', $output);
        $this->assertStringContainsString('aria-expanded="false"', $output);
    }
}
