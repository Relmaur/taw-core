<?php

declare(strict_types=1);

namespace TAW\Core;

class VisualEditor
{
    /**
     * Cached result of the active check.
     * null = not yet determined, bool = resolved.
     */
    private static ?bool $active = null;

    /**
     * The query parameter that activates the visual editor.
     */
    public const QUERY_PARAM = 'taw_visual_edit';

    /**
     * The minimum capability required to use the visual editor.
     */
    public const CAPABILITY = 'edit_posts';

    /**
     * Boot the visual editor system.
     * Call this once during theme initialization (functions.php)
     */
    public static function init(): void
    {

        // Add the "Edit Visually" button to the admin bar
        add_action('admin_bar_menu', [self::class, 'addAdminBarButton'], 90);

        // When in edit mode on the frontend, enqueue editor assets
        if (self::isActive()) {
            add_action('wp_enqueue_scripts', [self::class, 'enqueueEditorAssets']);
        }
    }

    /**
     * Is the visual editor currently active?
     * 
     * Three conditions must ALL be true:
     * 1. We're on the frontend (not wp-admin)
     * 2. The query parameter is present and truthy
     * 3. The current user has the required capability
     */
    public static function isActive(): bool
    {
        // Return cached result if we're already checked
        if (self::$active !== null) {
            return self::$active;
        }

        self::$active = ! is_admin()
            && isset($_GET[self::QUERY_PARAM])
            && $_GET[self::QUERY_PARAM] === '1'
            && current_user_can(self::CAPABILITY);

        return self::$active;
    }

    /**
     * Get the visual editor URL for a given post.
     */
    public static function getEditorUrl(int $postId): string
    {
        $permalink = get_permalink($postId);

        if (!$permalink) {
            return '';
        }

        return add_query_arg(self::QUERY_PARAM, '1', $permalink);
    }

    /**
     * Add "Edit Visuall" Button to the WordPress admin bar.
     */
    public static function addAdminBarButton(\WP_Admin_Bar $adminBar): void
    {

        // Only show for users with the right capability
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        // Determine the target post ID based on context
        $postId = self::resolvePostId();

        if (! $postId) {
            return;
        }

        $editorUrl = self::getEditorUrl($postId);

        if (! $editorUrl) {
            return;
        }

        // If we're already in the edit mode, show "Exit Editor" instead
        if (self::isActive()) {
            $adminBar->add_node([
                'id' => 'taw-visual-editor',
                'title' => __('Exit Editor', 'taw-core'),
                'href' => get_permalink($postId),
                'meta' => [
                    'class' => 'taw-visual-editor-exit',
                    'title' => __('Return to normal view', 'taw-theme')
                ]
            ]);

            return;
        }

        // Normal state: show "Edit Visually"
        $adminBar->add_node([
            'id'    => 'taw-visual-editor',
            'title' => __('Edit Visually', 'taw-theme'),
            'href'  => $editorUrl,
            'meta'  => [
                'class' => 'taw-visual-editor-btn',
                'title' => __('Open the visual content editor', 'taw-theme'),
            ],
        ]);
    }

    /**
     * Enqueue visual editor assets on the frontend.
     */
    public static function enqueueEditorAssets(): void {}

    /**
     * Resolve the current post ID from context.
     * 
     * Works both in wp-admin (edit screen) and on the frontend.
     */
    private static function resolvePostId(): ?int
    {
        // Froentend: use the current queried object
        if (!is_admin()) {
            $queried = get_queried_object();

            if ($queried instanceof \WP_Post) {
                return (int) $queried->ID;
            }
            return null;
        }

        // Admin: check the edit screen
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && $screen->base === 'post') {
            $postId = absint($_GET['post'] ?? $_POST['post_ID'] ?? 0);
            return $postId ?: null;
        }

        return null;
    }
}
