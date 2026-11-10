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
 *
 * What carries over from wp_mail(): to (array or comma-separated), subject,
 * the message (HTML when a `Content-Type: text/html` header says so), the
 * Reply-To, Cc and Bcc headers, and attachments (read from disk and sent
 * base64). A From header is ignored: Emailit sends from the address given
 * to useEmailit(), the one its domain verification covers. An attachment
 * that can't be read falls back to wp_mail() rather than sending without it.
 */
class EmailConfig
{
    private static ?string $emailitApiKey = null;
    private static string  $fromEmail     = '';
    private static string  $fromName      = '';

    /** Retries after Emailit's rate limit (429) before falling back to wp_mail(). */
    public const RATE_LIMIT_RETRIES = 2;

    /** The longest wait honoured from a 429's Retry-After, in seconds. */
    public const RATE_LIMIT_MAX_WAIT = 3.0;

    /** @var (callable(float): void)|null test seam for the wait between retries */
    private static $sleeper = null;

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

        // wp_mail()'s 'to' is either an array or a single string that may itself be
        // comma-separated (both are valid WordPress/PHPMailer conventions, which
        // splits a comma-joined string back into individual recipients itself).
        // Emailit's API has no such convention — it only understands a plain
        // string (one recipient) or a real string[] (multiple recipients) — so a
        // naive implode(', ', ...)/cast here would hand it a single string
        // containing several "@"s, which Emailit's own address parsing can't
        // split back apart correctly.
        $toList = is_array($atts['to'])
            ? $atts['to']
            : explode(',', (string) ($atts['to'] ?? ''));
        $toList = array_values(array_filter(array_map('trim', $toList), fn($v) => $v !== ''));
        $to     = match (count($toList)) {
            0       => '',
            1       => $toList[0],
            default => $toList,
        };

        $subject = (string) ($atts['subject'] ?? '');
        $message = (string) ($atts['message'] ?? '');
        $parsed  = self::parseHeaders($atts['headers'] ?? []);
        $isHtml  = $parsed['html'];

        $attachments = self::readAttachments($atts['attachments'] ?? []);
        if ($attachments === null) {
            return null;
        }

        $from = self::$fromName
            ? self::$fromName . ' <' . self::$fromEmail . '>'
            : self::$fromEmail;

        $payload = [
            'from'    => $from,
            'to'      => $to,
            'subject' => $subject,
            'html'        => $isHtml ? $message : null,
            'text'        => $isHtml ? wp_strip_all_tags($message, true) : $message,
            'reply_to'    => $parsed['reply_to'],
            'cc'          => $parsed['cc'] ?: null,
            'bcc'         => $parsed['bcc'] ?: null,
            'attachments' => $attachments ?: null,
        ];
        $payload = array_filter($payload, fn($v) => $v !== null);

        // Emailit allows a few messages a second per account. A second email
        // in the same request (a client's copy right after the studio's) can
        // hit that limit: wait and retry, instead of handing the message to
        // the host's mail, which the domain's SPF/DKIM may not cover.
        for ($attempt = 0; ; $attempt++) {
            try {
                $client = new \Emailit\EmailitClient(self::$emailitApiKey);
                $client->emails()->send($payload);

                if ($attempt > 0) {
                    Logger::info('mail.emailit_rate_limit_retried', 'Emailit accepted the message after its rate limit.', ['retries' => $attempt]);
                }
                return true;
            } catch (\Emailit\Exceptions\RateLimitException $e) {
                if ($attempt < self::RATE_LIMIT_RETRIES) {
                    self::wait(self::retryAfter($e->getHttpHeaders(), $attempt));
                    continue;
                }
                Logger::error(
                    'mail.emailit_send_failed',
                    'Emailit send failed — falling back to wp_mail().',
                    ['exception' => get_class($e), 'error' => $e->getMessage(), 'retries' => $attempt],
                );
                return null;
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

    /**
     * Seconds to wait before retry $attempt (0-based): a 429's Retry-After
     * when it sends one (capped), otherwise 1 s, then 2 s.
     *
     * @param array<string, mixed> $headers
     */
    public static function retryAfter(array $headers, int $attempt): float
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'retry-after') {
                continue;
            }
            $value = is_array($value) ? reset($value) : $value;
            if (is_numeric($value)) {
                return max(0.0, min(self::RATE_LIMIT_MAX_WAIT, (float) $value));
            }
        }

        return min(self::RATE_LIMIT_MAX_WAIT, (float) ($attempt + 1));
    }

    /** Tests replace the wait; null puts usleep() back. */
    public static function setSleeper(?callable $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    private static function wait(float $seconds): void
    {
        if (self::$sleeper !== null) {
            (self::$sleeper)($seconds);
            return;
        }
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }

    /**
     * wp_mail() headers (an array, or one string with a header per line) →
     * whether the body is HTML, plus Reply-To / Cc / Bcc.
     *
     * @param mixed $headers
     * @return array{html: bool, reply_to: ?string, cc: list<string>, bcc: list<string>}
     */
    private static function parseHeaders(mixed $headers): array
    {
        $lines = is_array($headers) ? $headers : preg_split('/\r\n|\r|\n/', (string) $headers);
        $out   = ['html' => false, 'reply_to' => null, 'cc' => [], 'bcc' => []];

        foreach ((array) $lines as $line) {
            $line = (string) $line;
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $name = strtolower($name);
            if ($value === '') {
                continue;
            }

            if ($name === 'content-type' && stripos($value, 'text/html') !== false) {
                $out['html'] = true;
            } elseif ($name === 'reply-to' && $out['reply_to'] === null) {
                $out['reply_to'] = $value;
            } elseif ($name === 'cc' || $name === 'bcc') {
                foreach (explode(',', $value) as $address) {
                    if (($address = trim($address)) !== '') {
                        $out[$name][] = $address;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * wp_mail() attachments (paths, as an array or one per line; a string key
     * is the file name to show) → Emailit's base64 attachments. Null when a
     * file can't be read, so the caller falls back to wp_mail() instead of
     * sending without it.
     *
     * @param mixed $attachments
     * @return list<array{filename: string, content: string, content_type?: string}>|null
     */
    private static function readAttachments(mixed $attachments): ?array
    {
        $list = is_array($attachments) ? $attachments : preg_split('/\r\n|\r|\n/', (string) $attachments);
        $out  = [];

        foreach ((array) $list as $key => $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $content = is_readable($path) ? file_get_contents($path) : false;
            if ($content === false) {
                Logger::error(
                    'mail.emailit_attachment_unreadable',
                    'An attachment could not be read — sending through wp_mail() instead.',
                    ['file' => basename($path)],
                );
                return null;
            }

            $name = is_string($key) && $key !== '' ? $key : basename($path);
            $file = ['filename' => $name, 'content' => base64_encode($content)];
            $type = function_exists('wp_check_filetype') ? wp_check_filetype($name)['type'] : false;
            if (is_string($type) && $type !== '') {
                $file['content_type'] = $type;
            }
            $out[] = $file;
        }

        return $out;
    }
}
