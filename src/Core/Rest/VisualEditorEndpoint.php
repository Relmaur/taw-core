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
            'methods'             => \WP_REST_Server::CREATABLE, // = 'POST'
            'callback'            => [$this, 'save_fields'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
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
    public function save_fields(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();

        // ── Validate payload structure ──────────────────────────

        $postId = absint($body['post_id'] ?? 0);
        $fields = $body['fields'] ?? [];

        if (!$postId) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Missing or invalid post_id.',
            ], 400);
        }

        if (empty($fields) || !is_array($fields)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'No fields provided.',
            ], 400);
        }

        // ── Verify the user can edit THIS specific post ─────────

        if (!current_user_can('edit_post', $postId)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to edit this post.',
            ], 403);
        }

        // Verify the post exists
        $post = get_post($postId);
        if (!$post) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        // ── Process each field change ───────────────────────────

        $saved   = [];
        $errors  = [];

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
        ], $allSucceeded ? 200 : 207); // 207 = Multi-Status (partial success)
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
