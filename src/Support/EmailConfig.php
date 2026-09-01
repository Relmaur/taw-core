<?php

declare(strict_types=1);

namespace TAW\Support;

use TAW\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EmailConfig — configure the outbound email transport for the framework.
 *
 * Usage in the theme's inc/customizations.php, before Theme::boot():
 *
 *   use TAW\Support\EmailConfig;
 *
 *   if (defined('EMAILIT_API_KEY')) {
 *       EmailConfig::useEmailit(
 *           apiKey:   EMAILIT_API_KEY,
 *           from:     defined('EMAILIT_FROM_EMAIL') ? EMAILIT_FROM_EMAIL : get_bloginfo('admin_email'),
 *           fromName: defined('EMAILIT_FROM_NAME') ? EMAILIT_FROM_NAME : '',
 *       );
 *   }
 *
 * The `defined('EMAILIT_API_KEY')` guard keeps this a true per-site opt-in:
 * only sites whose wp-config.php defines the constant (a site-specific
 * secret — never commit it) activate Emailit at all.
 *
 * Requires the official PHP SDK:
 *   composer require emailit/emailit-php
 *
 * When configured, ALL wp_mail() calls — including form submissions,
 * password resets, and WooCommerce emails — are routed through Emailit.
 * If the SDK is not installed or the API call fails, the call falls
 * back to wp_mail() transparently.
 */
class EmailConfig
{
    private static ?string $emailitApiKey = null;
    private static string  $fromEmail     = '';
    private static string  $fromName      = '';

    /**
     * Route all wp_mail() calls through Emailit's REST API.
     *
     * @param string $apiKey    Your Emailit API key.
     * @param string $from      Sender email address (e.g. hello@example.com).
     * @param string $fromName  Sender display name. Defaults to site name.
     */
    public static function useEmailit(string $apiKey, string $from, string $fromName = ''): void
    {
        self::$emailitApiKey = $apiKey;
        self::$fromEmail     = $from;
        self::$fromName      = $fromName ?: get_bloginfo('name');

        add_filter('pre_wp_mail', [self::class, 'interceptForEmailit'], 10, 2);
    }

    /**
     * pre_wp_mail filter callback.
     *
     * Return non-null to short-circuit wp_mail (we handled it).
     * Return null to fall through to wp_mail (SDK missing or API failed).
     *
     * HTML messages are sent as proper multipart/alternative — both `html`
     * and a `text` part (derived via wp_strip_all_tags()) go to the API
     * together, never `html` alone. A missing plain-text alternative is a
     * real (if small) spam-filter signal, and Emailit's API accepts both
     * params independently rather than treating them as mutually exclusive.
     *
     * @param mixed $return  Existing filter return value (null by default).
     * @param array $atts    wp_mail argument array: to, subject, message, headers, attachments.
     * @return mixed
     */
    public static function interceptForEmailit(mixed $return, array $atts): mixed
    {
        if (self::$emailitApiKey === null || self::$emailitApiKey === '') {
            return null;
        }

        if (!class_exists(\Emailit\EmailitClient::class)) {
            Logger::warning(
                'mail.emailit_package_missing',
                'EmailConfig::useEmailit() is active but emailit/emailit-php is not installed — falling back to wp_mail().',
                ['suggestion' => 'composer require emailit/emailit-php'],
            );
            return null;
        }

        $to      = is_array($atts['to']) ? implode(', ', $atts['to']) : (string) ($atts['to'] ?? '');
        $subject = (string) ($atts['subject'] ?? '');
        $message = (string) ($atts['message'] ?? '');
        $headers = (array)  ($atts['headers'] ?? []);

        $isHtml = false;
        foreach ($headers as $header) {
            if (stripos((string) $header, 'content-type: text/html') !== false) {
                $isHtml = true;
                break;
            }
        }

        $from = self::$fromName
            ? self::$fromName . ' <' . self::$fromEmail . '>'
            : self::$fromEmail;

        try {
            $client = new \Emailit\EmailitClient(self::$emailitApiKey);
            $client->emails()->send(array_filter([
                'from'    => $from,
                'to'      => $to,
                'subject' => $subject,
                'html'    => $isHtml ? $message : null,
                'text'    => $isHtml ? wp_strip_all_tags($message, true) : $message,
            ], fn($v) => $v !== null));

            return true;
        } catch (\Throwable $e) {
            Logger::error(
                'mail.emailit_send_failed',
                'Emailit send failed — falling back to wp_mail().',
                ['exception' => get_class($e), 'error' => $e->getMessage()],
            );
            return null;
        }
    }
}
