<?php

declare(strict_types=1);

namespace TAW\Core\Form;

use TAW\Core\Mail\Mailer;
use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Form — configuration-driven frontend form with AJAX processing, validation, and email delivery.
 *
 * Features:
 *  - CSRF protection (WordPress nonces)
 *  - Honeypot spam protection
 *  - Rate limiting (per-IP, per-form, transient-backed — 5 attempts/60s by default)
 *  - Optional Cloudflare Turnstile bot verification ('turnstile' => true + wp-config.php keys)
 *  - Field sanitization & validation, including optional min_length/max_length/pattern/min/max rules,
 *    each with an optional per-field custom message ('{rule}_message', e.g. 'required_message').
 *    A form-wide default can also be set once via 'messages' => ['required' => '...', 'email' => '...',
 *    'min_length' => '...', 'max_length' => '...', 'pattern' => '...', 'min' => '...', 'max' => '...']
 *    (label/value placeholders use sprintf's %s/%d — see the built-in defaults for the exact signature
 *    per rule). Precedence: field-level '{rule}_message' > form-level 'messages.{rule}' > English default.
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
 *       'rate_limit'   => ['max' => 5, 'window' => 60], // optional — this is the default; pass false to disable
 *       'turnstile'    => true, // optional — requires TAW_TURNSTILE_SITE_KEY/SECRET_KEY in wp-config.php
 *       'fields'       => [
 *           ['id' => 'name',    'label' => 'Name',    'type' => 'text',  'required' => true, 'required_message' => 'Please tell us your name.', 'min_length' => 2, 'max_length' => 80],
 *           ['id' => 'email',   'label' => 'Email',   'type' => 'email', 'required' => true, 'email_message' => 'That doesn\'t look like a real email address.'],
 *           ['id' => 'phone',   'label' => 'Phone',   'type' => 'tel', 'pattern' => '[0-9+ ()-]{7,20}', 'pattern_message' => 'Enter a valid phone number.'],
 *           ['id' => 'guests',  'label' => 'Guests',  'type' => 'number', 'min' => 1, 'max' => 20],
 *           ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'max_length' => 2000],
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

    /**
     * Get every registered form, keyed by id.
     *
     * @return array<string, self>
     */
    public static function getAll(): array
    {
        return self::$registry;
    }

    /**
     * The raw config array this form was registered with.
     */
    public function getConfig(): array
    {
        return $this->config;
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

        add_action('wp_enqueue_scripts', static function () {
            wp_enqueue_style('taw-form', Framework::url('assets/form.css'), [], Framework::version());
        });
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
        // Rate limit BEFORE the nonce check — a flooding script doesn't need a
        // valid nonce to cause load (check_ajax_referer() dies cheaply on a bad
        // nonce, but WP still bootstraps the full request each time), so this
        // has to be the very first thing that runs.
        $rateLimit = $this->config['rate_limit'] ?? ['max' => 5, 'window' => 60];
        if ($rateLimit !== false) {
            $max = $rateLimit['max'] ?? 5;
            $window = $rateLimit['window'] ?? 60;
            $ip = SubmissionsHandler::getUserIp();

            if (RateLimiter::tooManyAttempts($this->id, $ip, $max, $window)) {
                wp_send_json_error([
                    'general' => $this->config['messages']['rate_limited']
                        ?? __('Too many attempts. Please wait a moment and try again.', 'taw'),
                ]);
            }
        }

        check_ajax_referer($this->id . '_action', $this->id . '_nonce');

        // Honeypot — silently succeed so bots think they submitted correctly.
        if (!empty($_POST['taw_hp_check'])) {
            wp_send_json_success();
        }

        // Turnstile — checked after the cheap local checks above (rate limit,
        // nonce, honeypot), since this one costs an outbound HTTP call to
        // Cloudflare; no point paying that cost for a request that was going
        // to be rejected locally anyway.
        if (!empty($this->config['turnstile']) && Turnstile::isConfigured()) {
            $token = isset($_POST['cf-turnstile-response']) ? (string) wp_unslash($_POST['cf-turnstile-response']) : '';

            if (!Turnstile::verify($token, SubmissionsHandler::getUserIp())) {
                wp_send_json_error([
                    'general' => $this->config['messages']['turnstile_failed']
                        ?? __('We could not verify you are human. Please try again.', 'taw'),
                ]);
            }
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
                $errors[$fieldId] = $this->requiredMessage($field, $label);
                continue;
            }

            // Skip length/pattern/range checks on an empty, non-required field —
            // "optional and blank" shouldn't fail a min_length/pattern rule.
            if ('' !== trim((string) $value)) {
                $ruleError = $this->validateRules($field, (string) $value, $label);
                if ($ruleError !== null) {
                    $errors[$fieldId] = $ruleError;
                    continue;
                }
            }

            $sanitized = $this->sanitize($value, $field['type'] ?? 'text');

            if (($field['type'] ?? '') === 'email' && !empty($sanitized) && !is_email($sanitized)) {
                $errors[$fieldId] = $this->emailMessage($field);
            }

            $data[$fieldId] = $sanitized;
        }

        if (!empty($errors)) {
            wp_send_json_error(['errors' => $errors]);
        }

        $pageUrl = isset($_POST['taw_form_page_url'])
            ? esc_url_raw(wp_unslash($_POST['taw_form_page_url']))
            : '';

        // Save first — guaranteed record regardless of email outcome.
        SubmissionsHandler::saveSubmission($this->id, $inputFields, $data, $this->config['webhook'] ?? [], $pageUrl);

        $sent = $this->sendEmail($data);
        if (!$sent) {
            error_log('[TAW Form] Email delivery failed for form "' . $this->id . '" — submission was saved to the database.');
        }

        wp_send_json_success([
            'message' => $this->config['messages']['success'] ?? __('Thank you! Your message has been sent.', 'taw'),
        ]);
    }

    /* -------------------------------------------------------------------------
     * Sanitize / Conditions
     * ---------------------------------------------------------------------- */

    /**
     * Resolve the error message for a missing required field. Precedence:
     * field-level 'required_message' > form-level 'messages.required' >
     * built-in English default.
     */
    private function requiredMessage(array $field, string $label): string
    {
        /* translators: %s: field label */
        $template = $this->config['messages']['required'] ?? __('%s is required.', 'taw');

        return $field['required_message'] ?? sprintf($template, $label);
    }

    /**
     * Resolve the error message for an invalid email field. Precedence:
     * field-level 'email_message' > form-level 'messages.email' > built-in
     * English default. No label interpolation — unlike the other rules,
     * the built-in default doesn't reference the field name.
     */
    private function emailMessage(array $field): string
    {
        return $field['email_message']
            ?? $this->config['messages']['email']
            ?? __('Invalid email address.', 'taw');
    }

    /**
     * Validate a non-empty field value against its optional min_length,
     * max_length, pattern, min, and max rules. Only called for values that
     * already passed the required check and aren't blank — an optional
     * empty field never fails these.
     *
     * @return string|null Error message, or null if the value is valid.
     */
    private function validateRules(array $field, string $value, string $label): ?string
    {
        if (isset($field['min_length']) && mb_strlen($value) < (int) $field['min_length']) {
            /* translators: 1: field label, 2: minimum length */
            $template = $this->config['messages']['min_length'] ?? __('%1$s must be at least %2$d characters.', 'taw');
            return $field['min_length_message'] ?? sprintf($template, $label, (int) $field['min_length']);
        }

        if (isset($field['max_length']) && mb_strlen($value) > (int) $field['max_length']) {
            /* translators: 1: field label, 2: maximum length */
            $template = $this->config['messages']['max_length'] ?? __('%1$s must be no more than %2$d characters.', 'taw');
            return $field['max_length_message'] ?? sprintf($template, $label, (int) $field['max_length']);
        }

        if (!empty($field['pattern'])) {
            // Field-authored regex, not user input — @ delimiter avoids clashing
            // with a pattern that itself contains a forward slash.
            $matched = @preg_match('@^(?:' . $field['pattern'] . ')$@u', $value);
            if ($matched === false) {
                // Malformed pattern in the field config itself — a developer
                // error, not a user-input problem. Fail safe (reject) and log,
                // rather than silently accepting anything.
                error_log('[TAW Form] Invalid pattern in field "' . $field['id'] . '": ' . $field['pattern']);
                return sprintf(__('%s could not be validated.', 'taw'), $label);
            }
            if ($matched === 0) {
                $template = $this->config['messages']['pattern'] ?? __('%s is not in the correct format.', 'taw');
                return $field['pattern_message'] ?? sprintf($template, $label);
            }
        }

        if (($field['type'] ?? '') === 'number' && is_numeric($value)) {
            $numeric = (float) $value;

            if (isset($field['min']) && $numeric < (float) $field['min']) {
                /* translators: 1: field label, 2: minimum value */
                $template = $this->config['messages']['min'] ?? __('%1$s must be at least %2$s.', 'taw');
                return $field['min_message'] ?? sprintf($template, $label, $field['min']);
            }

            if (isset($field['max']) && $numeric > (float) $field['max']) {
                /* translators: 1: field label, 2: maximum value */
                $template = $this->config['messages']['max'] ?? __('%1$s must be no more than %2$s.', 'taw');
                return $field['max_message'] ?? sprintf($template, $label, $field['max']);
            }
        }

        return null;
    }

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

    /**
     * The full URL of the page currently being rendered, built from the
     * actual request rather than get_permalink() — forms can be embedded
     * outside The Loop (headers, footers, sidebars) where a global $post
     * isn't reliably available, and this needs to work in every context.
     */
    private static function currentPageUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';

        return (is_ssl() ? 'https://' : 'http://') . $host . $uri;
    }

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

        // Captured server-side at render time (not read from the submission
        // request's Referer header, which browsers/privacy tools can strip
        // or omit) — the same form can be embedded on many different pages,
        // and downstream automations (an n8n webhook routing submissions to
        // different spreadsheets by page/section, for example) need to know
        // which one this particular submission came from.
        echo '<input type="hidden" name="taw_form_page_url" value="' . esc_attr(self::currentPageUrl()) . '">';

        // Honeypot — hidden from real users, filled by bots.
        echo '<div style="display:none;" aria-hidden="true">';
        echo '<label>Leave this empty <input type="text" name="taw_hp_check" value="" tabindex="-1" autocomplete="off"></label>';
        echo '</div>';

        if (!empty($this->config['turnstile'])) {
            $this->renderTurnstile();
        }

        echo '<div class="taw-hidden taw-general-error" data-taw-general-error role="alert"></div>';

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

            echo '<div class="taw-hidden taw-success" data-taw-success role="status"></div>';

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

        // ── Compact progress (mobile) ─────────────────────────────
        $firstTitle = $steps[0]['title'] ?? '';
        echo '<div class="taw-step-progress" data-taw-step-progress aria-hidden="true">';
        echo '<div class="taw-step-progress-meta">';
        echo '<span class="taw-step-progress-count" data-taw-step-progress-count>1 / ' . $count . '</span>';
        echo '<span class="taw-step-progress-title" data-taw-step-progress-title>' . esc_html($firstTitle) . '</span>';
        echo '</div>';
        echo '<div class="taw-step-progress-track"><div class="taw-step-progress-fill" data-taw-step-progress-fill></div></div>';
        echo '</div>';

        // ── Step indicator (desktop) ──────────────────────────────
        echo '<div class="taw-step-indicator" data-taw-step-indicator aria-label="Form progress">';
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

        echo '<div class="taw-hidden taw-success" data-taw-success role="status"></div>';

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
        echo '<svg class="taw-hidden taw-btn-spinner" data-taw-spinner'
            . ' xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">'
            . '<circle class="taw-spinner-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>'
            . '<path class="taw-spinner-fill" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>'
            . '</svg>';
        echo '<span data-taw-submit-label>' . esc_html($label) . '</span>';
        echo '</button>';
    }

    /**
     * Render the Cloudflare Turnstile widget, if configured. A form can opt
     * in via `'turnstile' => true`, but this only actually renders anything
     * when TAW_TURNSTILE_SITE_KEY is defined — an opted-in form on a site
     * that hasn't configured keys yet degrades to "no widget, no
     * server-side check" (see process()) rather than blocking submission
     * outright, with a WP_DEBUG-only notice so misconfiguration is visible
     * to developers, not to visitors.
     */
    private function renderTurnstile(): void
    {
        $siteKey = Turnstile::siteKey();

        if ($siteKey === null) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    '[TAW Form] Form "' . esc_html($this->id) . '" has \'turnstile\' => true, '
                    . 'but TAW_TURNSTILE_SITE_KEY/TAW_TURNSTILE_SECRET_KEY are not defined. '
                    . 'The widget will not render and no verification will run.',
                    E_USER_WARNING
                );
            }
            return;
        }

        Turnstile::enqueueScript();

        echo '<div class="cf-turnstile" data-sitekey="' . esc_attr($siteKey) . '" data-theme="light"></div>';
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
        $inputClass  = 'taw-input';

        $width = max(1, min(100, (int) ($field['width'] ?? 100)));
        $span  = max(1, min(12, (int) round($width / 100 * 12)));

        $wrapAttrs = 'class="taw-form-field"'
            . ' style="grid-column: span ' . $span . ' / span ' . $span . ';"'
            . ' data-taw-field-wrap="' . esc_attr($id) . '"';

        if (!empty($conditions)) {
            $wrapAttrs .= ' data-taw-conditions="' . esc_attr(wp_json_encode($conditions)) . '" hidden';
        }

        echo '<div ' . $wrapAttrs . '>';

        $labellessTypes = ['checkbox', 'radio', 'checkbox_group'];

        if ($label && !in_array($type, $labellessTypes, true)) {
            echo '<label for="' . esc_attr($id) . '" class="taw-field-label">';
            echo esc_html($label);
            if ($required) {
                echo ' <span class="taw-required" aria-hidden="true">*</span>';
            }
            echo '</label>';
        }

        switch ($type) {

            case 'textarea':
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="%2$d" class="%3$s" placeholder="%4$s"%5$s></textarea>',
                    esc_attr($id),
                    intval($field['rows'] ?? 4),
                    $inputClass,
                    esc_attr($placeholder),
                    $this->lengthAttrs($field)
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
                    echo '<span class="taw-field-label">' . esc_html($label);
                    if ($required) {
                        echo ' <span class="taw-required" aria-hidden="true">*</span>';
                    }
                    echo '</span>';
                }
                $layout = $field['layout'] ?? 'horizontal';
                $groupClass = $layout === 'vertical'
                    ? 'taw-option-group taw-option-group--vertical'
                    : 'taw-option-group';
                echo '<div class="' . $groupClass . '" role="radiogroup">';
                foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                    $optId = esc_attr($id . '_' . $optVal);
                    echo '<label class="taw-option-label">';
                    printf(
                        '<input type="radio" id="%s" name="%s" value="%s" class="taw-check">',
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
                    echo '<span class="taw-field-label">' . esc_html($label);
                    if ($required) {
                        echo ' <span class="taw-required" aria-hidden="true">*</span>';
                    }
                    echo '</span>';
                }
                $layout = $field['layout'] ?? 'horizontal';
                $groupClass = $layout === 'vertical'
                    ? 'taw-option-group taw-option-group--vertical'
                    : 'taw-option-group';
                echo '<div class="' . $groupClass . '">';
                foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                    $optId = esc_attr($id . '_' . $optVal);
                    echo '<label class="taw-option-label">';
                    printf(
                        '<input type="checkbox" id="%s" name="%s[]" value="%s" class="taw-check">',
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
                echo '<label class="taw-option-label">';
                printf(
                    '<input type="checkbox" id="%1$s" name="%1$s" value="1" class="taw-check">',
                    esc_attr($id)
                );
                if ($label) {
                    echo '<span>' . esc_html($label) . '</span>';
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
                    '<input type="%1$s" id="%2$s" name="%2$s" class="%3$s" placeholder="%4$s"%5$s%6$s>',
                    esc_attr($type),
                    esc_attr($id),
                    $inputClass,
                    esc_attr($placeholder),
                    $this->lengthAttrs($field),
                    $type === 'number' ? $this->rangeAttrs($field) : ''
                );
                break;
        }

        echo '<span class="taw-hidden taw-field-error" data-taw-field-error="' . esc_attr($id) . '" role="alert"></span>';
        echo '</div>';
    }

    /**
     * HTML attribute string for min_length/max_length/pattern — native
     * browser-level validation as a UX nicety. The authoritative check is
     * always server-side in process() via validateRules(); these attributes
     * can be removed from the DOM trivially, so they're a convenience, not
     * a security boundary.
     */
    private function lengthAttrs(array $field): string
    {
        $attrs = '';

        if (isset($field['min_length'])) {
            $attrs .= ' minlength="' . (int) $field['min_length'] . '"';
        }
        if (isset($field['max_length'])) {
            $attrs .= ' maxlength="' . (int) $field['max_length'] . '"';
        }
        if (!empty($field['pattern'])) {
            $attrs .= ' pattern="' . esc_attr($field['pattern']) . '"';
        }

        return $attrs;
    }

    /**
     * HTML attribute string for a number field's min/max — same
     * convenience-only caveat as lengthAttrs().
     */
    private function rangeAttrs(array $field): string
    {
        $attrs = '';

        if (isset($field['min'])) {
            $attrs .= ' min="' . esc_attr((string) $field['min']) . '"';
        }
        if (isset($field['max'])) {
            $attrs .= ' max="' . esc_attr((string) $field['max']) . '"';
        }

        return $attrs;
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

        $stepTitles = wp_json_encode(
            $isMultiStep
                ? array_values(array_map(fn($s) => $s['title'] ?? '', $this->config['steps']))
                : []
        );
        ?>
        <script>
        (function () {
            'use strict';

            // Not document.querySelector('[data-taw-form="..."]') — that only
            // ever matches the FIRST element with this id, and the same form
            // can legitimately appear more than once on one page (e.g. the
            // same block used in a header and a footer). Every extra instance
            // would render its own <script>, but all of them would find and
            // bind to that same first form — leaving every instance after
            // the first with no submit handler at all, so submitting one
            // falls through to the browser's native POST straight to
            // admin-ajax.php, landing on the raw JSON response instead of
            // showing the inline success message. This script always
            // immediately follows its own <form>, so referencing that
            // sibling directly is unambiguous regardless of how many
            // instances of this form exist on the page.
            var form = document.currentScript.previousElementSibling;
            if (!form || !form.matches('[data-taw-form="<?php echo $formId; ?>"]')) return;

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
            var stepTitles        = <?php echo $stepTitles; ?>;

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
                var prevBtn       = form.querySelector('[data-taw-prev]');
                var nextBtn       = form.querySelector('[data-taw-next]');
                var submitWrap    = form.querySelector('[data-taw-submit-wrap]');
                var panels        = form.querySelectorAll('[data-taw-step-panel]');
                var dots          = form.querySelectorAll('[data-taw-step-dot]');
                var connectors    = form.querySelectorAll('[data-taw-step-connector]');
                var progressCount = form.querySelector('[data-taw-step-progress-count]');
                var progressTitle = form.querySelector('[data-taw-step-progress-title]');
                var progressFill  = form.querySelector('[data-taw-step-progress-fill]');

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

                    // Compact mobile progress
                    if (progressCount) progressCount.textContent = (currentStep + 1) + ' / ' + stepCount;
                    if (progressTitle) progressTitle.textContent = stepTitles[currentStep] || '';
                    if (progressFill)  progressFill.style.width  = ((currentStep + 1) / stepCount * 100) + '%';
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
                if (spinner)   spinner.classList.toggle('taw-hidden', !on);
                if (submitLbl) submitLbl.textContent = on ? '<?php echo $loadingLabel; ?>' : '<?php echo $submitLabel; ?>';
            }

            // ── Error helpers ───────────────────────────────────────

            function clearErrors() {
                form.querySelectorAll('[data-taw-field-error]').forEach(function (el) {
                    el.textContent = '';
                    el.classList.add('taw-hidden');
                });
                if (generalEl) { generalEl.textContent = ''; generalEl.classList.add('taw-hidden'); }
                if (successEl) { successEl.textContent = ''; successEl.classList.add('taw-hidden'); }
            }

            function showFieldError(fieldId, message) {
                var el = form.querySelector('[data-taw-field-error="' + fieldId + '"]');
                if (!el) return;
                el.textContent = message;
                el.classList.remove('taw-hidden');
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
                            form.querySelectorAll('[data-taw-step-panel], .taw-step-indicator, .taw-step-progress, [data-taw-form-actions]')
                                .forEach(function (el) { el.hidden = true; });
                        }

                        if (successEl) {
                            successEl.textContent = json.data && json.data.message ? json.data.message : '';
                            successEl.classList.remove('taw-hidden');
                        }
                        return;
                    }

                    var payload = json.data || {};

                    if (payload.general && generalEl) {
                        generalEl.textContent = payload.general;
                        generalEl.classList.remove('taw-hidden');
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
                        generalEl.classList.remove('taw-hidden');
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
