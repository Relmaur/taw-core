<?php

declare(strict_types=1);

namespace TAW\Core\Auth;

use TAW\Core\Form\RateLimiter;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PagePassword — gate a page template behind a single shared password.
 *
 * Declared directly in a page template, before any output (before
 * get_header(), before any echo) — not a wp-admin toggle, not a sitewide
 * subsystem needing an enable() step:
 *
 *   use TAW\Core\Auth\PagePassword;
 *
 *   PagePassword::protect([
 *       'password' => defined('TAW_CLIENT_PORTAL_PASSWORD') ? TAW_CLIENT_PORTAL_PASSWORD : '',
 *       'title'    => 'Client Submission Portal',
 *   ]);
 *
 *   get_header();
 *   // ... the real template, only reached once unlocked ...
 *
 * On a locked visit, protect() renders a minimal standalone gate screen and
 * exits — the calling template never continues past that call. On success,
 * it sets a signed, stateless unlock cookie (tied to wp_salt('auth'), not
 * forgeable by a client just setting a cookie manually) and redirects back
 * to the same URL (POST/redirect/GET, avoiding a resubmission on refresh).
 *
 * Deliberately fails CLOSED (denies access) rather than gracefully
 * degrading when misconfigured (no 'password' given) — this gates access,
 * not a supplementary check like Form's Turnstile integration, so an
 * unconfigured gate must never fall open.
 *
 * The password itself should live in that site's wp-config.php as a PHP
 * constant, never hardcoded in a committed template — same convention this
 * package already uses for TAW_TURNSTILE_SECRET_KEY.
 *
 * @package TAW
 */
final class PagePassword
{
    private const COOKIE_PREFIX = 'taw_pw_';

    public static function protect(array $config): void
    {
        $password = (string) ($config['password'] ?? '');
        $id       = (string) ($config['id'] ?? substr(md5($password), 0, 12));
        $duration = (int) ($config['duration'] ?? (30 * DAY_IN_SECONDS));
        $title    = (string) ($config['title'] ?? __('Protected Page', 'taw'));

        if ($password === '') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    '[TAW PagePassword] protect() called with no "password" configured — denying access. '
                    . 'Set a non-empty password (typically a wp-config.php constant).',
                    E_USER_WARNING
                );
            }
            self::renderGate($id, $title, __('This page is not available.', 'taw'));
            // renderGate() always exits.
        }

        if (self::isUnlocked($id)) {
            return;
        }

        $nonceAction = 'taw_page_password_' . $id;

        if (isset($_POST['taw_page_password_submit'])) {
            $ip = SubmissionsHandler::getUserIp();

            if (RateLimiter::tooManyAttempts($nonceAction, $ip, 5, 300)) {
                self::renderGate($id, $title, __('Too many attempts. Please wait a few minutes and try again.', 'taw'));
            }

            $nonceOk = isset($_POST['taw_page_password_nonce'])
                && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['taw_page_password_nonce'])), $nonceAction);

            if (!$nonceOk) {
                self::renderGate($id, $title, __('Your session expired. Please try again.', 'taw'));
            }

            $submitted = isset($_POST['taw_page_password']) ? (string) wp_unslash($_POST['taw_page_password']) : '';

            if (hash_equals($password, $submitted)) {
                self::grantAccess($id, $duration);
                // grantAccess() always exits.
            }

            self::renderGate($id, $title, __('Incorrect password. Please try again.', 'taw'));
        }

        self::renderGate($id, $title, null);
    }

    private static function cookieName(string $id): string
    {
        return self::COOKIE_PREFIX . $id;
    }

    private static function sign(string $id, int $expiry): string
    {
        return hash_hmac('sha256', $id . '|' . $expiry, wp_salt('auth'));
    }

    private static function isUnlocked(string $id): bool
    {
        $raw = $_COOKIE[self::cookieName($id)] ?? '';

        if (!is_string($raw) || !str_contains($raw, '.')) {
            return false;
        }

        [$expiryRaw, $signature] = explode('.', $raw, 2);
        $expiry = (int) $expiryRaw;

        if ($expiry < time()) {
            return false;
        }

        return hash_equals(self::sign($id, $expiry), $signature);
    }

    private static function grantAccess(string $id, int $duration): void
    {
        $expiry = time() + $duration;
        $value  = $expiry . '.' . self::sign($id, $expiry);

        setcookie(self::cookieName($id), $value, [
            'expires'  => $expiry,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        wp_safe_redirect(self::currentUrl());
        exit;
    }

    /**
     * Mirrors Form::currentPageUrl()'s construction — HTTP_HOST with a
     * home_url()-derived fallback, since neither $_SERVER key is guaranteed
     * present on every SAPI/proxy setup.
     */
    private static function currentUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';

        return (is_ssl() ? 'https://' : 'http://') . $host . $uri;
    }

    /**
     * Renders a minimal, fully standalone gate screen (its own <html>/<head>/
     * <body> — deliberately not get_header()/get_footer(), since those
     * belong to the very page this is gating) and exits. The calling
     * template never continues past protect() until this stops being
     * reached.
     */
    private static function renderGate(string $id, string $title, ?string $error): void
    {
        $nonceAction = 'taw_page_password_' . $id;
        $formCssUrl  = Framework::url('assets/form.css');
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html($title); ?></title>
<link rel="stylesheet" href="<?php echo esc_url($formCssUrl); ?>">
<style>
    body {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        margin: 0;
        background: #f5f5f4;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    .taw-pw-gate {
        max-width: 22rem;
        width: 90%;
        background: #fff;
        padding: 2rem;
        border-radius: 0.75rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
    }
    .taw-pw-gate h1 {
        font-size: 1.25rem;
        margin: 0 0 1rem;
        color: #1c1917;
    }
</style>
</head>
<body>
<div class="taw-pw-gate">
    <h1><?php echo esc_html($title); ?></h1>
    <?php if ($error): ?>
        <div class="taw-general-error" role="alert"><?php echo esc_html($error); ?></div>
    <?php endif; ?>
    <form method="post" class="taw-form" novalidate>
        <?php wp_nonce_field($nonceAction, 'taw_page_password_nonce'); ?>
        <div class="taw-form-field">
            <label class="taw-field-label" for="taw_page_password"><?php esc_html_e('Password', 'taw'); ?></label>
            <input type="password" id="taw_page_password" name="taw_page_password" class="taw-input" autofocus required>
        </div>
        <div class="taw-form-actions">
            <button type="submit" name="taw_page_password_submit" value="1" class="taw-btn taw-btn-primary">
                <?php esc_html_e('Enter', 'taw'); ?>
            </button>
        </div>
    </form>
</div>
</body>
</html>
        <?php
        exit;
    }
}
