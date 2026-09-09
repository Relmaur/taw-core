<?php

declare(strict_types=1);

namespace TAW\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in security hardening helpers.
 *
 * Unlike Lucide / MediaFolders (which stay dormant until enable()), the
 * helpers here are wired into Theme::boot() by default — they are
 * auth-gated, their failure mode is minimal, and locking down user
 * enumeration matches TAW's opinionated-defaults stance. Each one still
 * carries its own `apply_filters()` escape hatch so a headless or
 * integration site can put the stock WordPress behaviour back without
 * editing framework code.
 *
 * Usage (already done for you by Theme::boot()):
 *
 *   TAW\Core\Security\Hardening::hideUsersEndpoint();
 *
 * To opt a site back out, in inc/customizations.php or a plugin:
 *
 *   add_filter('taw_security_hide_users_endpoint', '__return_false');
 */
class Hardening
{
    /**
     * Guards against registering the rest_endpoints filter more than once
     * per request — hideUsersEndpoint() is safe to call from both
     * Theme::boot() and a theme's inc/security.php.
     */
    private static bool $usersEndpointFilterAdded = false;

    /**
     * Hide the public `/wp/v2/users` REST collection and the single-user
     * `/wp/v2/users/(?P<id>[\d]+)` route from anonymous requests.
     *
     * Why filter at `rest_endpoints` rather than match a URL: this runs at
     * REST dispatch, *after* the request has been resolved to a route, so
     * it closes every routing form in one place —
     * `/wp-json/wp/v2/users`, the query-routed `/?rest_route=/wp/v2/users`
     * (which host WAFs and "hide users endpoint" plugins that only match
     * the `/wp-json/` path form typically miss, and which leaks id, name
     * and author slug ≈ username for every user), and `/batch/v1`
     * sub-requests.
     *
     * What stays reachable:
     *   - `/wp/v2/users/me` — the authenticated self-lookup the WordPress
     *     mobile apps, Jetpack and the block editor all need. It already
     *     401s without a valid login, so it is not an enumeration vector.
     *   - Everything, for any logged-in user. The block editor's author
     *     selector fetches `/wp/v2/users?who=authors` for Editors too, who
     *     lack the `list_users` capability — gating on
     *     `is_user_logged_in()` keeps that working. Enumeration is an
     *     anonymous-attacker threat, so authentication is the right line.
     */
    public static function hideUsersEndpoint(): void
    {
        if (self::$usersEndpointFilterAdded) {
            return;
        }

        self::$usersEndpointFilterAdded = true;

        add_filter('rest_endpoints', [self::class, 'filterUsersEndpoints']);
    }

    /**
     * `rest_endpoints` callback for hideUsersEndpoint(). Public only so the
     * filter can be registered by class-callable; not part of the intended
     * API surface.
     *
     * @param array<string, mixed> $endpoints Route regex => endpoint config.
     * @return array<string, mixed>
     */
    public static function filterUsersEndpoints(array $endpoints): array
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }

        /**
         * Filter: allow a site to keep the public users collection exposed.
         *
         * Return false to restore stock WordPress behaviour — needed by
         * headless front ends or integrations that read
         * `/wp/v2/users` anonymously.
         *
         * @param bool $hide Whether to remove the public users routes for
         *                    anonymous requests. Default true.
         */
        if (!apply_filters('taw_security_hide_users_endpoint', true)) {
            return $endpoints;
        }

        unset(
            $endpoints['/wp/v2/users'],
            $endpoints['/wp/v2/users/(?P<id>[\d]+)']
        );

        return $endpoints;
    }
}
