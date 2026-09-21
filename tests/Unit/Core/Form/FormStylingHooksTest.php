<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * Covers the per-form styling hooks on Form::register():
 *
 *  - 'class'        → extra class(es) on the <form>, after `taw-form`
 *  - 'button_class' → extra class(es) on the primary buttons, after `taw-btn taw-btn-primary`
 *  - per-field width emitted as the `--taw-span` custom property instead of an
 *    inline `grid-column`, so a theme can override the column with an ordinary
 *    selector (previously only `!important` beat the inline style)
 *
 * Found while pixel-matching a Figma contact form: the submit button was
 * hard-coded `taw-btn taw-btn-primary` and per-field span was inline, so the
 * only per-form styling route was an ancestor wrapper + `!important`.
 */
final class FormStylingHooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('add_action')->justReturn(true);
        Functions\when('esc_attr')->alias(
            fn(mixed $text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')
        );
        Functions\when('esc_html')->alias(
            fn(mixed $text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8')
        );
    }

    private function makeForm(array $config = []): Form
    {
        return Form::register(array_merge(['id' => 'test_form_' . bin2hex(random_bytes(4))], $config));
    }

    private function submitButton(Form $form): string
    {
        ob_start();
        $this->callMethod($form, 'renderSubmitButton', 'Send', 'Sending...');

        return (string) ob_get_clean();
    }

    private function field(Form $form, array $field): string
    {
        ob_start();
        $this->callMethod($form, 'renderField', $field);

        return (string) ob_get_clean();
    }

    // ── button_class ────────────────────────────────────────────────────

    public function test_submit_button_keeps_default_classes_when_button_class_unset(): void
    {
        $output = $this->submitButton($this->makeForm());

        $this->assertStringContainsString('class="taw-btn taw-btn-primary"', $output);
    }

    public function test_button_class_is_appended_to_the_defaults_not_replacing_them(): void
    {
        $output = $this->submitButton($this->makeForm(['button_class' => 'btn-black rounded-lg']));

        $this->assertStringContainsString('class="taw-btn taw-btn-primary btn-black rounded-lg"', $output);
    }

    public function test_blank_or_non_string_button_class_is_ignored(): void
    {
        foreach (['', '   ', ['x'], 42, null] as $value) {
            $output = $this->submitButton($this->makeForm(['button_class' => $value]));

            $this->assertStringContainsString('class="taw-btn taw-btn-primary"', $output);
        }
    }

    public function test_button_class_is_escaped_for_attribute_context(): void
    {
        $output = $this->submitButton($this->makeForm(['button_class' => 'x" onclick="alert(1)']));

        $this->assertStringNotContainsString('" onclick="', $output);
        $this->assertStringContainsString('&quot;', $output);
    }

    // ── class on the <form> ─────────────────────────────────────────────

    public function test_form_class_is_appended_after_taw_form(): void
    {
        $form = $this->makeForm(['class' => 'contact-form']);

        Functions\when('admin_url')->justReturn('https://example.test/wp-admin/admin-ajax.php');
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('wp_parse_url')->justReturn('example.test');
        Functions\when('home_url')->justReturn('https://example.test');
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('__')->returnArg();

        ob_start();
        try {
            $form->render();
        } catch (\Throwable) {
            // render() goes on to print the inline script, which needs more
            // WordPress stubs than this test cares about — the <form> open
            // tag is written first and is all that's asserted on.
        }
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('class="taw-form contact-form"', $output);
    }

    // ── --taw-span ──────────────────────────────────────────────────────

    public function test_field_width_is_a_custom_property_not_an_inline_grid_column(): void
    {
        $output = $this->field($this->makeForm(), ['id' => 'first', 'label' => 'First', 'type' => 'text', 'width' => 50]);

        $this->assertStringContainsString('style="--taw-span: 6;"', $output);
        $this->assertStringNotContainsString('grid-column', $output);
    }

    public function test_field_width_defaults_to_full_span_and_is_clamped(): void
    {
        $form = $this->makeForm();

        $this->assertStringContainsString('--taw-span: 12;', $this->field($form, ['id' => 'a', 'type' => 'text']));
        $this->assertStringContainsString('--taw-span: 12;', $this->field($form, ['id' => 'b', 'type' => 'text', 'width' => 500]));
        $this->assertStringContainsString('--taw-span: 1;', $this->field($form, ['id' => 'c', 'type' => 'text', 'width' => 1]));
    }

    public function test_structural_types_also_use_the_custom_property(): void
    {
        $form = $this->makeForm();

        $this->assertStringContainsString('--taw-span: 4;', $this->field($form, ['type' => 'divider', 'width' => 33]));
        $this->assertStringNotContainsString('grid-column', $this->field($form, ['type' => 'divider', 'width' => 33]));
    }
}
