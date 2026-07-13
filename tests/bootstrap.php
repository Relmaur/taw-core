<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for taw-core's unit test suite.
 *
 * This suite runs WITHOUT a real WordPress install — Brain Monkey mocks
 * individual WP functions per-test instead. That's a deliberate division
 * of labor with taw-theme's bin/ci/smoke-test.php, which boots a real
 * WordPress + MySQL environment and exercises the full render path: this
 * suite is for fast, isolated tests of taw-core's own logic (validation
 * rules, rate limiting, Turnstile verification, etc.), the smoke test is
 * for "does this actually work end-to-end against a real site."
 *
 * Every file in src/ guards itself with `if (!defined('ABSPATH')) exit;`
 * (a standard WordPress security convention preventing direct access
 * outside a WP context) — ABSPATH must be defined before any TAW class is
 * autoloaded, or the PHP process exits the moment such a file is included.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

require __DIR__ . '/../vendor/autoload.php';
