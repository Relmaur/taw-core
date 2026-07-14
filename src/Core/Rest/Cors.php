<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Headless CORS — opt-in cross-origin access for a statically exported
 * frontend (Cloudflare Pages, Vercel, etc.) that needs to reach this
 * WordPress install's REST API (`taw/v1/*`) and form AJAX endpoints
 * (`admin-ajax.php?action=taw_form_*`) from a different domain.
 *
 * Off by default — same pattern as Turnstile: read from a wp-config.php
 * constant, never stored in the options table, never active unless a
 * site owner deliberately opts in.
 *
 *   define('TAW_HEADLESS_ORIGINS', 'https://my-site.pages.dev');
 *   // or a comma-separated list:
 *   define('TAW_HEADLESS_ORIGINS', 'https://my-site.pages.dev,https://staging.my-site.pages.dev');
 *
 * REST API (`/wp-json/taw/v1/...`):
 *   Handled by hooking WordPress core's own `allowed_http_origins` filter
 *   rather than sending CORS headers by hand — core's `rest_send_cors_headers()`
 *   (wired to `rest_pre_serve_request` on every request) already emits the
 *   right Access-Control-* headers and answers OPTIONS preflights for any
 *   origin core considers "allowed". We only need to extend that allowlist.
 *
 * admin-ajax.php (`?action=taw_form_*`):
 *   Core has no CORS awareness here at all — admin-ajax.php is same-origin
 *   only by default. We add the headers ourselves and short-circuit the
 *   OPTIONS preflight before WP tries (and fails) to resolve it as an
 *   ajax action.
 */
final class Cors
{
    public static function register(): void
    {
        if (!self::isConfigured()) {
            return;
        }

        add_filter('allowed_http_origins', [self::class, 'filterAllowedOrigins']);

        // Runs before admin-ajax.php's own action dispatch (which hooks 'init'
        // at default priority 10 via `wp_ajax_*`), so priority 0 wins the race.
        add_action('init', [self::class, 'handleAjaxCors'], 0);
    }

    public static function isConfigured(): bool
    {
        return defined('TAW_HEADLESS_ORIGINS') && constant('TAW_HEADLESS_ORIGINS') !== '';
    }

    /**
     * @return string[]
     */
    private static function allowedOrigins(): array
    {
        if (!self::isConfigured()) {
            return [];
        }

        $raw = (string) constant('TAW_HEADLESS_ORIGINS');

        return array_values(array_filter(array_map(
            static fn (string $origin) => untrailingslashit(trim($origin)),
            explode(',', $raw)
        )));
    }

    /**
     * @param string[] $origins
     * @return string[]
     */
    public static function filterAllowedOrigins(array $origins): array
    {
        return array_values(array_unique(array_merge($origins, self::allowedOrigins())));
    }

    /**
     * admin-ajax.php only ever carries `taw_form_*` actions cross-origin
     * (search stays on REST). Scoped narrowly rather than opening CORS for
     * every admin-ajax action on the install.
     */
    public static function handleAjaxCors(): void
    {
        if (!wp_doing_ajax()) {
            return;
        }

        $action = $_REQUEST['action'] ?? '';
        if (!is_string($action) || !str_starts_with($action, 'taw_form_')) {
            return;
        }

        $origin = self::requestOrigin();
        if ($origin === null || !in_array($origin, self::allowedOrigins(), true)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
        header('Vary: Origin');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            status_header(200);
            exit;
        }
    }

    private static function requestOrigin(): ?string
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        return $origin !== '' ? untrailingslashit(sanitize_text_field($origin)) : null;
    }
}
