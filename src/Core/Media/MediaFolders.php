<?php

declare(strict_types=1);

namespace TAW\Core\Media;

use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in nestable Media Library folders.
 *
 * Model: a single hierarchical taxonomy (taw_media_folder) registered on
 * 'attachment', with show_in_rest => true. That one flag is what gives us,
 * for free from WordPress core:
 *   - full term CRUD (including 'parent', for re-nesting) at
 *     wp/v2/taw_media_folder
 *   - a taw_media_folder param on wp/v2/media, for listing/filtering and
 *     for reassigning a file's folder (PATCH wp/v2/media/<id>)
 * No custom REST endpoint class is needed here — see folders.js, which
 * talks to those two built-in routes directly.
 *
 * Two admin surfaces:
 *   1. A dedicated "Media -> Folders" screen (own markup + folders.js): a
 *      folder tree (create/rename/delete/drag-to-reparent) and a
 *      drag-and-drop attachment grid. 100% our own code — no WordPress
 *      core Backbone/Grid-view internals are touched.
 *   2. Lighter integration into the classic Media Library List view:
 *      a folder filter dropdown, a "Folder" column, and a "Move to
 *      folder..." bulk action.
 *
 * Usage:
 *   // In the theme's inc/customizations.php, before Theme::boot():
 *   TAW\Core\Media\MediaFolders::enable();
 */
class MediaFolders
{
    public const TAXONOMY = 'taw_media_folder';

    private const CAPABILITY = 'upload_files';

    /**
     * Whether Media Folders has been explicitly enabled for this theme.
     * Must call MediaFolders::enable() in customizations.php to activate.
     */
    private static bool $enabled = false;

    /**
     * Opt-in to Media Folders.
     * Call this in the theme's customizations.php before Theme::boot().
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Whether Media Folders has been enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Boot Media Folders.
     * No-op unless enable() was called first.
     */
    public static function init(): void
    {
        if (!self::$enabled) {
            return;
        }

        add_action('init', [self::class, 'registerTaxonomy']);
        add_action('admin_menu', [self::class, 'registerFoldersPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);

        // Classic List view integration.
        add_action('restrict_manage_posts', [self::class, 'renderFolderFilterDropdown'], 10, 2);
        add_action('pre_get_posts', [self::class, 'applyFolderFilterQuery']);
        add_filter('manage_media_columns', [self::class, 'addFolderColumn']);
        add_action('manage_media_custom_column', [self::class, 'renderFolderColumn'], 10, 2);
        add_filter('bulk_actions-upload', [self::class, 'registerBulkMoveAction']);
        add_filter('handle_bulk_actions-upload', [self::class, 'handleBulkMoveAction'], 10, 3);
        add_action('admin_notices', [self::class, 'renderBulkMoveNotice']);

        // Lets the Folders screen's "Unfiled" pseudo-folder query
        // wp/v2/media?taw_unfiled=1 without a dedicated REST endpoint.
        add_filter('rest_attachment_query', [self::class, 'filterRestAttachmentQuery'], 10, 2);
    }

    /**
     * Register the hierarchical folder taxonomy on attachments.
     *
     * show_ui is deliberately false — the default WP taxonomy metabox
     * doesn't fit the attachment edit screen well; the Folders screen and
     * List-view integration below are the real UI.
     */
    public static function registerTaxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, 'attachment', [
            'labels' => [
                'name'          => __('Folders', 'taw-theme'),
                'singular_name' => __('Folder', 'taw-theme'),
            ],
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => false,
            'show_admin_column' => false,
            'show_in_rest'      => true,
            'rest_base'         => self::TAXONOMY,
            'capabilities'      => [
                'manage_terms' => self::CAPABILITY,
                'edit_terms'   => self::CAPABILITY,
                'delete_terms' => self::CAPABILITY,
                'assign_terms' => self::CAPABILITY,
            ],
        ]);
    }

    /**
     * Add the "Media -> Folders" submenu page.
     */
    public static function registerFoldersPage(): void
    {
        add_submenu_page(
            'upload.php',
            __('Folders', 'taw-theme'),
            __('Folders', 'taw-theme'),
            self::CAPABILITY,
            'taw-media-folders',
            [self::class, 'renderFoldersPage']
        );
    }

    /**
     * Enqueue folders.js/folders.css on the Folders screen and on the
     * classic Media Library list screen (which only needs the filter
     * dropdown / bulk-move toggle piece of the script).
     */
    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'media_page_taw-media-folders' && $hook !== 'upload.php') {
            return;
        }

        $dir = Framework::path('src/Core/Media/');
        $url = Framework::url('src/Core/Media/');

        wp_enqueue_style(
            'taw-media-folders',
            $url . 'folders.css',
            [],
            filemtime($dir . 'folders.css')
        );

        wp_enqueue_script(
            'taw-media-folders',
            $url . 'folders.js',
            [],
            filemtime($dir . 'folders.js'),
            true
        );

        wp_localize_script('taw-media-folders', 'tawMediaFolders', [
            'restUrl'   => rest_url('wp/v2/'),
            'nonce'     => wp_create_nonce('wp_rest'),
            'taxonomy'  => self::TAXONOMY,
            'screen'    => $hook === 'media_page_taw-media-folders' ? 'folders' : 'list',
        ]);
    }

    /**
     * Folder filter dropdown for the List view (restrict_manage_posts),
     * plus a hidden "move to folder" target dropdown used by the bulk
     * action — shown/hidden by folders.js when that bulk action is chosen.
     */
    public static function renderFolderFilterDropdown(string $post_type, string $which): void
    {
        if ($post_type !== 'attachment') {
            return;
        }

        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);

        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, not a form submission
        $selected = isset($_GET[self::TAXONOMY]) ? sanitize_text_field(wp_unslash($_GET[self::TAXONOMY])) : '';
        ?>
        <select name="<?php echo esc_attr(self::TAXONOMY); ?>" id="taw-folder-filter">
            <option value=""><?php esc_html_e('All folders', 'taw-theme'); ?></option>
            <?php foreach ($terms as $term): ?>
                <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($selected, $term->slug); ?>>
                    <?php echo esc_html(str_repeat('— ', count(get_ancestors($term->term_id, self::TAXONOMY))) . $term->name); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="taw_target_folder" id="taw-bulk-move-target" style="display:none;">
            <option value=""><?php esc_html_e('Choose a folder…', 'taw-theme'); ?></option>
            <?php foreach ($terms as $term): ?>
                <option value="<?php echo esc_attr((string) $term->term_id); ?>">
                    <?php echo esc_html(str_repeat('— ', count(get_ancestors($term->term_id, self::TAXONOMY))) . $term->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Apply the folder filter dropdown's selection to the List view query.
     */
    public static function applyFolderFilterQuery(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, not a form submission
        $folder = isset($_GET[self::TAXONOMY]) ? sanitize_text_field(wp_unslash($_GET[self::TAXONOMY])) : '';

        if ($folder === '') {
            return;
        }

        $query->set('tax_query', [[
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => $folder,
        ]]);
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public static function addFolderColumn(array $columns): array
    {
        $columns[self::TAXONOMY] = __('Folder', 'taw-theme');

        return $columns;
    }

    public static function renderFolderColumn(string $column_name, int $attachment_id): void
    {
        if ($column_name !== self::TAXONOMY) {
            return;
        }

        $terms = get_the_terms($attachment_id, self::TAXONOMY);

        if (empty($terms) || is_wp_error($terms)) {
            echo '&#8212;';
            return;
        }

        echo esc_html($terms[0]->name);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function registerBulkMoveAction(array $actions): array
    {
        $actions['taw_move_to_folder'] = __('Move to folder…', 'taw-theme');

        return $actions;
    }

    /**
     * @param int[] $post_ids
     */
    public static function handleBulkMoveAction(string $redirect_to, string $action, array $post_ids): string
    {
        if ($action !== 'taw_move_to_folder' || empty($post_ids)) {
            return $redirect_to;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP core verifies the bulk-action nonce before invoking this handler
        $folder_id = isset($_REQUEST['taw_target_folder']) ? absint($_REQUEST['taw_target_folder']) : 0;

        if (!$folder_id || !term_exists($folder_id, self::TAXONOMY)) {
            return $redirect_to;
        }

        foreach ($post_ids as $post_id) {
            wp_set_object_terms((int) $post_id, [$folder_id], self::TAXONOMY);
        }

        return add_query_arg('taw_moved', count($post_ids), $redirect_to);
    }

    public static function renderBulkMoveNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success-count param, not a form submission
        $moved = isset($_GET['taw_moved']) ? absint($_GET['taw_moved']) : 0;

        if (!$moved) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %d: number of files moved */
                    _n('Moved %d file to the selected folder.', 'Moved %d files to the selected folder.', $moved, 'taw-theme'),
                    $moved
                )
            )
        );
    }

    /**
     * Lets wp/v2/media?taw_unfiled=1 return attachments with no folder
     * term assigned, powering the Folders screen's "Unfiled" pseudo-node.
     *
     * @param array<string, mixed> $args
     */
    public static function filterRestAttachmentQuery(array $args, \WP_REST_Request $request): array
    {
        if (!$request->get_param('taw_unfiled')) {
            return $args;
        }

        $args['tax_query'] = [[
            'taxonomy' => self::TAXONOMY,
            'operator' => 'NOT EXISTS',
        ]];

        return $args;
    }

    /**
     * Render the "Media -> Folders" admin page shell. folders.js does the
     * rest (fetching/rendering the tree and attachment grid).
     */
    public static function renderFoldersPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Media Folders', 'taw-theme'); ?></h1>
            <div id="taw-folders-app" class="taw-folders-app">
                <div class="taw-folders-app__tree">
                    <button type="button" class="button" id="taw-folders-new-root">
                        <?php esc_html_e('+ New Folder', 'taw-theme'); ?>
                    </button>
                    <ul id="taw-folders-tree" class="taw-folders-tree" aria-busy="true"></ul>
                </div>
                <div class="taw-folders-app__grid">
                    <h2 id="taw-folders-grid-title"><?php esc_html_e('Select a folder', 'taw-theme'); ?></h2>
                    <div id="taw-folders-grid" class="taw-folders-grid"></div>
                    <button type="button" class="button" id="taw-folders-load-more" style="display:none;">
                        <?php esc_html_e('Load more', 'taw-theme'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
