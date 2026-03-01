<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

if (!defined('ABSPATH')) {
    exit;
}

class SearchEndpoints
{
    /** REST namespace — versioned so we can evolve without breaking. */
    private const NAMESPACE = 'taw/v1';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/search-posts', [
            'methods'             => \WP_REST_Server::READABLE, // = 'GET'
            'callback'            => [$this, 'search_posts'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $this->get_search_args(),
        ]);
    }

    /**
     * Permission gate — only logged-in users who can edit posts.
     *
     * This runs BEFORE search_posts(). If it returns false,
     * WordPress sends 403 and your callback never executes.
     */
    public function check_permission(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Declare accepted parameters with validation and sanitization.
     *
     * WordPress processes these BEFORE your callback runs.
     * By the time search_posts() executes, $request->get_param()
     * returns clean, validated data — no manual sanitization needed.
     */
    private function get_search_args(): array
    {
        return [
            's' => [
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Search string to match against post titles.',
            ],
            'post_type' => [
                'required'          => false,
                'default'           => 'post',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ($value) {
                    // Accept comma-separated types: "post,page"
                    $types = array_map('trim', explode(',', $value));
                    foreach ($types as $type) {
                        if (!post_type_exists($type)) {
                            return new \WP_Error(
                                'invalid_post_type',
                                sprintf('Post type "%s" does not exist.', $type),
                                ['status' => 400]
                            );
                        }
                    }
                    return true;
                },
                'description'       => 'Post type(s) to search. Comma-separated for multiple.',
            ],
            'per_page' => [
                'required'          => false,
                'default'           => 10,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value) {
                    $v = absint($value);
                    return $v >= 1 && $v <= 50;
                },
                'description'       => 'Number of results to return (1–50).',
            ],
            'exclude' => [
                'required'          => false,
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => 'Comma-separated post IDs to exclude.',
            ],
        ];
    }

    /**
     * The actual search handler.
     *
     * By the time this runs, we know:
     * - The user is logged in and can edit posts (permission_callback passed)
     * - All parameters are sanitized and validated (args passed)
     */
    public function search_posts(\WP_REST_Request $request): \WP_REST_Response
    {
        $search    = $request->get_param('s');
        $post_type = array_map('trim', explode(',', $request->get_param('post_type')));
        $per_page  = $request->get_param('per_page');
        $exclude   = $request->get_param('exclude');

        $query_args = [
            'post_type'      => $post_type,
            'posts_per_page' => $per_page,
            'post_status'    => 'publish',
            'orderby'        => 'relevance',
            'order'          => 'DESC',
        ];

        // Only add search param if there's a search string.
        // Without this, an empty 's' returns nothing in WP_Query.
        if ($search !== '') {
            $query_args['s'] = $search;
        } else {
            // No search term: return recent posts (useful for initial load)
            $query_args['orderby'] = 'date';
        }

        // Exclude specific post IDs (useful when you've already selected some)
        if ($exclude !== '') {
            $exclude_ids = array_filter(array_map('absint', explode(',', $exclude)));
            if (!empty($exclude_ids)) {
                $query_args['post__not_in'] = $exclude_ids;
            }
        }

        $query = new \WP_Query($query_args);
        $results = [];

        foreach ($query->posts as $post) {
            $results[] = $this->format_post($post);
        }

        return new \WP_REST_Response($results, 200);
    }

    /**
     * Format a post for the JSON response.
     *
     * We return only what the Post Selector UI needs — nothing more.
     * This keeps the payload small and avoids leaking data.
     */
    private function format_post(\WP_Post $post): array
    {
        $thumbnail_id = get_post_thumbnail_id($post->ID);

        return [
            'id'        => $post->ID,
            'title'     => get_the_title($post),
            'post_type' => $post->post_type,
            'status'    => $post->post_status,
            'date'      => get_the_date('M j, Y', $post),
            'edit_url'  => get_edit_post_link($post->ID, 'raw'),
            'permalink' => get_permalink($post),
            'thumbnail' => $thumbnail_id
                ? wp_get_attachment_image_url($thumbnail_id, 'thumbnail')
                : '',
        ];
    }
}
