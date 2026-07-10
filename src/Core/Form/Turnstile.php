<?php

declare(strict_types=1);

namespace TAW\Core\Form;

/**
 * Cloudflare Turnstile integration — opt-in bot verification for individual
 * forms (`Form::register(['turnstile' => true, ...])`).
 *
 * Keys are read from PHP constants defined in wp-config.php, the same
 * pattern used for DB credentials — never stored in the options table
 * (unlike OptionsPage fields, which are readable via the REST API by
 * anyone with edit_posts). A secret key belongs in wp-config.php, not a
 * metabox field.
 *
 *   define('TAW_TURNSTILE_SITE_KEY', '0x...');
 *   define('TAW_TURNSTILE_SECRET_KEY', '0x...');
 *
 * Get real keys from https://dash.cloudflare.com/?to=/:account/turnstile.
 */
final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private static bool $scriptEnqueued = false;

    public static function isConfigured(): bool
    {
        return defined('TAW_TURNSTILE_SITE_KEY')
            && defined('TAW_TURNSTILE_SECRET_KEY')
            && constant('TAW_TURNSTILE_SITE_KEY') !== ''
            && constant('TAW_TURNSTILE_SECRET_KEY') !== '';
    }

    public static function siteKey(): ?string
    {
        return self::isConfigured() ? (string) constant('TAW_TURNSTILE_SITE_KEY') : null;
    }

    /**
     * Print the Cloudflare widget script tag once per request, regardless
     * of how many Turnstile-enabled forms render on the same page.
     */
    public static function enqueueScript(): void
    {
        if (self::$scriptEnqueued) {
            return;
        }
        self::$scriptEnqueued = true;

        echo '<script src="' . esc_url(self::SCRIPT_URL) . '" async defer></script>' . "\n";
    }

    /**
     * Verify a submitted Turnstile token against Cloudflare's siteverify API.
     * Fails closed: any network error, malformed response, or missing
     * secret key is treated as verification failure, not a pass-through.
     */
    public static function verify(string $token, string $remoteIp): bool
    {
        if (!self::isConfigured() || $token === '') {
            return false;
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 5,
            'body' => [
                'secret' => (string) constant('TAW_TURNSTILE_SECRET_KEY'),
                'response' => $token,
                'remoteip' => $remoteIp,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[TAW Form] Turnstile verification request failed: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) && !empty($body['success']);
    }
}
