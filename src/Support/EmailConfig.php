<?php

declare(strict_types=1);

namespace TAW\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EmailConfig — configure the outbound email transport for the framework.
 *
 * Usage in functions.php:
 *
 *   use TAW\Support\EmailConfig;
 *
 *   EmailConfig::useEmailit(
 *       apiKey:   defined('EMAILIT_API_KEY') ? EMAILIT_API_KEY : '',
 *       from:     'hello@example.com',
 *       fromName: 'My Site',
 *   );
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
     * @param mixed $return  Existing filter return value (null by default).
     * @param array $atts    wp_mail argument array: to, subject, message, headers, attachments.
     * @return mixed
     */
    public static function interceptForEmailit(mixed $return, array $atts): mixed
    {
        if (self::$emailitApiKey === null || self::$emailitApiKey === '') {
            return null;
        }

        if (!class_exists(\Emailit\Client::class)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[TAW EmailConfig] emailit/emailit-php is not installed. Run: composer require emailit/emailit-php');
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
            $client = new \Emailit\Client(self::$emailitApiKey);
            $client->emails()->send(array_filter([
                'from'    => $from,
                'to'      => $to,
                'subject' => $subject,
                'html'    => $isHtml ? $message : null,
                'text'    => $isHtml ? null : $message,
            ], fn($v) => $v !== null));

            return true;
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[TAW EmailConfig] Emailit send failed: ' . $e->getMessage() . ' — falling back to wp_mail().');
            return null;
        }
    }
}
