<?php

declare(strict_types=1);

namespace TAW\Core\Media;

use TAW\Helpers\Framework;
use TAW\Support\Alpine;

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

        // Grid-view sidebar (upload.php's default thumbnail view).
        add_action('admin_enqueue_scripts', [self::class, 'enqueueGridSidebarAssets']);
        add_action('admin_footer-upload.php', [self::class, 'renderGridSidebarContainer']);
        add_filter('ajax_query_attachments_args', [self::class, 'filterAjaxQueryAttachmentsArgs']);
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

        $taxQuery = self::buildTaxQueryForFolder($folder);

        if ($taxQuery === null) {
            return;
        }

        $query->set('tax_query', $taxQuery);
    }

    /**
     * Build a tax_query clause for a folder selector value, accepted in any
     * of three shapes so the same taw_media_folder param works from every
     * caller: the classic List-view dropdown (term slug), the Grid-view
     * sidebar (numeric term ID or the literal 'unfiled'), and the AJAX/
     * Backbone bridge (same shapes as the sidebar, since the sidebar is
     * what sets them).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function buildTaxQueryForFolder(mixed $folder): ?array
    {
        if ($folder === null || $folder === '') {
            return null;
        }

        if ($folder === 'unfiled') {
            return [[
                'taxonomy' => self::TAXONOMY,
                'operator' => 'NOT EXISTS',
            ]];
        }

        if (is_numeric($folder)) {
            return [[
                'taxonomy' => self::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => (int) $folder,
            ]];
        }

        return [[
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => (string) $folder,
        ]];
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
     * Filters wp.media Grid view's Backbone query (admin-ajax action
     * query-attachments) the same way applyFolderFilterQuery() filters the
     * classic List view's WP_Query — the sidebar's JS bridge sets a
     * taw_media_folder prop on the Backbone library collection, which WP
     * core merges straight into $query before this filter runs.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public static function filterAjaxQueryAttachmentsArgs(array $query): array
    {
        $folder = $query[self::TAXONOMY] ?? '';

        $taxQuery = self::buildTaxQueryForFolder($folder);

        if ($taxQuery === null) {
            return $query;
        }

        $query['tax_query'] = $taxQuery;

        return $query;
    }

    /**
     * Whether the classic List view (as opposed to the default Grid view)
     * is active on upload.php.
     */
    private static function isListMode(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-mode param, not a form submission
        return isset($_GET['mode']) && sanitize_text_field(wp_unslash($_GET['mode'])) === 'list';
    }

    /**
     * Enqueue the Grid-view sidebar's own assets — separate from
     * enqueueAssets() so that method's Folders-screen/List-mode gate stays
     * unambiguous. Alpine.js is declared as a script dependency (enqueued
     * elsewhere, see Metabox::enqueue_admin_assets()) alongside
     * taw-media-folders so sidebar.js can read the tawMediaFolders global
     * folders.js already localizes, with no second wp_localize_script call.
     */
    public static function enqueueGridSidebarAssets(string $hook): void
    {
        if ($hook !== 'upload.php' || self::isListMode()) {
            return;
        }

        Alpine::enqueue();

        $dir = Framework::path('src/Core/Media/');
        $url = Framework::url('src/Core/Media/');

        wp_enqueue_style(
            'taw-media-sidebar',
            $url . 'sidebar.css',
            ['taw-media-folders'],
            filemtime($dir . 'sidebar.css')
        );

        wp_enqueue_script(
            'taw-media-sidebar',
            $url . 'sidebar.js',
            ['alpinejs', 'taw-media-folders'],
            filemtime($dir . 'sidebar.js'),
            true
        );
    }

    /**
     * Render the Grid-view sidebar's static shell. Detached at the tail of
     * <body> (admin_footer-upload.php) — sidebar.js repositions this exact
     * node next to #wp-media-grid rather than us guessing at a WP-core
     * insertion point. Alpine's x-for below iterates a client-flattened,
     * depth-annotated folder array (sidebar.js), since Alpine has no
     * recursive-template primitive comparable to folders.js's own
     * childrenOf()/renderTreeNodes() recursion.
     */
    public static function renderGridSidebarContainer(): void
    {
        if (self::isListMode()) {
            return;
        }
        ?>
        <div id="taw-media-sidebar" class="taw-media-sidebar" style="display:none;" x-data="tawMediaSidebar" x-init="init()">
            <button type="button" class="button" @click="createFolder(0)">
                <?php esc_html_e('+ New Folder', 'taw-theme'); ?>
            </button>
            <ul class="taw-folders-tree" aria-busy="true">
                <li class="taw-folders-tree__node" :class="{ 'is-selected': selectedFolderId === null }" @click="selectFolder(null)">
                    <div class="taw-folders-tree__row">
                        <span class="taw-folders-tree__name"><?php esc_html_e('All Files', 'taw-theme'); ?></span>
                    </div>
                </li>
                <li class="taw-folders-tree__node" :class="{ 'is-selected': selectedFolderId === 'unfiled' }" @click="selectFolder('unfiled')">
                    <div class="taw-folders-tree__row">
                        <span class="taw-folders-tree__name"><?php esc_html_e('Unfiled', 'taw-theme'); ?></span>
                    </div>
                </li>
                <template x-for="node in flatFolders" :key="node.id">
                    <li
                        class="taw-folders-tree__node"
                        :class="{ 'is-selected': selectedFolderId === node.id }"
                        :style="'padding-left: ' + (node.depth * 12) + 'px'"
                        draggable="true"
                        @click="selectFolder(node.id)"
                        @dragstart="onFolderDragStart($event, node.id)"
                        @dragover.prevent="onFolderDragOver($event)"
                        @dragleave="onFolderDragLeave($event)"
                        @drop.prevent="onFolderDrop($event, node.id)"
                    >
                        <div class="taw-folders-tree__row">
                            <span class="taw-folders-tree__name" x-text="node.name"></span>
                            <span class="taw-folders-tree__actions">
                                <button type="button" class="taw-folders-tree__add" title="<?php esc_attr_e('Add subfolder', 'taw-theme'); ?>" @click.stop="createFolder(node.id)">+</button>
                                <button type="button" class="taw-folders-tree__rename" title="<?php esc_attr_e('Rename', 'taw-theme'); ?>" @click.stop="renameFolder(node.id, node.name)">&#9998;</button>
                                <button type="button" class="taw-folders-tree__delete" title="<?php esc_attr_e('Delete', 'taw-theme'); ?>" @click.stop="deleteFolder(node.id)">&times;</button>
                            </span>
                        </div>
                    </li>
                </template>
            </ul>
        </div>
        <?php
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
