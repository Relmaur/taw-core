<?php

declare(strict_types=1);

namespace TAW\Hub\Cli;

use TAW\Hub\HubServices;
use TAW\Hub\Security\HubIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `wp taw hub …` — terminal parity with the Hub, over the *same*
 * {@see \TAW\Hub\Orchestration\ActionRegistry} the REST `/command` route uses.
 * A terminal operator is trusted, so CLI dispatch runs as a wildcard identity
 * — but still only the bounded action set, never an arbitrary command.
 *
 * Registered by {@see \TAW\Hub\HubIntegration::init()} only when the
 * integration is enabled and WP-CLI is loaded.
 */
final class HubCliCommand
{
    /**
     * List the actions the Hub (and this command) can invoke.
     *
     * ## EXAMPLES
     *
     *     wp taw hub actions
     *
     * @when after_wp_load
     */
    public function actions(): void
    {
        foreach (HubServices::registry()->describe() as $action) {
            \WP_CLI::line(sprintf('%-18s %s', $action['name'], $action['capability']));
        }
    }

    /**
     * Run a Hub action locally.
     *
     * ## OPTIONS
     *
     * <action>
     * : The action name (see `wp taw hub actions`).
     *
     * [--args=<json>]
     * : JSON object of arguments for the action.
     *
     * ## EXAMPLES
     *
     *     wp taw hub run flush-caches --args='{"scopes":["object","rewrites"]}'
     *     wp taw hub run report-telemetry
     *
     * @when after_wp_load
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $action = $args[0] ?? '';
        $parsed = [];

        if (isset($assoc['args'])) {
            $decoded = json_decode((string) $assoc['args'], true);
            if (!is_array($decoded)) {
                \WP_CLI::error('--args must be a JSON object.');
            }
            $parsed = $decoded;
        }

        $result = HubServices::boot()->commands->dispatch(
            new HubIdentity('cli', ['*']),
            $action,
            $parsed,
        );

        \WP_CLI::line((string) wp_json_encode($result['body'], JSON_PRETTY_PRINT));

        if ($result['status'] >= 400) {
            \WP_CLI::halt(1);
        }
    }

    /**
     * Show enrolment + trusted-key status.
     *
     * ## EXAMPLES
     *
     *     wp taw hub status
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        $enrolment = get_option('taw_hub_enrolment', []);
        $keys      = get_option('taw_hub_enrolled_keys', []);

        \WP_CLI::line('Enabled:        ' . (\TAW\Hub\HubConfig::enabled() ? 'yes' : 'no'));
        \WP_CLI::line('Enrolment used: ' . (is_array($enrolment) && ($enrolment['consumed'] ?? false) ? 'yes' : 'no'));
        \WP_CLI::line('Enrolled keys:  ' . (is_array($keys) ? count($keys) : 0));
    }
}
