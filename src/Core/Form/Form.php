<?php

declare(strict_types=1);

namespace TAW\Core\Form;

use TAW\Core\Mail\Mailer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Form — configuration-driven frontend form with processing, validation, and email delivery.
 *
 * Features:
 *  - CSRF protection (WordPress nonces)
 *  - Honeypot spam protection
 *  - Field sanitization & validation
 *  - Transient-based success flash message
 *  - PRG pattern (redirect after POST) to prevent double-submission
 *  - Optional email delivery via Mailer + MJML templates
 *
 * Usage:
 *   $form = new Form([
 *       'id'           => 'contact',
 *       'submit_label' => 'Send Message',
 *       'email' => [
 *           'to_self'   => ['subject' => 'New contact', 'template' => 'contact-self'],
 *           'to_client' => ['subject' => 'Got your message', 'template' => 'contact-client'],
 *       ],
 *       'messages' => ['success' => 'Thanks! We'll be in touch.'],
 *       'fields' => [
 *           ['id' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true],
 *           ['id' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true],
 *           ['id' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
 *       ],
 *   ]);
 *   $form->render();
 */
class Form
{
    private string $id;
    private array $config;
    private array $errors = [];

    public function __construct(array $config)
    {
        $this->id     = $config['id'];
        $this->config = $config;

        // Process immediately if init already fired (e.g. form instantiated inside a template).
        // Otherwise schedule for init — process() is a no-op on GET requests.
        if (did_action('init')) {
            $this->process();
        } else {
            add_action('init', [$this, 'process']);
        }
    }

    /* -------------------------------------------------------------------------
     * Processing
     * ---------------------------------------------------------------------- */

    public function process(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['taw_form_id']) || $_POST['taw_form_id'] !== $this->id) {
            return;
        }

        // Honeypot
        if (!empty($_POST['taw_hp_check'])) {
            return;
        }

        // Nonce
        if (
            !isset($_POST[$this->id . '_nonce']) ||
            !wp_verify_nonce($_POST[$this->id . '_nonce'], $this->id . '_action')
        ) {
            $this->errors['general'] = 'Security check failed. Please refresh and try again.';
            return;
        }

        // Validate & sanitize
        $data     = [];
        $hasError = false;

        foreach ($this->config['fields'] as $field) {
            $fieldId = $field['id'];
            $value   = isset($_POST[$fieldId]) ? wp_unslash($_POST[$fieldId]) : '';
            $label   = $field['label'] ?? $fieldId;

            if (!empty($field['required']) && empty($value)) {
                $this->errors[$fieldId] = sprintf(__('%s is required.', 'taw'), $label);
                $hasError = true;
                continue;
            }

            $sanitized = $this->sanitize($value, $field['type'] ?? 'text');

            if (($field['type'] ?? '') === 'email' && !empty($sanitized) && !is_email($sanitized)) {
                $this->errors[$fieldId] = __('Invalid email address.', 'taw');
                $hasError = true;
            }

            $data[$fieldId] = $sanitized;
        }

        if ($hasError) {
            return;
        }

        $sent = $this->sendEmail($data);

        if ($sent) {
            SubmissionsHandler::saveSubmission($this->id, $this->config['fields'], $data);

            set_transient('taw_form_success_' . $this->id, true, 60);
            // PRG: redirect to the same page without POST data.
            // We do NOT append a query var — unregistered vars cause WP to 404.
            // The success state is communicated entirely via the transient.
            $redirect = esc_url_raw(home_url(wp_unslash($_SERVER['REQUEST_URI'])));
            wp_safe_redirect($redirect);
            exit;
        }

        $this->errors['general'] = __('There was a problem sending your message. Please try again later.', 'taw');
    }

    private function sanitize(mixed $value, string $type): string
    {
        return match ($type) {
            'email'    => sanitize_email($value),
            'textarea' => sanitize_textarea_field($value),
            'url'      => esc_url_raw($value),
            default    => sanitize_text_field($value),
        };
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
        $successMsg = null;
        if (get_transient('taw_form_success_' . $this->id)) {
            delete_transient('taw_form_success_' . $this->id);
            $successMsg = $this->config['messages']['success'] ?? 'Thank you! Your message has been sent.';
        }

        if (isset($this->errors['general'])) {
            echo '<div class="p-4 mb-6 text-red-800 bg-red-100 border border-red-200 rounded-lg">'
                . esc_html($this->errors['general']) . '</div>';
        }

        echo '<form method="post" action="" class="flex flex-col gap-4 items-end" data-taw-form novalidate>';

        wp_nonce_field($this->id . '_action', $this->id . '_nonce');
        echo '<input type="hidden" name="taw_form_id" value="' . esc_attr($this->id) . '">';
        echo '<div style="display:none;"><label>Leave this empty <input type="text" name="taw_hp_check" value=""></label></div>';

        foreach ($this->config['fields'] as $field) {
            $this->renderField($field);
        }

        if ($successMsg) {
            echo '<div class="p-4 w-full text-green-800 bg-green-100 border border-green-200 rounded-lg">'
                . esc_html($successMsg) . '</div>';
        }

        $btnLabel     = $this->config['submit_label'] ?? 'Send Message';
        $loadingLabel = $this->config['submit_loading_label'] ?? 'Sending...';

        echo '<div class="mt-2 w-full sm:w-fit">';
        echo '<button type="submit" data-taw-submit data-loading-label="' . esc_attr($loadingLabel)
            . '" class="p-3 w-full bg-stone-800 text-stone-300 rounded-md hover:bg-stone-700 transition-colors border border-stone-800 cursor-pointer ml-auto inline-flex items-center justify-center gap-2">';
        echo '<svg class="hidden animate-spin size-4" data-taw-spinner xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">'
            . '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>'
            . '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>'
            . '</svg>';
        echo '<span data-taw-submit-label>' . esc_html($btnLabel) . '</span>';
        echo '</button>';
        echo '</div>';

        echo '</form>';
    }

    private function renderField(array $field): void
    {
        $id          = $field['id'];
        $type        = $field['type'] ?? 'text';
        $label       = $field['label'] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $required    = !empty($field['required']);
        $value       = isset($_POST[$id]) ? wp_unslash($_POST[$id]) : '';
        $hasError    = isset($this->errors[$id]);
        $borderClass = $hasError ? 'border-red-500 ' : 'border-stone-400 ';
        $baseClasses = 'w-full p-2 border rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-stone-500 focus:border-transparent transition-all flex-1 ' . $borderClass;

        echo '<div class="flex flex-col gap-1 w-full">';

        if ($label) {
            echo '<label for="' . esc_attr($id) . '" class="font-semibold text-stone-700">';
            echo esc_html($label) . ($required ? ' <span class="text-red-500">*</span>' : '');
            echo '</label>';
        }

        switch ($type) {
            case 'textarea':
                printf(
                    '<textarea id="%1$s" name="%1$s" rows="%2$s" class="%3$s" placeholder="%4$s">%5$s</textarea>',
                    esc_attr($id),
                    intval($field['rows'] ?? 4),
                    $baseClasses,
                    esc_attr($placeholder),
                    esc_textarea($value)
                );
                break;

            case 'select':
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '" class="' . $baseClasses . '">';
                echo '<option value="" disabled ' . selected($value, '', false) . '>Select an option...</option>';
                foreach (($field['options'] ?? []) as $optVal => $optLabel) {
                    echo '<option value="' . esc_attr($optVal) . '" ' . selected($value, $optVal, false) . '>'
                        . esc_html($optLabel) . '</option>';
                }
                echo '</select>';
                break;

            default:
                printf(
                    '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="%4$s" placeholder="%5$s">',
                    esc_attr($type),
                    esc_attr($id),
                    esc_attr($value),
                    $baseClasses,
                    esc_attr($placeholder)
                );
                break;
        }

        if ($hasError) {
            echo '<span class="text-sm text-red-600">' . esc_html($this->errors[$id]) . '</span>';
        }

        echo '</div>';
    }
}
