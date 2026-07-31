<?php

declare(strict_types=1);

namespace TAW\Core\Form;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SubmissionsHandler — stores form submissions as a CPT and fires a webhook.
 *
 * Registers the `taw_submission` CPT for viewing submissions in WP Admin.
 * Also provides a Settings → Form Webhook page for configuring a webhook URL
 * and HMAC signing secret (compatible with n8n, Zapier, Make, etc.).
 *
 * Usage:
 *   new SubmissionsHandler();
 *
 * Submissions are saved automatically by Form::process() after a successful send.
 */
class SubmissionsHandler
{
    const POST_TYPE      = 'taw_submission';
    const WEBHOOK_URL    = 'taw_form_webhook_url';
    const WEBHOOK_SECRET = 'taw_form_webhook_secret';
    const NONCE_ACTION   = 'taw_webhook_settings_save';
    const PAGE_SLUG      = 'taw-form-webhook';

    public function __construct()
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('add_meta_boxes', [$this, 'addDetailsMetabox']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'setColumns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'renderColumns'], 10, 2);

        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'handleSettingsSave']);
    }

    /* =====================================================================
     * CPT
     * =================================================================== */

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'          => 'Form Submissions',
                'singular_name' => 'Submission',
                'menu_name'     => 'Submissions',
                'not_found'     => 'No submissions found.',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'capability_type' => 'post',
            'capabilities'    => ['create_posts' => 'do_not_allow'],
            'map_meta_cap'    => true,
            'hierarchical'    => false,
            'supports'        => ['title'],
        ]);
    }

    /* =====================================================================
     * Admin Columns
     * =================================================================== */

    public function setColumns(array $columns): array
    {
        return [
            'cb'             => $columns['cb'],
            'title'          => 'Submission',
            'form_source'    => 'Source Form',
            'webhook_status' => 'Webhook',
            'submitted_on'   => 'Date',
        ];
    }

    public function renderColumns(string $column, int $postId): void
    {
        match ($column) {
            'form_source' => print(esc_html(get_post_meta($postId, '_taw_form_id', true))),
            'webhook_status' => (function () use ($postId) {
                $status = get_post_meta($postId, '_taw_webhook_status', true);
                echo match ($status) {
                    'sent'   => '<span style="color:#46b450;">&#10003; Sent</span>',
                    'failed' => '<span style="color:#dc3232;">&#10007; Failed</span>',
                    default  => '<span style="color:#999;">—</span>',
                };
            })(),
            'submitted_on' => print(get_the_date('Y-m-d H:i:s', $postId)),
            default => null,
        };
    }

    /* =====================================================================
     * Submission Detail Metabox
     * =================================================================== */

    public function addDetailsMetabox(): void
    {
        add_meta_box(
            'taw_submission_data',
            'Submission Data',
            [$this, 'renderMetabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function renderMetabox(\WP_Post $post): void
    {
        $data = get_post_meta($post->ID, '_taw_submission_data', true);

        if (empty($data) || !is_array($data)) {
            echo '<p>No data found for this submission.</p>';
            return;
        }

        echo '<table class="widefat striped" style="border:0;"><tbody>';
        foreach ($data as $key => $row) {
            printf(
                '<tr><th style="width:200px;text-align:left;font-weight:600;color:#444;">%s</th><td>%s</td></tr>',
                esc_html($row['label'] ?? $key),
                nl2br(esc_html($row['value'] ?? ''))
            );
        }
        echo '</tbody></table>';

        $webhookStatus = get_post_meta($post->ID, '_taw_webhook_status', true) ?: 'Not configured';
        $webhookError  = get_post_meta($post->ID, '_taw_webhook_error', true);
        $userIp        = get_post_meta($post->ID, '_taw_user_ip', true);
        $pageUrl       = get_post_meta($post->ID, '_taw_page_url', true);

        echo '<div style="margin-top:15px;border-top:1px solid #ddd;padding:10px;color:#666;font-size:11px;">';
        echo '<strong>Webhook Status:</strong> ' . esc_html($webhookStatus);
        if ($webhookError) {
            echo ' <span style="color:#dc3232;">(' . esc_html($webhookError) . ')</span>';
        }
        echo '<br><strong>User IP:</strong> ' . esc_html($userIp);
        if ($pageUrl) {
            echo '<br><strong>Page URL:</strong> ' . esc_html($pageUrl);
        }
        echo '</div>';
    }

    /* =====================================================================
     * Save Submission (called by Form::process)
     * =================================================================== */

    /**
     * @param string $formId        Form identifier.
     * @param array  $fields        Field config array from the form config.
     * @param array  $data          Sanitized field_id → value map.
     * @param array  $webhookConfig Optional per-form code-level default, e.g.
     *                              ['url' => '...', 'secret' => '...'] from
     *                              the form's own 'webhook' config key.
     *                              Overridden by an admin-configured per-form
     *                              webhook if one is set — see fireWebhook().
     * @param string $pageUrl       The full URL of the page the form was
     *                              submitted from (captured server-side at
     *                              render time — see Form::currentPageUrl()).
     * @return int|false            Created post ID or false on failure.
     */
    public static function saveSubmission(string $formId, array $fields, array $data, array $webhookConfig = [], string $pageUrl = ''): int|false
    {
        $name    = $data['name'] ?? $data['email'] ?? 'Anonymous';
        $subject = $data['subject'] ?? $formId;
        $title   = "{$name} — {$subject}";

        $metaData = [];
        foreach ($fields as $field) {
            $fid           = $field['id'];
            $metaData[$fid] = ['label' => $field['label'] ?? $fid, 'value' => $data[$fid] ?? ''];
        }

        $postId = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_title'  => sanitize_text_field($title),
            'post_status' => 'publish',
        ]);

        if (!$postId || is_wp_error($postId)) {
            return false;
        }

        update_post_meta($postId, '_taw_form_id', $formId);
        update_post_meta($postId, '_taw_submission_data', $metaData);
        update_post_meta($postId, '_taw_user_ip', self::getUserIp());
        update_post_meta($postId, '_taw_page_url', $pageUrl);

        self::fireWebhook($postId, $formId, $data, $webhookConfig, $pageUrl);

        return $postId;
    }

    /* =====================================================================
     * Webhook
     * =================================================================== */

    /**
     * Resolves the webhook URL to fire for a given form, in precedence
     * order: an admin-configured per-form override (Settings → Form
     * Webhook) > the form's own code-level 'webhook' config default >
     * the site-wide default webhook (fallback for any form with neither).
     */
    private static function resolveWebhookUrl(string $formId, array $webhookConfig): string
    {
        return get_option(self::urlOptionKey($formId), '')
            ?: ($webhookConfig['url'] ?? '')
            ?: get_option(self::WEBHOOK_URL, '');
    }

    /**
     * Same precedence as resolveWebhookUrl(), for the HMAC signing secret.
     */
    private static function resolveWebhookSecret(string $formId, array $webhookConfig): string
    {
        return get_option(self::secretOptionKey($formId), '')
            ?: ($webhookConfig['secret'] ?? '')
            ?: get_option(self::WEBHOOK_SECRET, '');
    }

    private static function urlOptionKey(string $formId): string
    {
        return self::WEBHOOK_URL . '_' . sanitize_key($formId);
    }

    private static function secretOptionKey(string $formId): string
    {
        return self::WEBHOOK_SECRET . '_' . sanitize_key($formId);
    }

    private static function fireWebhook(int $postId, string $formId, array $data, array $webhookConfig = [], string $pageUrl = ''): void
    {
        $url = self::resolveWebhookUrl($formId, $webhookConfig);

        if (empty($url)) {
            update_post_meta($postId, '_taw_webhook_status', 'skipped');
            return;
        }

        $payload = [
            'event'        => 'new_submission',
            'form_id'      => $formId,
            'post_id'      => $postId,
            'submitted_at' => current_time('c'),
            'site_url'     => home_url(),
            'page_url'     => $pageUrl,
            'ip'           => self::getUserIp(),
            'data'         => $data,
        ];

        /**
         * Filters the webhook payload for a form submission before it's sent.
         *
         * Lets a specific taw-theme site tailor its own webhook payload —
         * adding a computed routing key, UTM params, anything the default
         * shape doesn't cover — without touching taw-core itself. Runs
         * before the HMAC signature is computed, so the signature always
         * covers exactly what's actually sent.
         *
         * @param array<string, mixed> $payload The default payload (event,
         *                                       form_id, post_id,
         *                                       submitted_at, site_url,
         *                                       page_url, ip, data).
         * @param string               $formId  The submitting form's id.
         * @param int                  $postId  The taw_submission CPT post ID.
         * @param array<string, mixed> $data    Sanitized field_id => value map.
         */
        $payload = apply_filters('taw_form_webhook_payload', $payload, $formId, $postId, $data);

        $headers = ['Content-Type' => 'application/json'];

        $secret = self::resolveWebhookSecret($formId, $webhookConfig);
        if (!empty($secret)) {
            $headers['X-TAW-Signature'] = hash_hmac('sha256', wp_json_encode($payload), $secret);
        }

        $response = wp_remote_post($url, [
            'body'      => wp_json_encode($payload),
            'headers'   => $headers,
            'timeout'   => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            update_post_meta($postId, '_taw_webhook_status', 'failed');
            update_post_meta($postId, '_taw_webhook_error', $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            update_post_meta($postId, '_taw_webhook_status', 'sent');
        } else {
            update_post_meta($postId, '_taw_webhook_status', 'failed');
            update_post_meta($postId, '_taw_webhook_error', 'HTTP ' . $code);
        }
    }

    /* =====================================================================
     * Settings Page (Settings → Form Webhook)
     * =================================================================== */

    public function addSettingsPage(): void
    {
        add_options_page(
            'Form Webhook Settings',
            'Form Webhook',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderSettingsPage']
        );
    }

    public function handleSettingsSave(): void
    {
        if (
            !isset($_POST['taw_webhook_settings_nonce']) ||
            !wp_verify_nonce($_POST['taw_webhook_settings_nonce'], self::NONCE_ACTION)
        ) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        update_option(self::WEBHOOK_URL, esc_url_raw(trim($_POST[self::WEBHOOK_URL] ?? '')), false);

        $secret = sanitize_text_field(trim($_POST[self::WEBHOOK_SECRET] ?? ''));
        if (!empty($secret)) {
            update_option(self::WEBHOOK_SECRET, $secret, false);
        }

        if (!empty($_POST['taw_clear_webhook_secret'])) {
            delete_option(self::WEBHOOK_SECRET);
        }

        // Per-form overrides — one row per currently-registered form. These
        // use their own field-name prefix (distinct from self::WEBHOOK_URL /
        // self::WEBHOOK_SECRET above) precisely to avoid colliding with the
        // sitewide default field: two <input> elements sharing the same base
        // name — one scalar, one array-notation — make PHP's request-body
        // parser coerce the whole $_POST entry into an array, clobbering the
        // scalar value and crashing the trim() call above with a TypeError.
        $formUrls      = (array) ($_POST['taw_per_form_webhook_url'] ?? []);
        $formSecrets   = (array) ($_POST['taw_per_form_webhook_secret'] ?? []);
        $clearSecretOf = (array) ($_POST['taw_clear_per_form_webhook_secret'] ?? []);

        foreach (array_keys(Form::getAll()) as $formId) {
            update_option(
                self::urlOptionKey($formId),
                esc_url_raw(trim($formUrls[$formId] ?? '')),
                false
            );

            $formSecret = sanitize_text_field(trim($formSecrets[$formId] ?? ''));
            if (!empty($formSecret)) {
                update_option(self::secretOptionKey($formId), $formSecret, false);
            }

            if (!empty($clearSecretOf[$formId])) {
                delete_option(self::secretOptionKey($formId));
            }
        }

        add_settings_error(self::PAGE_SLUG, 'settings_updated', 'Webhook settings saved.', 'updated');
    }

    public function renderSettingsPage(): void
    {
        $url       = get_option(self::WEBHOOK_URL, '');
        $hasSecret = !empty(get_option(self::WEBHOOK_SECRET, ''));
        $forms     = Form::getAll();
        ?>
        <div class="wrap">
            <h1>Form Webhook Settings</h1>
            <p class="description" style="max-width:640px;">
                Every form submission fires a <strong>JSON POST</strong>. Configure a webhook
                for a specific form below, or set a default that any form without its own
                webhook falls back to. Connect to <strong>n8n</strong>, Zapier, Make, or any
                automation platform.
            </p>

            <form method="post" action="" style="max-width:760px;">
                <?php wp_nonce_field(self::NONCE_ACTION, 'taw_webhook_settings_nonce'); ?>

                <h2>Default Webhook <span class="description" style="font-weight:normal;">(fallback for any form below with no override)</span></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="<?php echo self::WEBHOOK_URL; ?>">Webhook URL</label></th>
                        <td>
                            <input type="url" id="<?php echo self::WEBHOOK_URL; ?>"
                                name="<?php echo self::WEBHOOK_URL; ?>"
                                value="<?php echo esc_attr($url); ?>"
                                class="regular-text"
                                placeholder="https://n8n.example.com/webhook/abc123">
                            <p class="description">Leave empty to disable the default.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="<?php echo self::WEBHOOK_SECRET; ?>">Signing Secret</label></th>
                        <td>
                            <?php if ($hasSecret): ?>
                                <p class="description" style="margin-bottom:8px;">
                                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                                    A secret is stored. Enter a new value to replace it.
                                </p>
                            <?php endif; ?>
                            <input type="password" id="<?php echo self::WEBHOOK_SECRET; ?>"
                                name="<?php echo self::WEBHOOK_SECRET; ?>"
                                value="" class="regular-text"
                                placeholder="<?php echo $hasSecret ? 'Leave blank to keep current' : 'Optional — for HMAC-SHA256 signing'; ?>"
                                autocomplete="off">
                            <?php if ($hasSecret): ?>
                                <p style="margin-top:8px;">
                                    <label>
                                        <input type="checkbox" name="taw_clear_webhook_secret" value="1">
                                        Remove stored secret
                                    </label>
                                </p>
                            <?php endif; ?>
                            <p class="description">
                                When set, every request includes an <code>X-TAW-Signature</code> header
                                (HMAC-SHA256) so your webhook receiver can verify the origin.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php if (!empty($forms)): ?>
                    <h2>Per-Form Webhooks</h2>
                    <p class="description" style="max-width:640px;margin-bottom:1em;">
                        Overrides the default above for that form only. A form can also set a
                        code-level default via its <code>webhook</code> config key — an override
                        entered here always takes precedence over that.
                    </p>
                    <table class="widefat striped" style="max-width:760px;">
                        <thead>
                            <tr>
                                <th style="width:140px;">Form ID</th>
                                <th>Webhook URL</th>
                                <th>Signing Secret</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $formId => $form): ?>
                                <?php
                                $formUrl       = get_option(self::urlOptionKey($formId), '');
                                $formHasSecret = !empty(get_option(self::secretOptionKey($formId), ''));
                                ?>
                                <tr>
                                    <td><code><?php echo esc_html($formId); ?></code></td>
                                    <td>
                                        <input type="url"
                                            name="taw_per_form_webhook_url[<?php echo esc_attr($formId); ?>]"
                                            value="<?php echo esc_attr($formUrl); ?>"
                                            class="regular-text"
                                            placeholder="Uses default above">
                                    </td>
                                    <td>
                                        <input type="password"
                                            name="taw_per_form_webhook_secret[<?php echo esc_attr($formId); ?>]"
                                            value="" class="regular-text"
                                            placeholder="<?php echo $formHasSecret ? 'Leave blank to keep current' : 'Optional'; ?>"
                                            autocomplete="off">
                                        <?php if ($formHasSecret): ?>
                                            <label style="display:block;margin-top:4px;font-weight:normal;">
                                                <input type="checkbox" name="taw_clear_per_form_webhook_secret[<?php echo esc_attr($formId); ?>]" value="1">
                                                Remove stored secret
                                            </label>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php submit_button('Save Webhook Settings'); ?>
            </form>

            <hr>
            <h2>Payload Example</h2>
            <pre style="background:#f6f7f7;padding:15px;border:1px solid #ddd;border-radius:4px;max-width:640px;overflow-x:auto;">{
  "event": "new_submission",
  "form_id": "contact",
  "post_id": 142,
  "submitted_at": "2026-02-07T12:30:00+00:00",
  "site_url": "https://example.com",
  "page_url": "https://example.com/contact",
  "ip": "203.0.113.42",
  "data": {
    "name": "Jane Doe",
    "email": "jane@example.com",
    "message": "Hello!"
  }
}</pre>
        </div>
        <?php
    }

    /* =====================================================================
     * Helpers
     * =================================================================== */

    public static function getUserIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';

        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return sanitize_text_field($ip);
    }
}
