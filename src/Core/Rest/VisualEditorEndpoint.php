<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Metabox\Metabox;

if (!defined('ABSPATH')) {
    exit;
}

class VisualEditorEndpoint
{
    private const NAMESPACE = 'taw/v1';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/visual-editor/save', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'save_fields'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => $this->get_save_args(),
        ]);
    }

    /**
     * Define accepted parameters for the save endpoint.
     *
     * WordPress processes these BEFORE save_fields() runs:
     * - 'required' → auto-rejects requests missing the param (400)
     * - 'validate_callback' → auto-rejects invalid values (400)
     * - 'sanitize_callback' → cleans values before your callback
     *
     * By the time save_fields() executes, $request->get_param()
     * returns validated, sanitized data.
     */
    private function get_save_args(): array
    {
        return [
            'post_id' => [
                'required'          => true,
                'sanitize_callback' => 'absint',
                'validate_callback' => function ($value) {
                    return absint($value) > 0;
                },
                'description'       => 'The post ID to save field changes to.',
            ],
            'fields' => [
                'required'          => true,
                'validate_callback' => function ($value) {
                    // Must be a non-empty associative array
                    // Each key is a field ID, each value is a change object
                    return is_array($value) && !empty($value);
                },
                'description'       => 'Object of field changes keyed by field ID.',
            ],
        ];
    }

    /**
     * Permission check — runs BEFORE the callback.
     * 
     * We need two things:
     * 1. The user can edit posts in general
     * 2. The user can edit THIS specific post
     * 
     * We check (2) in the callback because we need the post_id
     * from the request body, which isn't available here reliably.
     */
    public function check_permission(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Save handler — receives batched field changes from the visual editor.
     * 
     * Expected payload:
     * {
     *     "post_id": 42,
     *     "fields": {
     *         "hero_heading": {
     *             "blockId": "hero",
     *             "fieldId": "hero_heading",
     *             "type": "text",
     *             "value": "New Heading Text"
     *         },
     *         "hero_image": {
     *             "blockId": "hero",
     *             "fieldId": "hero_image",
     *             "type": "image",
     *             "value": 456
     *         }
     *     }
     * }
     */
    /**
     * Save handler — receives batched field changes from the visual editor.
     *
     * By the time this runs, WordPress has already verified:
     * - post_id is present and is a positive integer (args validation)
     * - fields is present and is a non-empty array (args validation)
     * - The user can edit posts in general (permission_callback)
     *
     * We still need to verify:
     * - The user can edit THIS specific post (resource-level auth)
     * - The post exists
     * - Each field is registered and editor-enabled (business logic)
     */
    public function save_fields(\WP_REST_Request $request): \WP_REST_Response
    {
        // ── Read from $request (pre-validated by args) ──────────
        //
        // NOTE: We use get_param() instead of get_json_params().
        // get_param() returns data that has been through the args
        // sanitize/validate pipeline. get_json_params() returns
        // the raw parsed JSON — bypassing the args processing.

        $postId = $request->get_param('post_id');
        $fields = $request->get_param('fields');

        // ── Resource-level authorization ────────────────────────
        //
        // The permission_callback checks "can edit posts in general".
        // Here we check "can edit THIS specific post" — the double
        // check pattern VIP expects.

        if (!current_user_can('edit_post', $postId)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to edit this post.',
            ], 403);
        }

        $post = get_post($postId);
        if (!$post) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        // ── Process each field change ───────────────────────────

        $saved  = [];
        $errors = [];

        foreach ($fields as $fieldId => $change) {
            $result = $this->save_single_field($postId, $fieldId, $change);

            if ($result === true) {
                $saved[] = $fieldId;
            } else {
                $errors[$fieldId] = $result;
            }
        }

        // ── Response ────────────────────────────────────────────

        $allSucceeded = empty($errors);

        return new \WP_REST_Response([
            'success' => $allSucceeded,
            'saved'   => $saved,
            'errors'  => $errors,
            'message' => $allSucceeded
                ? sprintf('%d field(s) saved.', count($saved))
                : sprintf('%d saved, %d failed.', count($saved), count($errors)),
        ], $allSucceeded ? 200 : 207);
    }

    /**
     * Save a single field change.
     * 
     * This is where the security magic happens:
     * 1. Look up the field in the registry (rejects unknown fields)
     * 2. Verify it has editor enabled (rejects non-editable fields)
     * 3. Sanitize the value using Metabox's sanitization rules
     * 4. Write to post_meta
     * 
     * @return true|string True on success, error message on failure.
     */
    private function save_single_field(int $postId, string $fieldId, array $change): true|string
    {
        // ── 1. Field must exist in the registry ─────────────────

        $fieldConfig = Metabox::get_field_config($fieldId);

        if (!$fieldConfig) {
            return "Unknown field: {$fieldId}";
        }

        // ── 2. Field must be editor-enabled ─────────────────────

        $editorConfig = Metabox::get_editor_config($fieldId);

        if ($editorConfig === null) {
            return "Field '{$fieldId}' is not editor-enabled.";
        }

        // ── 3. Sanitize the value ───────────────────────────────

        $rawValue       = $change['value'] ?? '';
        $sanitizedValue = Metabox::sanitizeValue($fieldConfig, $rawValue);

        // ── 4. Determine the meta key and save ──────────────────

        $prefix  = $fieldConfig['prefix'] ?? '_taw_';
        $metaKey = $prefix . $fieldId;

        // For group sub-fields, the meta key is already the compound ID
        // (e.g., 'hero_cta_text' → '_taw_hero_cta_text')
        // which is exactly how they're stored. No special handling needed.

        $result = update_post_meta($postId, $metaKey, $sanitizedValue);

        // update_post_meta returns false if the value didn't change,
        // which is not an error — it just means the "new" value was
        // identical to the existing value.
        if ($result === false) {
            // Check if it genuinely failed or just didn't change
            $currentValue = get_post_meta($postId, $metaKey, true);
            if ($currentValue != $sanitizedValue) {
                return "Failed to save field: {$fieldId}";
            }
        }

        return true;
    }
}
