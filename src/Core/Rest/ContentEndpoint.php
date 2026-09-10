<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Content\Exporter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `GET /wp-json/taw/v1/content/export` — the same portable snapshot the
 * Tools → TAW Data screen's Export button produces, for CI / external
 * tooling / a headless build step.
 *
 * Read-only. Gated on the core `export` capability (Administrators and
 * Editors by default). Import has no REST route in this iteration — it
 * stays on the admin screen and the CLI, where the mandatory dry-run and
 * rollback snapshot live.
 */
final class ContentEndpoint
{
    private const NAMESPACE = 'taw/v1';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/content/export', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'export'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'types' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Comma-separated post types to limit the export to.',
                ],
                'since' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Only export posts dated on or after this date (Y-m-d).',
                ],
                'include_media' => [
                    'required' => false,
                    'default'  => true,
                    'type'     => 'boolean',
                ],
            ],
        ]);
    }

    public function check_permission(): bool
    {
        return current_user_can('export');
    }

    public function export(\WP_REST_Request $request): \WP_REST_Response
    {
        $scope = ['include_media' => (bool) $request->get_param('include_media')];

        $types = (string) $request->get_param('types');
        if ($types !== '') {
            $scope['types'] = array_values(array_filter(array_map('trim', explode(',', $types))));
        }

        $since = (string) $request->get_param('since');
        if ($since !== '') {
            $scope['since'] = $since;
        }

        $exporter = new Exporter();
        $snapshot = $exporter->snapshot($scope);

        $response = new \WP_REST_Response($snapshot);
        $response->header('X-TAW-Content-Schema', Exporter::SCHEMA_VERSION);

        return $response;
    }
}
