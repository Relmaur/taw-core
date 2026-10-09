<?php
/**
 * Fixture theme for the content interchange fidelity suite.
 *
 * Boots taw/core's data layer from the checkout the suite runs (the
 * TAW_FIDELITY_CORE constant, written into wp-config.php by run.sh) and
 * declares the schema in taw-schema/: a fieldset with every field type on
 * pages, posts and books, a term fieldset, an options page.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once TAW_FIDELITY_CORE . '/vendor/autoload.php';

add_action('after_setup_theme', static function (): void {
    \TAW\Core\Boot::data();
    add_theme_support('custom-logo');
    register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
}, 0);

// Site B downloads site A's media from 127.0.0.1 on a test port, which
// WordPress refuses for "unsafe" (local) hosts and ports unless told otherwise.
add_filter('http_request_host_is_external', '__return_true');
add_filter('http_allowed_safe_ports', static fn (array $ports): array => [...$ports, 8881, 8882]);
