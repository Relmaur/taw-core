<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Icons\Lucide;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Powers the wp-admin Lucide icon picker (see Lucide::enqueuePickerAssets()
 * and src/Core/Icons/picker.js). Only registered when Lucide::enable() has
 * been called — see Lucide::init().
 */
class IconsEndpoint
{
    private const NAMESPACE = 'taw/v1';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/icons', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'search_icons'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $this->get_search_args(),
        ]);
    }

    public function check_permission(): bool
    {
        return current_user_can('edit_posts');
    }

    private function get_search_args(): array
    {
        return [
            'search' => [
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Search string matched against icon names and keywords.',
            ],
            'per_page' => [
                'required'          => false,
                'default'           => 60,
                'sanitize_callback' => 'absint',
                'validate_callback' => static function ($value): bool {
                    $v = absint($value);
                    return $v >= 1 && $v <= 120;
                },
                'description'       => 'Number of results to return (1-120).',
            ],
        ];
    }

    public function search_icons(\WP_REST_Request $request): \WP_REST_Response
    {
        $results = Lucide::search(
            (string) $request->get_param('search'),
            (int) $request->get_param('per_page')
        );

        return new \WP_REST_Response($results, 200);
    }
}
