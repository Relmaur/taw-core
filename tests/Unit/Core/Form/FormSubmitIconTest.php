<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * Covers 'submit_icon' — an optional Form::register() config key rendering
 * an icon inside the submit button, after the label. Previously the button
 * only ever rendered a spinner + label, with no markup slot for one at all —
 * flagged as a real, reusable gap while pixel-matching a submit button
 * against a Figma spec with a trailing send icon.
 */
final class FormSubmitIconTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->alias(
            fn(mixed $text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')
        );
    }

    private function makeForm(array $config = []): Form
    {
        return Form::register(array_merge(['id' => 'test_form_' . bin2hex(random_bytes(4))], $config));
    }

    public function test_renders_nothing_when_submit_icon_not_set(): void
    {
        $form = $this->makeForm();

        ob_start();
        $this->callMethod($form, 'renderSubmitIcon');
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_renders_nothing_when_submit_icon_is_empty_string(): void
    {
        $form = $this->makeForm(['submit_icon' => '']);

        ob_start();
        $this->callMethod($form, 'renderSubmitIcon');
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_raw_svg_is_printed_as_is(): void
    {
        $form = $this->makeForm(['submit_icon' => '<svg class="my-icon"><path d="M0 0"></path></svg>']);

        ob_start();
        $this->callMethod($form, 'renderSubmitIcon');
        $output = ob_get_clean();

        $this->assertStringContainsString('<svg class="my-icon">', $output);
        $this->assertStringContainsString('taw-btn-icon', $output);
        $this->assertStringContainsString('aria-hidden="true"', $output);
    }

    public function test_a_real_vendored_icon_name_renders_via_lucide(): void
    {
        // No Lucide::enable() call — render() needs none, per its own
        // docblock; only the admin picker (the 'icon' metabox field type)
        // is gated behind enable().
        $form = $this->makeForm(['submit_icon' => 'send']);

        ob_start();
        $this->callMethod($form, 'renderSubmitIcon');
        $output = ob_get_clean();

        $this->assertStringContainsString('<svg', $output);
        $this->assertStringContainsString('taw-btn-icon', $output);
    }

    public function test_unknown_icon_name_renders_nothing(): void
    {
        $form = $this->makeForm(['submit_icon' => 'this-icon-does-not-exist']);

        ob_start();
        $this->callMethod($form, 'renderSubmitIcon');
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_button_renders_the_icon_after_the_label(): void
    {
        $form = $this->makeForm([
            'submit_label' => 'Send message',
            'submit_icon'  => '<svg class="send-icon"></svg>',
        ]);

        ob_start();
        $this->callMethod($form, 'renderSubmitButton', 'Send message', 'Sending...');
        $output = ob_get_clean();

        $labelPos = strpos($output, 'Send message');
        $iconPos = strpos($output, 'send-icon');

        $this->assertNotFalse($labelPos);
        $this->assertNotFalse($iconPos);
        $this->assertGreaterThan($labelPos, $iconPos);
    }
}
