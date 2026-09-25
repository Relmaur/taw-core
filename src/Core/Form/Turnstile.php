<?php

declare(strict_types=1);

namespace TAW\Core\Form;

use TAW\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cloudflare Turnstile integration — opt-in bot verification for individual
 * forms (`Form::register(['turnstile' => true, ...])`).
 *
 * Keys are read from PHP constants defined in wp-config.php, the same
 * pattern used for DB credentials — never stored in the options table
 * (an OptionsPage with `rest` publishes its fields over the REST API). A
 * secret key belongs in wp-config.php, not a metabox field.
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
     * Print the shared TAWTurnstile JS helper once per request, regardless
     * of how many Turnstile-enabled forms render on the same page.
     *
     * Deliberately does NOT print a static `<script src="...api.js">` tag
     * (the old implementation). Two problems with that, both stemming from
     * the same root cause — a page-transition library (Swup, Turbo, htmx,
     * any client-side router) swaps DOM via innerHTML, and per the HTML
     * spec a <script> inserted that way — inline OR external — never
     * executes:
     *
     *  1. Cloudflare's *implicit* render mode (the default) auto-scans the
     *     DOM for .cf-turnstile elements exactly once, when api.js itself
     *     finishes loading. A form that appears later — via any client-side
     *     navigation, not just a hard page load — never gets a widget
     *     rendered into its container: it silently has no CAPTCHA, and
     *     server-side verify() then always fails since no token is ever
     *     produced.
     *  2. If a visitor's very first page in a given session has no
     *     Turnstile form at all, the api.js <script src> tag itself would
     *     only ever arrive bundled inside a later, client-side-inserted
     *     page's markup — inert on arrival, so window.turnstile would
     *     never even become defined.
     *
     * Explicit mode plus this helper fixes both structurally: every
     * .cf-turnstile div is rendered by the small inline script
     * Form::renderTurnstile() emits right after it (see there), which
     * works identically whether that markup was parsed on a normal page
     * load or inserted later by a router taw-core has no knowledge of.
     * TAWTurnstile.render() lazy-loads api.js itself, via
     * document.createElement('script') + appendChild — not innerHTML — so
     * it's never subject to the same inertness problem it exists to route
     * around. Idempotent (`window.TAWTurnstile ||`) so re-running this
     * same inline setup script on every client-side navigation is a no-op
     * once the singleton already exists.
     */
    public static function enqueueScript(): void
    {
        if (self::$scriptEnqueued) {
            return;
        }
        self::$scriptEnqueued = true;

        // esc_url_raw(), not esc_url() — this is embedded into a JS string
        // literal below, not an HTML attribute, so esc_url()'s `&` →
        // `&#038;` entity-encoding would corrupt it (HTML entity decoding
        // doesn't apply inside a <script> element's raw-text content).
        // esc_js() on the way in provides the actual JS-string-literal
        // escaping.
        $scriptUrl = esc_js(esc_url_raw(self::SCRIPT_URL . '?render=explicit'));
        ?>
        <script>
        window.TAWTurnstile = window.TAWTurnstile || (function () {
            var pending = [];
            var loading = false;

            function renderOne(el) {
                if (!el || el.dataset.tawWidgetId) return; // already rendered
                var id = window.turnstile.render(el, {
                    sitekey: el.dataset.sitekey,
                    theme: el.dataset.theme || 'light',
                });
                el.dataset.tawWidgetId = id;
            }

            function flush() {
                pending.forEach(renderOne);
                pending = [];
            }

            function ensureLoaded() {
                if (window.turnstile) {
                    flush();
                    return;
                }
                if (loading) return;
                loading = true;

                var script = document.createElement('script');
                script.src = '<?php echo $scriptUrl; ?>';
                script.async = true;
                script.onload = flush;
                document.head.appendChild(script);
            }

            return {
                render: function (el) {
                    pending.push(el);
                    ensureLoaded();
                },
                remove: function (el) {
                    if (el && el.dataset.tawWidgetId && window.turnstile) {
                        window.turnstile.remove(el.dataset.tawWidgetId);
                        delete el.dataset.tawWidgetId;
                    }
                },
            };
        })();
        </script>
        <?php
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
            Logger::warning('form.turnstile_request_failed', 'Turnstile verification request failed.', [
                'error' => $response->get_error_message(),
            ]);
            return false;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) && !empty($body['success']);
    }
}
