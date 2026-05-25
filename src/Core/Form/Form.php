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
 *  - Webhook delivery (configured in Settings → Form Webhook)
 *
 * ⚠️  REGISTRATION MUST HAPPEN BEFORE TEMPLATES LOAD
 *
 * admin-ajax.php never loads theme templates, so AJAX hooks must be registered
 * on every request. Register forms in functions.php or Theme::boot(), then call
 * render() anywhere in a template:
 *
 *   // functions.php (or Theme::boot())
 *   Form::register([
 *       'id'           => 'contact',
 *       'submit_label' => 'Send Message',
 *       'email'        => ['subject' => 'New contact', 'to' => get_option('admin_email')],
 *       'messages'     => ['success' => 'Thanks! We\'ll be in touch.'],
 *       'fields'       => [
 *           ['id' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true],
 *           ['id' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true],
 *           ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
 *       ],
 *   ]);
 *
 *   // Any template / block
 *   Form::render('contact');
 */
class Form
{
    /** @var self[] All registered forms, keyed by form ID. */
    private static array $registry = [];

    private string $id;
    private array $config;

    /**
     * Register a form globally so its AJAX handlers are always available.
     *
     * Must be called before templates load (functions.php or theme boot).
     * Returns the Form instance so you can store a reference if needed.
     */
    public static function register(array $config): self
    {
        $instance = new self($config);
        return $instance;
    }

    /**
     * Render a previously registered form by ID.
     * Call this from any template or block after Form::register() has run.
     */
    public static function display(string $id): void
    {
        $form = self::$registry[$id] ?? null;

        if (!$form) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    '[TAW Form] Form "' . esc_html($id) . '" was not registered. '
                    . 'Call Form::register() in the block boot() method before using Form::display().',
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

        // Route through admin-ajax.php — works for both logged-in and logged-out users,
        // and is completely independent of WP's page/rewrite routing.
        add_action('wp_ajax_nopriv_taw_form_' . $this->id, [$this, 'process']);
        add_action('wp_ajax_taw_form_' . $this->id, [$this, 'process']);
    }

    /* -------------------------------------------------------------------------
     * Processing (AJAX handler)
     * ---------------------------------------------------------------------- */

    public function process(): void
    {
        // Nonce verification via check_ajax_referer exits automatically on failure.
        check_ajax_referer($this->id . '_action', $this->id . '_nonce');

        // Honeypot — silently succeed so bots think they submitted correctly.
        if (!empty($_POST['taw_hp_check'])) {
            wp_send_json_success();
        }

        // First pass: collect all raw values so conditions can be evaluated server-side.
        $raw = [];
        foreach ($this->config['fields'] as $field) {
            $raw[$field['id']] = isset($_POST[$field['id']]) ? wp_unslash($_POST[$field['id']]) : '';
        }

        $data   = [];
        $errors = [];

        foreach ($this->config['fields'] as $field) {
            $fieldId = $field['id'];

            // Skip fields whose conditions aren't met — they were hidden (and disabled) in the UI.
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

        SubmissionsHandler::saveSubmission($this->id, $this->config['fields'], $data);

        wp_send_json_success([
            'message' => $this->config['messages']['success'] ?? __('Thank you! Your message has been sent.', 'taw'),
        ]);
    }

    /* -------------------------------------------------------------------------
     * Email
     * ---------------------------------------------------------------------- */

    private function sanitize(mixed $value, string $type): string
    {
        return match ($type) {
            'email'    => sanitize_email($value),
            'textarea' => sanitize_textarea_field($value),
            'url'      => esc_url_raw($value),
            'date'     => sanitize_text_field($value),
            'checkbox' => ($value === '1') ? '1' : '0',
            default    => sanitize_text_field($value),
        };
    }

    /**
     * Returns true when every condition in the array is satisfied by $values.
     * An empty conditions array is always satisfied.
     *
     * @param array<int, array{field: string, operator: string, value: scalar}> $conditions
     * @param array<string, mixed> $values  Raw field_id → value map for the current submission.
     */
    private function conditionsMet(array $conditions, array $values): bool
    {
        foreach ($conditions as $condition) {
            $field    = $condition['field']    ?? '';
            $operator = $condition['operator'] ?? '==';
            $expected = (string) ($condition['value'] ?? '');
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

            if (!$passes) {
                return false;
            }
        }

        return true;
    }

    private function sendEmail(array $data): bool
    {
        $emailConfig  = $this->config['email'] ?? [];
        $templateIn   = $emailConfig['to_self']['template']   ?? null;
        $templateOut  = $emailConfig['to_client']['template'] ?? null;

        if ($templateIn && $templateOut) {
            return $this->sendWithTemplate($data);
        }

        // Fallback: plain-text wp_mail
        $to      = $emailConfig['to'] ?? get_option('admin_email');
        $subject = $emailConfig['subject'] ?? 'New Form Submission';
        $body    = 'New submission from ' . get_bloginfo('name') . ":\n\n";
        $headers = [];

        foreach ($this->config['fields'] as $field) {
            $body .= ($field['label'] ?? $field['id']) . ': ' . ($data[$field['id']] ?? '-') . "\n";
        }

        foreach ($this->config['fields'] as $field) {
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
        foreach ($this->config['fields'] as $field) {
            $label        = $field['label'] ?? $field['id'];
            $value        = $formData[$field['id']] ?? '-';
            $replacements[$field['id']] = $value;
            $allFields   .= "<p><strong>{$label}:</strong> {$value}</p>";
        }
        $replacements['all_fields'] = $allFields;

        $shared = array_merge($replacements, [
            'site_url' => get_site_url(),
        ]);

        try {
            // Email to site admin
            (new Mailer())
                ->to(get_option('admin_email'))
                ->subject($emailConfig['to_self']['subject'] ?? 'New Form Submission')
                ->template($emailConfig['to_self']['template'])
                ->setVariables($shared)
                ->send();

            // Confirmation email to the submitter (if an email field was filled)
            $clientEmail = null;
            foreach ($this->config['fields'] as $field) {
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
        $formId       = esc_attr($this->id);
        $btnLabel     = $this->config['submit_label'] ?? __('Send Message', 'taw');
        $loadingLabel = $this->config['submit_loading_label'] ?? __('Sending...', 'taw');
        $ajaxUrl      = esc_url(admin_url('admin-ajax.php'));

        echo '<form method="post"'
            . ' action="' . $ajaxUrl . '"'
            . ' class="flex flex-col gap-4 items-end"'
            . ' data-taw-form="' . $formId . '"'
            . ' novalidate>';

        wp_nonce_field($this->id . '_action', $this->id . '_nonce');
        echo '<input type="hidden" name="action" value="taw_form_' . $formId . '">';
        echo '<input type="hidden" name="taw_form_id" value="' . $formId . '">';

        // Honeypot field — hidden from real users, filled by bots
        echo '<div style="display:none;" aria-hidden="true">';
        echo '<label>Leave this empty <input type="text" name="taw_hp_check" value="" tabindex="-1" autocomplete="off"></label>';
        echo '</div>';

        // General error banner (shown by JS on non-field errors)
        echo '<div class="hidden p-4 w-full text-red-800 bg-red-100 border border-red-200 rounded-lg"'
            . ' data-taw-general-error role="alert"></div>';

        echo '<div class="grid grid-cols-12 gap-4">';
        foreach ($this->config['fields'] as $field) {
            $this->renderField($field);
        }
        echo '</div>';

        // Success message (shown by JS after successful submission)
        echo '<div class="hidden p-4 w-full text-green-800 bg-green-100 border border-green-200 rounded-lg"'
            . ' data-taw-success role="status"></div>';

        echo '<div class="mt-2 w-full sm:w-fit self-end">';
        echo '<button type="submit" data-taw-submit'
            . ' data-loading-label="' . esc_attr($loadingLabel) . '"'
            . ' class="p-3 w-full bg-stone-800 text-stone-300 rounded-md hover:bg-stone-700 transition-colors border border-stone-800 cursor-pointer ml-auto inline-flex items-center justify-center gap-2">';
        echo '<svg class="hidden animate-spin size-4" data-taw-spinner xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">'
            . '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>'
            . '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>'
            . '</svg>';
        echo '<span data-taw-submit-label>' . esc_html($btnLabel) . '</span>';
        echo '</button>';
        echo '</div>';

        echo '</form>';

        $this->renderScript($btnLabel, $loadingLabel);
    }

    private function renderField(array $field): void
    {
        $id          = $field['id'];
        $type        = $field['type'] ?? 'text';
        $label       = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required    = !empty($field['required']);
        $conditions  = $field['conditions'] ?? [];
        $baseClasses = 'w-full p-2 border border-stone-400 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-stone-500 focus:border-transparent transition-all';

        // Width → 12-column grid span (mobile always full-width)
        $width   = (int) ($field['width'] ?? 100);
        $colSpan = match (true) {
            $width <= 25 => 'col-span-12 sm:col-span-3',
            $width <= 33 => 'col-span-12 sm:col-span-4',
            $width <= 50 => 'col-span-12 sm:col-span-6',
            $width <= 67 => 'col-span-12 sm:col-span-8',
            $width <= 75 => 'col-span-12 sm:col-span-9',
            default      => 'col-span-12',
        };

        $wrapAttrs = 'class="' . $colSpan . ' flex flex-col gap-1"'
            . ' data-taw-field-wrap="' . esc_attr($id) . '"';

        if (!empty($conditions)) {
            // Hidden by default; JS evaluates and reveals when conditions are met.
            $wrapAttrs .= ' data-taw-conditions="' . esc_attr(wp_json_encode($conditions)) . '" hidden';
        }

        echo '<div ' . $wrapAttrs . '>';

        // Label — rendered inline for checkboxes, above for everything else.
        if ($label && $type !== 'checkbox') {
            echo '<label for="' . esc_attr($id) . '" class="font-semibold text-stone-700">';
            echo esc_html($label) . ($required ? ' <span class="text-red-500" aria-hidden="true">*</span>' : '');
            echo '</label>';
        }

        switch ($type) {
            case 'textarea':
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="%2$s" class="%3$s" placeholder="%4$s"></textarea>',
                    esc_attr($id),
                    intval($field['rows'] ?? 4),
                    $baseClasses,
                    esc_attr($placeholder)
                );
                break;

            case 'select':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" class="' . $baseClasses . '">';
                echo '<option value="" disabled selected>' . esc_html__('Select an option...', 'taw') . '</option>';
                foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                    echo '<option value="' . esc_attr($optVal) . '">' . esc_html($optLabel) . '</option>';
                }
                echo '</select>';
                break;

            case 'date':
                $min = !empty($field['min_date']) ? ' min="' . esc_attr($field['min_date']) . '"' : '';
                $max = !empty($field['max_date']) ? ' max="' . esc_attr($field['max_date']) . '"' : '';
                printf(
                    '<input type="date" id="%1$s" name="%1$s" class="%2$s"%3$s%4$s>',
                    esc_attr($id),
                    $baseClasses,
                    $min,
                    $max
                );
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

            default:
                printf(
                    '<input type="%1$s" id="%2$s" name="%2$s" class="%3$s" placeholder="%4$s">',
                    esc_attr($type),
                    esc_attr($id),
                    $baseClasses,
                    esc_attr($placeholder)
                );
                break;
        }

        echo '<span class="hidden text-sm text-red-600" data-taw-field-error="' . esc_attr($id) . '" role="alert"></span>';
        echo '</div>';
    }

    private function renderScript(string $btnLabel, string $loadingLabel): void
    {
        $formId         = esc_js($this->id);
        $btnLabelJs     = esc_js($btnLabel);
        $loadingLabelJs = esc_js($loadingLabel);
        $networkErrorJs = esc_js(__('A network error occurred. Please try again.', 'taw'));
        ?>
        <script>
        (function () {
            'use strict';

            var form = document.querySelector('[data-taw-form="<?php echo $formId; ?>"]');
            if (!form) return;

            var submitBtn = form.querySelector('[data-taw-submit]');
            var spinner   = form.querySelector('[data-taw-spinner]');
            var btnLabel  = form.querySelector('[data-taw-submit-label]');
            var successEl = form.querySelector('[data-taw-success]');
            var generalEl = form.querySelector('[data-taw-general-error]');

            function getFieldValue(name) {
                var el = form.querySelector('[name="' + name + '"]');
                if (!el) return '';
                if (el.type === 'checkbox') return el.checked ? '1' : '0';
                return el.value;
            }

            function evaluateConditions() {
                form.querySelectorAll('[data-taw-conditions]').forEach(function (wrap) {
                    var conditions;
                    try { conditions = JSON.parse(wrap.getAttribute('data-taw-conditions')); } catch (e) { return; }

                    var met = conditions.every(function (cond) {
                        var actual   = getFieldValue(cond.field);
                        var expected = String(cond.value);
                        switch (cond.operator) {
                            case '!=':       return actual !== expected;
                            case '>':        return parseFloat(actual) >  parseFloat(expected);
                            case '<':        return parseFloat(actual) <  parseFloat(expected);
                            case '>=':       return parseFloat(actual) >= parseFloat(expected);
                            case '<=':       return parseFloat(actual) <= parseFloat(expected);
                            case 'contains': return actual.indexOf(expected) !== -1;
                            default:         return actual === expected;
                        }
                    });

                    wrap.hidden = !met;
                    // Disable inputs inside hidden wrappers so they are excluded from FormData.
                    wrap.querySelectorAll('input, select, textarea').forEach(function (el) {
                        el.disabled = !met;
                    });
                });
            }

            // Evaluate once on load, then on every change/input event.
            evaluateConditions();
            form.addEventListener('change', evaluateConditions);
            form.addEventListener('input',  evaluateConditions);

            function setLoading(on) {
                submitBtn.disabled = on;
                if (spinner)  spinner.classList.toggle('hidden', !on);
                if (btnLabel) btnLabel.textContent = on ? '<?php echo $loadingLabelJs; ?>' : '<?php echo $btnLabelJs; ?>';
            }

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

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                clearErrors();
                setLoading(true);

                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new FormData(form),
                })
                .then(function (res) {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(function (json) {
                    setLoading(false);

                    if (json.success) {
                        form.reset();
                        if (successEl) {
                            successEl.textContent = (json.data && json.data.message) ? json.data.message : '';
                            successEl.classList.remove('hidden');
                        }
                        return;
                    }

                    var data = json.data || {};

                    if (data.general && generalEl) {
                        generalEl.textContent = data.general;
                        generalEl.classList.remove('hidden');
                    }

                    if (data.errors) {
                        Object.keys(data.errors).forEach(function (fieldId) {
                            showFieldError(fieldId, data.errors[fieldId]);
                        });
                    }
                })
                .catch(function () {
                    setLoading(false);
                    if (generalEl) {
                        generalEl.textContent = '<?php echo $networkErrorJs; ?>';
                        generalEl.classList.remove('hidden');
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
