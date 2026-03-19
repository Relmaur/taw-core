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

        echo '<div style="margin-top:15px;border-top:1px solid #ddd;padding:10px;color:#666;font-size:11px;">';
        echo '<strong>Webhook Status:</strong> ' . esc_html($webhookStatus);
        if ($webhookError) {
            echo ' <span style="color:#dc3232;">(' . esc_html($webhookError) . ')</span>';
        }
        echo '<br><strong>User IP:</strong> ' . esc_html($userIp);
        echo '</div>';
    }

    /* =====================================================================
     * Save Submission (called by Form::process)
     * =================================================================== */

    /**
     * @param string $formId  Form identifier.
     * @param array  $fields  Field config array from the form config.
     * @param array  $data    Sanitized field_id → value map.
     * @return int|false      Created post ID or false on failure.
     */
    public static function saveSubmission(string $formId, array $fields, array $data): int|false
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

        self::fireWebhook($postId, $formId, $data);

        return $postId;
    }

    /* =====================================================================
     * Webhook
     * =================================================================== */

    private static function fireWebhook(int $postId, string $formId, array $data): void
    {
        $url = get_option(self::WEBHOOK_URL, '');

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
            'ip'           => self::getUserIp(),
            'data'         => $data,
        ];

        $headers = ['Content-Type' => 'application/json'];

        $secret = get_option(self::WEBHOOK_SECRET, '');
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

        add_settings_error(self::PAGE_SLUG, 'settings_updated', 'Webhook settings saved.', 'updated');
    }

    public function renderSettingsPage(): void
    {
        $url       = get_option(self::WEBHOOK_URL, '');
        $hasSecret = !empty(get_option(self::WEBHOOK_SECRET, ''));
        ?>
        <div class="wrap">
            <h1>Form Webhook Settings</h1>
            <p class="description" style="max-width:640px;">
                Every form submission fires a <strong>JSON POST</strong> to the URL below.
                Connect to <strong>n8n</strong>, Zapier, Make, or any automation platform.
            </p>

            <form method="post" action="" style="max-width:640px;">
                <?php wp_nonce_field(self::NONCE_ACTION, 'taw_webhook_settings_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="<?php echo self::WEBHOOK_URL; ?>">Webhook URL</label></th>
                        <td>
                            <input type="url" id="<?php echo self::WEBHOOK_URL; ?>"
                                name="<?php echo self::WEBHOOK_URL; ?>"
                                value="<?php echo esc_attr($url); ?>"
                                class="regular-text"
                                placeholder="https://n8n.example.com/webhook/abc123">
                            <p class="description">Leave empty to disable.</p>
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

    private static function getUserIp(): string
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
