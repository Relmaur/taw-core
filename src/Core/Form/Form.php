<?php

declare(strict_types=1);

namespace TAW\Core\Form;

use TAW\Core\Mail\Mailer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Form — configuration-driven frontend form with AJAX processing, validation, and email delivery.
 *
 * Features:
 *  - CSRF protection (WordPress nonces)
 *  - Honeypot spam protection
 *  - Field sanitization & validation
 *  - AJAX submission via admin-ajax.php — bypasses WP routing, no 404 risk
 *  - Inline field-level and general error display
 *  - Optional email delivery via Mailer + MJML templates
 *  - Submission persistence via SubmissionsHandler (CPT)
 *  - Multi-step wizard with Prev/Next navigation and step indicator
 *  - Structural field types: html, heading, divider
 *  - Input field types: text, email, url, tel, number, textarea, select, date,
 *                       checkbox, radio, checkbox_group
 *  - Conditional visibility with AND (default) or OR relation
 *
 * ── Single-step usage ────────────────────────────────────────────────────────
 *
 *   Form::register([
 *       'id'           => 'contact',
 *       'submit_label' => 'Send Message',
 *       'fields'       => [
 *           ['id' => 'name',    'label' => 'Name',    'type' => 'text',  'required' => true],
 *           ['id' => 'email',   'label' => 'Email',   'type' => 'email', 'required' => true],
 *           ['id' => 'message', 'label' => 'Message', 'type' => 'textarea'],
 *       ],
 *   ]);
 *
 * ── Multi-step usage ─────────────────────────────────────────────────────────
 *
 *   Form::register([
 *       'id'           => 'onboarding',
 *       'submit_label' => 'Submit',
 *       'next_label'   => 'Continue',
 *       'prev_label'   => 'Back',
 *       'steps' => [
 *           [
 *               'title'  => 'Personal Info',
 *               'fields' => [
 *                   ['type' => 'heading', 'label' => '1. General Data', 'subtitle' => 'Personal identification'],
 *                   ['id' => 'name', 'label' => 'Full Name', 'type' => 'text', 'width' => 50, 'required' => true],
 *                   ['id' => 'dob',  'label' => 'Date of Birth', 'type' => 'date', 'width' => 50],
 *               ],
 *           ],
 *           [
 *               'title'  => 'Contact',
 *               'fields' => [
 *                   ['type' => 'divider'],
 *                   ['id' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
 *                   ['id' => 'estado_civil', 'label' => 'Marital Status', 'type' => 'radio',
 *                    'options' => ['single' => 'Single', 'married' => 'Married']],
 *                   ['id' => 'spouse', 'label' => 'Spouse Name', 'type' => 'text',
 *                    'conditions' => ['relation' => 'any', 'rules' => [
 *                        ['field' => 'estado_civil', 'operator' => '==', 'value' => 'married'],
 *                    ]]],
 *               ],
 *           ],
 *       ],
 *   ]);
 *
 * ── Template ─────────────────────────────────────────────────────────────────
 *
 *   Form::display('contact');
 *
 * @package TAW
 */
class Form
{
    /** @var self[] All registered forms, keyed by form ID. */
    private static array $registry = [];

    private string $id;
    private array $config;

    /** Field types that are structural/cosmetic — no input, no validation, no submission. */
    private const STRUCTURAL_TYPES = ['html', 'heading', 'divider'];

    public static function register(array $config): self
    {
        $instance = new self($config);
        return $instance;
    }

    public static function display(string $id): void
    {
        $form = self::$registry[$id] ?? null;

        if (!$form) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    '[TAW Form] Form "' . esc_html($id) . '" was not registered. '
                    . 'Call Form::register() before using Form::display().',
                    E_USER_WARNING
                );
            }
            return;
        }

        $form->render();
    }

    public function __construct(array $config)
    {
        $this->id     = $config['id'];
        $this->config = $config;

        self::$registry[$this->id] = $this;

        add_action('wp_ajax_nopriv_taw_form_' . $this->id, [$this, 'process']);
        add_action('wp_ajax_taw_form_' . $this->id, [$this, 'process']);
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    private function isMultiStep(): bool
    {
        return !empty($this->config['steps']);
    }

    /**
     * Flat list of all input fields (structural types excluded), from steps or flat config.
     * Used for server-side processing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getInputFields(): array
    {
        $fields = [];

        if ($this->isMultiStep()) {
            foreach ($this->config['steps'] as $step) {
                foreach (($step['fields'] ?? []) as $field) {
                    if (isset($field['id']) && !in_array($field['type'] ?? 'text', self::STRUCTURAL_TYPES, true)) {
                        $fields[] = $field;
                    }
                }
            }
        } else {
            foreach (($this->config['fields'] ?? []) as $field) {
                if (isset($field['id']) && !in_array($field['type'] ?? 'text', self::STRUCTURAL_TYPES, true)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Maps each input field ID to its step index.
     * Returns an empty array for single-step forms.
     *
     * @return array<string, int>
     */
    private function buildFieldToStepMap(): array
    {
        $map = [];

        if (!$this->isMultiStep()) {
            return $map;
        }

        foreach ($this->config['steps'] as $i => $step) {
            foreach (($step['fields'] ?? []) as $field) {
                if (isset($field['id'])) {
                    $map[$field['id']] = $i;
                }
            }
        }

        return $map;
    }

    /**
     * Returns required field IDs grouped by step index.
     * Used by the JS step validator.
     *
     * @return array<int, list<string>>
     */
    private function buildStepRequiredMap(): array
    {
        $map = [];

        if (!$this->isMultiStep()) {
            return $map;
        }

        foreach ($this->config['steps'] as $i => $step) {
            $map[$i] = [];
            foreach (($step['fields'] ?? []) as $field) {
                if (!empty($field['required']) && isset($field['id'])) {
                    $map[$i][] = $field['id'];
                }
            }
        }

        return $map;
    }

    /* -------------------------------------------------------------------------
     * Processing (AJAX handler)
     * ---------------------------------------------------------------------- */

    public function process(): void
    {
        check_ajax_referer($this->id . '_action', $this->id . '_nonce');

        // Honeypot — silently succeed so bots think they submitted correctly.
        if (!empty($_POST['taw_hp_check'])) {
            wp_send_json_success();
        }

        $inputFields = $this->getInputFields();

        // First pass: collect raw values so conditions can be evaluated.
        $raw = [];
        foreach ($inputFields as $field) {
            $posted = $_POST[$field['id']] ?? null;

            // checkbox_group arrives as an array when names use the [] suffix.
            if (is_array($posted)) {
                $raw[$field['id']] = implode(',', array_map('sanitize_text_field', wp_unslash($posted)));
            } else {
                $raw[$field['id']] = $posted !== null ? wp_unslash($posted) : '';
            }
        }

        $data   = [];
        $errors = [];

        foreach ($inputFields as $field) {
            $fieldId = $field['id'];

            if (!$this->conditionsMet($field['conditions'] ?? [], $raw)) {
                continue;
            }

            $value = $raw[$fieldId];
            $label = $field['label'] ?? $fieldId;

            if (!empty($field['required']) && '' === trim((string) $value)) {
                /* translators: %s: field label */
                $errors[$fieldId] = sprintf(__('%s is required.', 'taw'), $label);
                continue;
            }

            $sanitized = $this->sanitize($value, $field['type'] ?? 'text');

            if (($field['type'] ?? '') === 'email' && !empty($sanitized) && !is_email($sanitized)) {
                $errors[$fieldId] = __('Invalid email address.', 'taw');
            }

            $data[$fieldId] = $sanitized;
        }

        if (!empty($errors)) {
            wp_send_json_error(['errors' => $errors]);
        }

        $sent = $this->sendEmail($data);

        if (!$sent) {
            wp_send_json_error(['general' => __('There was a problem sending your message. Please try again later.', 'taw')]);
        }

        SubmissionsHandler::saveSubmission($this->id, $inputFields, $data);

        wp_send_json_success([
            'message' => $this->config['messages']['success'] ?? __('Thank you! Your message has been sent.', 'taw'),
        ]);
    }

    /* -------------------------------------------------------------------------
     * Sanitize / Conditions
     * ---------------------------------------------------------------------- */

    private function sanitize(mixed $value, string $type): string
    {
        return match ($type) {
            'email'         => sanitize_email($value),
            'textarea'      => sanitize_textarea_field($value),
            'url'           => esc_url_raw($value),
            'date'          => sanitize_text_field($value),
            'checkbox'      => ($value === '1') ? '1' : '0',
            'radio'         => sanitize_text_field($value),
            'checkbox_group'=> sanitize_text_field($value), // comma-separated
            default         => sanitize_text_field($value),
        };
    }

    /**
     * Returns true when the conditions array is satisfied by $values.
     *
     * Conditions format (backwards-compatible):
     *
     *   // AND (all rules must pass — default)
     *   'conditions' => [
     *       ['field' => 'x', 'operator' => '==', 'value' => 'y'],
     *   ]
     *
     *   // OR (any rule must pass)
     *   'conditions' => [
     *       'relation' => 'any',
     *       'rules' => [
     *           ['field' => 'x', 'operator' => '==', 'value' => 'y'],
     *           ['field' => 'x', 'operator' => '==', 'value' => 'z'],
     *       ],
     *   ]
     *
     * @param array<mixed>         $conditions
     * @param array<string, mixed> $values
     */
    private function conditionsMet(array $conditions, array $values): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $relation = $conditions['relation'] ?? 'all';
        $rules    = $conditions['rules']    ?? $conditions;

        // Filter to actual rule objects (handles the case where 'relation' key bleeds in).
        $rules = array_values(array_filter($rules, fn($r) => is_array($r) && isset($r['field'])));

        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            $field    = $rule['field']    ?? '';
            $operator = $rule['operator'] ?? '==';
            $expected = (string) ($rule['value'] ?? '');
            $actual   = (string) ($values[$field] ?? '');

            $passes = match ($operator) {
                '!='       => $actual !== $expected,
                '>'        => (float) $actual >  (float) $expected,
                '<'        => (float) $actual <  (float) $expected,
                '>='       => (float) $actual >= (float) $expected,
                '<='       => (float) $actual <= (float) $expected,
                'contains' => str_contains($actual, $expected),
                default    => $actual === $expected,
            };

            if ($relation === 'any' && $passes) {
                return true;
            }

            if ($relation !== 'any' && !$passes) {
                return false;
            }
        }

        return $relation !== 'any';
    }

    /* -------------------------------------------------------------------------
     * Email
     * ---------------------------------------------------------------------- */

    private function sendEmail(array $data): bool
    {
        $emailConfig = $this->config['email'] ?? [];
        $templateIn  = $emailConfig['to_self']['template']   ?? null;
        $templateOut = $emailConfig['to_client']['template'] ?? null;

        if ($templateIn && $templateOut) {
            return $this->sendWithTemplate($data);
        }

        $to      = $emailConfig['to'] ?? get_option('admin_email');
        $subject = $emailConfig['subject'] ?? 'New Form Submission';
        $body    = 'New submission from ' . get_bloginfo('name') . ":\n\n";
        $headers = [];

        foreach ($this->getInputFields() as $field) {
            $body .= ($field['label'] ?? $field['id']) . ': ' . ($data[$field['id']] ?? '-') . "\n";
        }

        foreach ($this->getInputFields() as $field) {
            if (($field['type'] ?? '') === 'email' && !empty($data[$field['id']])) {
                $headers[] = 'Reply-To: ' . $data[$field['id']];
                break;
            }
        }

        return wp_mail($to, $subject, $body, $headers);
    }

    private function sendWithTemplate(array $formData): bool
    {
        $emailConfig = $this->config['email'];

        $replacements = [
            'form_id'   => $this->id,
            'site_name' => get_bloginfo('name'),
        ];

        $allFields = '';
        foreach ($this->getInputFields() as $field) {
            $label                       = $field['label'] ?? $field['id'];
            $value                       = $formData[$field['id']] ?? '-';
            $replacements[$field['id']] = $value;
            $allFields                  .= "<p><strong>{$label}:</strong> {$value}</p>";
        }
        $replacements['all_fields'] = $allFields;

        $shared = array_merge($replacements, ['site_url' => get_site_url()]);

        try {
            (new Mailer())
                ->to(get_option('admin_email'))
                ->subject($emailConfig['to_self']['subject'] ?? 'New Form Submission')
                ->template($emailConfig['to_self']['template'])
                ->setVariables($shared)
                ->send();

            $clientEmail = null;
            foreach ($this->getInputFields() as $field) {
                if (($field['type'] ?? '') === 'email' && !empty($formData[$field['id']])) {
                    $clientEmail = $formData[$field['id']];
                    break;
                }
            }

            if ($clientEmail) {
                $shared['client_name'] = $formData['name'] ?? '';

                (new Mailer())
                    ->to($clientEmail)
                    ->subject($emailConfig['to_client']['subject'] ?? "Got your message — I'll be in touch soon")
                    ->template($emailConfig['to_client']['template'])
                    ->setVariables($shared)
                    ->send();
            }
        } catch (\Throwable $e) {
            error_log('[TAW Form] sendWithTemplate failed for form "' . $this->id . '": ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /* -------------------------------------------------------------------------
     * Rendering
     * ---------------------------------------------------------------------- */

    public function render(): void
    {
        $formId      = esc_attr($this->id);
        $ajaxUrl     = esc_url(admin_url('admin-ajax.php'));
        $isMultiStep = $this->isMultiStep();

        echo '<form method="post"'
            . ' action="' . $ajaxUrl . '"'
            . ' class="taw-form"'
            . ' data-taw-form="' . $formId . '"'
            . ($isMultiStep ? ' data-taw-multistep' : '')
            . ' novalidate>';

        wp_nonce_field($this->id . '_action', $this->id . '_nonce');
        echo '<input type="hidden" name="action" value="taw_form_' . $formId . '">';
        echo '<input type="hidden" name="taw_form_id" value="' . $formId . '">';

        // Honeypot — hidden from real users, filled by bots.
        echo '<div style="display:none;" aria-hidden="true">';
        echo '<label>Leave this empty <input type="text" name="taw_hp_check" value="" tabindex="-1" autocomplete="off"></label>';
        echo '</div>';

        $this->renderStyles();

        echo '<div class="hidden taw-general-error" data-taw-general-error role="alert"></div>';

        if ($isMultiStep) {
            $this->renderMultiStepBody();
        } else {
            $submitLabel  = $this->config['submit_label'] ?? __('Send Message', 'taw');
            $loadingLabel = $this->config['submit_loading_label'] ?? __('Sending...', 'taw');

            echo '<div class="taw-form-grid">';
            foreach (($this->config['fields'] ?? []) as $field) {
                $this->renderField($field);
            }
            echo '</div>';

            echo '<div class="hidden taw-success" data-taw-success role="status"></div>';

            echo '<div class="taw-form-actions">';
            $this->renderSubmitButton($submitLabel, $loadingLabel);
            echo '</div>';
        }

        echo '</form>';

        $this->renderScript();
    }

    private function renderMultiStepBody(): void
    {
        $steps        = $this->config['steps'];
        $count        = count($steps);
        $submitLabel  = $this->config['submit_label'] ?? __('Submit', 'taw');
        $loadingLabel = $this->config['submit_loading_label'] ?? __('Sending...', 'taw');
        $prevLabel    = $this->config['prev_label'] ?? __('Back', 'taw');
        $nextLabel    = $this->config['next_label'] ?? __('Next', 'taw');

        // ── Step indicator ────────────────────────────────────────
        echo '<div class="taw-step-indicator" data-taw-step-indicator>';
        foreach ($steps as $i => $step) {
            echo '<div class="taw-step-dot' . ($i === 0 ? ' is-active' : '') . '" data-taw-step-dot="' . $i . '">';
            echo '<span class="taw-step-dot-number">' . ($i + 1) . '</span>';
            if (!empty($step['title'])) {
                echo '<span class="taw-step-dot-label">' . esc_html($step['title']) . '</span>';
            }
            echo '</div>';

            if ($i < $count - 1) {
                echo '<div class="taw-step-connector" data-taw-step-connector="' . $i . '"></div>';
            }
        }
        echo '</div>';

        // ── Step panels ───────────────────────────────────────────
        foreach ($steps as $i => $step) {
            echo '<div class="taw-step-panel" data-taw-step-panel="' . $i . '"' . ($i > 0 ? ' hidden' : '') . '>';
            echo '<div class="taw-form-grid">';
            foreach (($step['fields'] ?? []) as $field) {
                $this->renderField($field);
            }
            echo '</div>';
            echo '</div>';
        }

        echo '<div class="hidden taw-success" data-taw-success role="status"></div>';

        // ── Navigation buttons ────────────────────────────────────
        echo '<div class="taw-form-actions" data-taw-form-actions>';

        echo '<button type="button" class="taw-btn taw-btn-secondary" data-taw-prev hidden>';
        echo esc_html($prevLabel);
        echo '</button>';

        echo '<button type="button" class="taw-btn taw-btn-primary" data-taw-next>';
        echo esc_html($nextLabel);
        echo '</button>';

        echo '<div data-taw-submit-wrap hidden>';
        $this->renderSubmitButton($submitLabel, $loadingLabel);
        echo '</div>';

        echo '</div>';
    }

    private function renderSubmitButton(string $label, string $loadingLabel): void
    {
        echo '<button type="submit" data-taw-submit'
            . ' data-loading-label="' . esc_attr($loadingLabel) . '"'
            . ' class="taw-btn taw-btn-primary">';
        echo '<svg class="hidden animate-spin taw-btn-spinner" data-taw-spinner'
            . ' xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">'
            . '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>'
            . '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>'
            . '</svg>';
        echo '<span data-taw-submit-label>' . esc_html($label) . '</span>';
        echo '</button>';
    }

    /* -------------------------------------------------------------------------
     * Field rendering
     * ---------------------------------------------------------------------- */

    private function renderField(array $field): void
    {
        $type = $field['type'] ?? 'text';

        // ── Structural types ──────────────────────────────────────
        if (in_array($type, self::STRUCTURAL_TYPES, true)) {
            $width = max(1, min(100, (int) ($field['width'] ?? 100)));
            $span  = max(1, min(12, (int) round($width / 100 * 12)));
            $style = 'grid-column: span ' . $span . ' / span ' . $span . ';';

            switch ($type) {
                case 'html':
                    echo '<div style="' . $style . '">' . wp_kses_post($field['content'] ?? '') . '</div>';
                    return;

                case 'heading':
                    echo '<div class="taw-field-heading" style="' . $style . '">';
                    echo '<h3 class="taw-heading-label">' . esc_html($field['label'] ?? '') . '</h3>';
                    if (!empty($field['subtitle'])) {
                        echo '<p class="taw-heading-subtitle">' . esc_html($field['subtitle']) . '</p>';
                    }
                    echo '</div>';
                    return;

                case 'divider':
                    echo '<hr class="taw-divider" style="' . $style . '">';
                    return;
            }
        }

        // ── Input fields ──────────────────────────────────────────
        $id          = $field['id'];
        $label       = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required    = !empty($field['required']);
        $conditions  = $field['conditions'] ?? [];
        $inputClass  = 'w-full p-2 border border-stone-400 rounded-md bg-white'
                     . ' focus:outline-none focus:ring-2 focus:ring-stone-500 focus:border-transparent transition-all';

        $width = max(1, min(100, (int) ($field['width'] ?? 100)));
        $span  = max(1, min(12, (int) round($width / 100 * 12)));

        $wrapAttrs = 'class="taw-form-field flex flex-col gap-1"'
            . ' style="grid-column: span ' . $span . ' / span ' . $span . ';"'
            . ' data-taw-field-wrap="' . esc_attr($id) . '"';

        if (!empty($conditions)) {
            $wrapAttrs .= ' data-taw-conditions="' . esc_attr(wp_json_encode($conditions)) . '" hidden';
        }

        echo '<div ' . $wrapAttrs . '>';

        $labellessTypes = ['checkbox', 'radio', 'checkbox_group'];

        if ($label && !in_array($type, $labellessTypes, true)) {
            echo '<label for="' . esc_attr($id) . '" class="font-semibold text-stone-700">';
            echo esc_html($label);
            if ($required) {
                echo ' <span class="text-red-500" aria-hidden="true">*</span>';
            }
            echo '</label>';
        }

        switch ($type) {

            case 'textarea':
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="%2$d" class="%3$s" placeholder="%4$s"></textarea>',
                    esc_attr($id),
                    intval($field['rows'] ?? 4),
                    $inputClass,
                    esc_attr($placeholder)
                );
                break;

            case 'select':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" class="' . $inputClass . '">';
                echo '<option value="" disabled selected>' . esc_html__('Select an option…', 'taw') . '</option>';
                foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                    echo '<option value="' . esc_attr($optVal) . '">' . esc_html($optLabel) . '</option>';
                }
                echo '</select>';
                break;

            case 'radio':
                if ($label) {
                    echo '<span class="font-semibold text-stone-700">' . esc_html($label);
                    if ($required) {
                        echo ' <span class="text-red-500" aria-hidden="true">*</span>';
                    }
                    echo '</span>';
                }
                $layout = $field['layout'] ?? 'horizontal'; // 'horizontal' | 'vertical'
                $groupClass = $layout === 'vertical'
                    ? 'flex flex-col gap-2'
                    : 'flex flex-wrap gap-x-4 gap-y-2';
                echo '<div class="' . $groupClass . '" role="radiogroup">';
                foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                    $optId = esc_attr($id . '_' . $optVal);
                    echo '<label class="flex items-center gap-2 cursor-pointer">';
                    printf(
                        '<input type="radio" id="%s" name="%s" value="%s">',
                        $optId,
                        esc_attr($id),
                        esc_attr($optVal)
                    );
                    echo '<span>' . esc_html($optLabel) . '</span>';
                    echo '</label>';
                }
                echo '</div>';
                break;

            case 'checkbox_group':
                if ($label) {
                    echo '<span class="font-semibold text-stone-700">' . esc_html($label);
                    if ($required) {
                        echo ' <span class="text-red-500" aria-hidden="true">*</span>';
                    }
                    echo '</span>';
                }
                $layout = $field['layout'] ?? 'horizontal';
                $groupClass = $layout === 'vertical'
                    ? 'flex flex-col gap-2'
                    : 'flex flex-wrap gap-x-4 gap-y-2';
                echo '<div class="' . $groupClass . '">';
                foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                    $optId = esc_attr($id . '_' . $optVal);
                    echo '<label class="flex items-center gap-2 cursor-pointer">';
                    printf(
                        '<input type="checkbox" id="%s" name="%s[]" value="%s" class="rounded border-stone-400">',
                        $optId,
                        esc_attr($id),
                        esc_attr($optVal)
                    );
                    echo '<span>' . esc_html($optLabel) . '</span>';
                    echo '</label>';
                }
                echo '</div>';
                break;

            case 'checkbox':
                echo '<label class="flex items-center gap-2 cursor-pointer select-none">';
                printf(
                    '<input type="checkbox" id="%1$s" name="%1$s" value="1" class="rounded border-stone-400 cursor-pointer">',
                    esc_attr($id)
                );
                if ($label) {
                    echo '<span class="text-stone-700">' . esc_html($label) . '</span>';
                }
                echo '</label>';
                break;

            case 'date':
                $min = !empty($field['min_date']) ? ' min="' . esc_attr($field['min_date']) . '"' : '';
                $max = !empty($field['max_date']) ? ' max="' . esc_attr($field['max_date']) . '"' : '';
                printf(
                    '<input type="date" id="%1$s" name="%1$s" class="%2$s"%3$s%4$s>',
                    esc_attr($id),
                    $inputClass,
                    $min,
                    $max
                );
                break;

            default:
                // Covers: text, email, url, tel, number, password, and any valid HTML input type.
                printf(
                    '<input type="%1$s" id="%2$s" name="%2$s" class="%3$s" placeholder="%4$s">',
                    esc_attr($type),
                    esc_attr($id),
                    $inputClass,
                    esc_attr($placeholder)
                );
                break;
        }

        echo '<span class="hidden text-sm text-red-600" data-taw-field-error="' . esc_attr($id) . '" role="alert"></span>';
        echo '</div>';
    }

    /* -------------------------------------------------------------------------
     * Styles
     * ---------------------------------------------------------------------- */

    private function renderStyles(): void
    {
        $fid = esc_attr($this->id);
        ?>
        <style>
        [data-taw-form="<?php echo $fid; ?>"] .taw-form-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 1rem;
        }
        @media (max-width: 639px) {
            [data-taw-form="<?php echo $fid; ?>"] .taw-form-field {
                grid-column: 1 / -1 !important;
            }
        }

        /* ── Step indicator ─────────────────────────────── */
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-indicator {
            display: flex;
            align-items: flex-start;
            margin-bottom: 2rem;
            overflow-x: auto;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-dot {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            flex-shrink: 0;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-dot-number {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: #e7e5e4;
            color: #78716c;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            font-weight: 600;
            transition: background .2s, color .2s;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-dot.is-active .taw-step-dot-number {
            background: #1c1917;
            color: #fff;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-dot.is-done .taw-step-dot-number {
            background: #57534e;
            color: #fff;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-dot-label {
            font-size: 0.7rem;
            color: #78716c;
            text-align: center;
            max-width: 80px;
            line-height: 1.3;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-dot.is-active .taw-step-dot-label {
            color: #1c1917;
            font-weight: 600;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-connector {
            flex: 1;
            height: 2px;
            background: #e7e5e4;
            margin-top: 1rem;
            min-width: 1.5rem;
            transition: background .2s;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-step-connector.is-done {
            background: #57534e;
        }

        /* ── Structural field types ─────────────────────── */
        [data-taw-form="<?php echo $fid; ?>"] .taw-field-heading {
            padding: 0.625rem 0.875rem;
            background: #1c1917;
            color: #fff;
            border-radius: 0.375rem;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-heading-label {
            font-size: 0.9375rem;
            font-weight: 700;
            margin: 0;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-heading-subtitle {
            font-size: 0.8rem;
            opacity: 0.65;
            font-style: italic;
            margin: 0.2rem 0 0;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-divider {
            border: none;
            border-top: 1px solid #e7e5e4;
            margin: 0;
        }

        /* ── Feedback banners ───────────────────────────── */
        [data-taw-form="<?php echo $fid; ?>"] .taw-general-error {
            padding: 0.75rem 1rem;
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
            border-radius: 0.5rem;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-success {
            padding: 0.75rem 1rem;
            color: #166534;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            border-radius: 0.5rem;
        }

        /* ── Buttons ────────────────────────────────────── */
        [data-taw-form="<?php echo $fid; ?>"] .taw-form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            margin-top: 1.5rem;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-btn {
            padding: 0.5rem 1.25rem;
            border-radius: 0.375rem;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s, color .15s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid transparent;
            font-size: 0.9375rem;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-btn-primary {
            background: #1c1917;
            color: #d6d3d1;
            border-color: #1c1917;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-btn-primary:hover:not(:disabled) { background: #44403c; }
        [data-taw-form="<?php echo $fid; ?>"] .taw-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        [data-taw-form="<?php echo $fid; ?>"] .taw-btn-secondary {
            background: transparent;
            color: #44403c;
            border-color: #a8a29e;
        }
        [data-taw-form="<?php echo $fid; ?>"] .taw-btn-secondary:hover { background: #f5f5f4; }
        [data-taw-form="<?php echo $fid; ?>"] .taw-btn-spinner {
            width: 1rem;
            height: 1rem;
        }
        </style>
        <?php
    }

    /* -------------------------------------------------------------------------
     * Script
     * ---------------------------------------------------------------------- */

    private function renderScript(): void
    {
        $formId       = esc_js($this->id);
        $isMultiStep  = $this->isMultiStep();
        $stepCount    = $isMultiStep ? count($this->config['steps']) : 0;
        $submitLabel  = esc_js($this->config['submit_label'] ?? ($isMultiStep ? __('Submit', 'taw') : __('Send Message', 'taw')));
        $loadingLabel = esc_js($this->config['submit_loading_label'] ?? __('Sending…', 'taw'));
        $networkError = esc_js(__('A network error occurred. Please try again.', 'taw'));
        $requiredMsg  = esc_js(__('This field is required.', 'taw'));

        $stepRequired = wp_json_encode($this->buildStepRequiredMap());
        $fieldToStep  = wp_json_encode($this->buildFieldToStepMap());
        ?>
        <script>
        (function () {
            'use strict';

            var form = document.querySelector('[data-taw-form="<?php echo $formId; ?>"]');
            if (!form) return;

            var submitBtn  = form.querySelector('[data-taw-submit]');
            var spinner    = form.querySelector('[data-taw-spinner]');
            var submitLbl  = form.querySelector('[data-taw-submit-label]');
            var successEl  = form.querySelector('[data-taw-success]');
            var generalEl  = form.querySelector('[data-taw-general-error]');

            var isMultiStep       = form.hasAttribute('data-taw-multistep');
            var stepCount         = <?php echo $stepCount; ?>;
            var currentStep       = 0;
            var stepRequiredMap   = <?php echo $stepRequired; ?>;
            var fieldToStepMap    = <?php echo $fieldToStep; ?>;

            // ── Conditions ──────────────────────────────────────────

            function getFieldValue(name) {
                // radio
                var radio = form.querySelector('[name="' + CSS.escape(name) + '"]:checked');
                if (radio && radio.type === 'radio') return radio.value;

                // checkbox_group (name ends with [])
                var checks = form.querySelectorAll('[name="' + CSS.escape(name) + '[]"]:checked');
                if (checks.length) {
                    return Array.from(checks).map(function(el) { return el.value; }).join(',');
                }

                // single checkbox
                var cb = form.querySelector('[name="' + CSS.escape(name) + '"][type="checkbox"]');
                if (cb) return cb.checked ? '1' : '0';

                var el = form.querySelector('[name="' + CSS.escape(name) + '"]');
                return el ? el.value : '';
            }

            function evalRule(rule) {
                var actual   = getFieldValue(rule.field);
                var expected = String(rule.value);
                switch (rule.operator) {
                    case '!=':       return actual !== expected;
                    case '>':        return parseFloat(actual) >  parseFloat(expected);
                    case '<':        return parseFloat(actual) <  parseFloat(expected);
                    case '>=':       return parseFloat(actual) >= parseFloat(expected);
                    case '<=':       return parseFloat(actual) <= parseFloat(expected);
                    case 'contains': return actual.indexOf(expected) !== -1;
                    default:         return actual === expected;
                }
            }

            function evaluateConditions() {
                form.querySelectorAll('[data-taw-conditions]').forEach(function (wrap) {
                    var cfg;
                    try { cfg = JSON.parse(wrap.getAttribute('data-taw-conditions')); } catch (e) { return; }

                    var relation = cfg.relation || 'all';
                    var rules    = Array.isArray(cfg.rules) ? cfg.rules
                                 : Array.isArray(cfg)       ? cfg
                                 : [];
                    rules = rules.filter(function(r) { return r && r.field; });

                    var met;
                    if (!rules.length) {
                        met = true;
                    } else if (relation === 'any') {
                        met = rules.some(evalRule);
                    } else {
                        met = rules.every(evalRule);
                    }

                    wrap.hidden = !met;
                    wrap.querySelectorAll('input, select, textarea').forEach(function (el) {
                        el.disabled = !met;
                    });
                });
            }

            evaluateConditions();
            form.addEventListener('change', evaluateConditions);
            form.addEventListener('input',  evaluateConditions);

            // ── Multi-step ──────────────────────────────────────────

            var updateStepUI = function() {}; // no-op for single-step forms

            if (isMultiStep) {
                var prevBtn    = form.querySelector('[data-taw-prev]');
                var nextBtn    = form.querySelector('[data-taw-next]');
                var submitWrap = form.querySelector('[data-taw-submit-wrap]');
                var panels     = form.querySelectorAll('[data-taw-step-panel]');
                var dots       = form.querySelectorAll('[data-taw-step-dot]');
                var connectors = form.querySelectorAll('[data-taw-step-connector]');

                updateStepUI = function () {
                    panels.forEach(function (p, i) { p.hidden = i !== currentStep; });

                    dots.forEach(function (dot, i) {
                        dot.classList.toggle('is-active', i === currentStep);
                        dot.classList.toggle('is-done',   i < currentStep);
                    });

                    connectors.forEach(function (c, i) {
                        c.classList.toggle('is-done', i < currentStep);
                    });

                    if (prevBtn)    prevBtn.hidden    = currentStep === 0;
                    if (nextBtn)    nextBtn.hidden    = currentStep === stepCount - 1;
                    if (submitWrap) submitWrap.hidden = currentStep !== stepCount - 1;
                };

                function validateStep(index) {
                    var required = stepRequiredMap[index] || [];
                    var valid    = true;
                    clearErrors();

                    required.forEach(function (fieldId) {
                        var wrap = form.querySelector('[data-taw-field-wrap="' + fieldId + '"]');
                        if (wrap && wrap.hidden) return; // condition hid this field

                        if (!getFieldValue(fieldId).trim()) {
                            showFieldError(fieldId, '<?php echo $requiredMsg; ?>');
                            valid = false;
                        }
                    });

                    return valid;
                }

                if (nextBtn) {
                    nextBtn.addEventListener('click', function () {
                        if (!validateStep(currentStep)) return;
                        if (currentStep < stepCount - 1) {
                            currentStep++;
                            updateStepUI();
                            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });
                }

                if (prevBtn) {
                    prevBtn.addEventListener('click', function () {
                        if (currentStep > 0) {
                            clearErrors();
                            currentStep--;
                            updateStepUI();
                            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });
                }

                updateStepUI();
            }

            // ── Loading state ───────────────────────────────────────

            function setLoading(on) {
                if (submitBtn) submitBtn.disabled = on;
                if (spinner)   spinner.classList.toggle('hidden', !on);
                if (submitLbl) submitLbl.textContent = on ? '<?php echo $loadingLabel; ?>' : '<?php echo $submitLabel; ?>';
            }

            // ── Error helpers ───────────────────────────────────────

            function clearErrors() {
                form.querySelectorAll('[data-taw-field-error]').forEach(function (el) {
                    el.textContent = '';
                    el.classList.add('hidden');
                });
                if (generalEl) { generalEl.textContent = ''; generalEl.classList.add('hidden'); }
                if (successEl) { successEl.textContent = ''; successEl.classList.add('hidden'); }
            }

            function showFieldError(fieldId, message) {
                var el = form.querySelector('[data-taw-field-error="' + fieldId + '"]');
                if (!el) return;
                el.textContent = message;
                el.classList.remove('hidden');
            }

            // ── Submission ──────────────────────────────────────────

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                clearErrors();
                setLoading(true);

                fetch(form.getAttribute('action'), {
                    method:      'POST',
                    credentials: 'same-origin',
                    body:        new FormData(form),
                })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function (json) {
                    setLoading(false);

                    if (json.success) {
                        form.reset();
                        evaluateConditions();

                        if (isMultiStep) {
                            form.querySelectorAll('[data-taw-step-panel], .taw-step-indicator, [data-taw-form-actions]')
                                .forEach(function (el) { el.hidden = true; });
                        }

                        if (successEl) {
                            successEl.textContent = json.data && json.data.message ? json.data.message : '';
                            successEl.classList.remove('hidden');
                        }
                        return;
                    }

                    var payload = json.data || {};

                    if (payload.general && generalEl) {
                        generalEl.textContent = payload.general;
                        generalEl.classList.remove('hidden');
                    }

                    if (payload.errors) {
                        Object.keys(payload.errors).forEach(function (fieldId) {
                            showFieldError(fieldId, payload.errors[fieldId]);
                        });

                        // Navigate to the step containing the first error.
                        if (isMultiStep) {
                            var earliest = null;
                            Object.keys(payload.errors).forEach(function (fieldId) {
                                var s = fieldToStepMap[fieldId];
                                if (s !== undefined && (earliest === null || s < earliest)) {
                                    earliest = s;
                                }
                            });
                            if (earliest !== null) {
                                currentStep = earliest;
                                updateStepUI();
                            }
                        }
                    }
                })
                .catch(function () {
                    setLoading(false);
                    if (generalEl) {
                        generalEl.textContent = '<?php echo $networkError; ?>';
                        generalEl.classList.remove('hidden');
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
