<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * Covers the message-resolution precedence every validation rule shares:
 * field-level '{rule}_message' > form-level 'messages.{rule}' > built-in
 * English default. This is exactly the logic that was manually verified
 * via ad hoc ReflectionMethod scripts throughout the session this suite
 * replaces — formalized here so a future regression is caught by `composer
 * run test`, not by re-deriving a one-off verification script from scratch.
 */
final class FormMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Form's constructor calls add_action() three times; identity
        // function for __() so message templates pass through unchanged.
        Functions\when('add_action')->justReturn(true);
        Functions\when('__')->returnArg(1);
    }

    private function makeForm(array $config): Form
    {
        // Form::register()/the constructor writes into a private static
        // registry keyed by id — use a unique id per test so instances
        // never collide across the suite.
        $config['id'] ??= 'test_form_' . bin2hex(random_bytes(4));

        return Form::register($config);
    }

    public function test_required_uses_field_level_message_when_set(): void
    {
        $form = $this->makeForm([]);
        $field = ['id' => 'name', 'label' => 'Name', 'required_message' => 'Please tell us your name.'];

        $this->assertSame(
            'Please tell us your name.',
            $this->callMethod($form, 'requiredMessage', $field, 'Name')
        );
    }

    public function test_required_uses_form_level_default_when_no_field_override(): void
    {
        $form = $this->makeForm(['messages' => ['required' => '%s es obligatorio.']]);
        $field = ['id' => 'name', 'label' => 'Nombre'];

        $this->assertSame(
            'Nombre es obligatorio.',
            $this->callMethod($form, 'requiredMessage', $field, 'Nombre')
        );
    }

    public function test_required_falls_back_to_built_in_default(): void
    {
        $form = $this->makeForm([]);
        $field = ['id' => 'name', 'label' => 'Name'];

        $this->assertSame(
            'Name is required.',
            $this->callMethod($form, 'requiredMessage', $field, 'Name')
        );
    }

    public function test_field_level_required_message_wins_over_form_level_default(): void
    {
        $form = $this->makeForm(['messages' => ['required' => '%s es obligatorio.']]);
        $field = ['id' => 'name', 'label' => 'Name', 'required_message' => 'Custom override.'];

        $this->assertSame(
            'Custom override.',
            $this->callMethod($form, 'requiredMessage', $field, 'Name')
        );
    }

    public function test_email_uses_field_level_message_when_set(): void
    {
        $form = $this->makeForm([]);
        $field = ['id' => 'email', 'email_message' => "That doesn't look like a real email address."];

        $this->assertSame(
            "That doesn't look like a real email address.",
            $this->callMethod($form, 'emailMessage', $field)
        );
    }

    public function test_email_uses_form_level_default_when_no_field_override(): void
    {
        $form = $this->makeForm(['messages' => ['email' => 'Correo electrónico no válido.']]);
        $field = ['id' => 'email'];

        $this->assertSame(
            'Correo electrónico no válido.',
            $this->callMethod($form, 'emailMessage', $field)
        );
    }

    public function test_email_falls_back_to_built_in_default(): void
    {
        $form = $this->makeForm([]);
        $field = ['id' => 'email'];

        $this->assertSame(
            'Invalid email address.',
            $this->callMethod($form, 'emailMessage', $field)
        );
    }

    public function test_min_length_message_precedence(): void
    {
        $form = $this->makeForm(['messages' => ['min_length' => '%1$s debe tener al menos %2$d caracteres.']]);
        $field = ['id' => 'code', 'label' => 'Código', 'min_length' => 5];

        $this->assertSame(
            'Código debe tener al menos 5 caracteres.',
            $this->callMethod($form, 'validateRules', $field, 'abc', 'Código')
        );
    }

    public function test_min_length_field_override_wins(): void
    {
        $form = $this->makeForm(['messages' => ['min_length' => '%1$s debe tener al menos %2$d caracteres.']]);
        $field = ['id' => 'code', 'label' => 'Código', 'min_length' => 5, 'min_length_message' => 'Too short.'];

        $this->assertSame(
            'Too short.',
            $this->callMethod($form, 'validateRules', $field, 'abc', 'Código')
        );
    }

    public function test_min_length_valid_value_returns_null(): void
    {
        $form = $this->makeForm([]);
        $field = ['id' => 'code', 'min_length' => 3];

        $this->assertNull($this->callMethod($form, 'validateRules', $field, 'abcdef', 'Code'));
    }

    public function test_max_length_message_precedence(): void
    {
        $form = $this->makeForm(['messages' => ['max_length' => '%1$s no debe superar los %2$d caracteres.']]);
        $field = ['id' => 'bio', 'label' => 'Bio', 'max_length' => 3];

        $this->assertSame(
            'Bio no debe superar los 3 caracteres.',
            $this->callMethod($form, 'validateRules', $field, 'abcdef', 'Bio')
        );
    }

    public function test_pattern_message_precedence(): void
    {
        $form = $this->makeForm(['messages' => ['pattern' => '%s no tiene el formato correcto.']]);
        $field = ['id' => 'phone', 'label' => 'Teléfono', 'pattern' => '[0-9]{7,10}'];

        $this->assertSame(
            'Teléfono no tiene el formato correcto.',
            $this->callMethod($form, 'validateRules', $field, 'abc', 'Teléfono')
        );
    }

    public function test_pattern_valid_value_returns_null(): void
    {
        $form = $this->makeForm([]);
        $field = ['id' => 'phone', 'pattern' => '[0-9]{7,10}'];

        $this->assertNull($this->callMethod($form, 'validateRules', $field, '5551234567', 'Phone'));
    }

    public function test_min_message_precedence_on_number_field(): void
    {
        $form = $this->makeForm(['messages' => ['min' => '%1$s debe ser al menos %2$s.']]);
        $field = ['id' => 'age', 'label' => 'Edad', 'type' => 'number', 'min' => 18];

        $this->assertSame(
            'Edad debe ser al menos 18.',
            $this->callMethod($form, 'validateRules', $field, '16', 'Edad')
        );
    }

    public function test_max_message_precedence_on_number_field(): void
    {
        $form = $this->makeForm(['messages' => ['max' => '%1$s no debe superar %2$s.']]);
        $field = ['id' => 'guests', 'label' => 'Guests', 'type' => 'number', 'max' => 20];

        $this->assertSame(
            'Guests no debe superar 20.',
            $this->callMethod($form, 'validateRules', $field, '25', 'Guests')
        );
    }

    public function test_min_max_ignored_for_non_number_fields(): void
    {
        $form = $this->makeForm([]);
        // 'min'/'max' only apply when type === 'number' — a text field with
        // these keys set should never trip the range check.
        $field = ['id' => 'name', 'type' => 'text', 'min' => 18, 'max' => 20];

        $this->assertNull($this->callMethod($form, 'validateRules', $field, 'not a number', 'Name'));
    }
}
