<?php

declare(strict_types=1);

namespace TAW\Core\Media;

use TAW\Core\Icons\Lucide;
use TAW\Helpers\Framework;
use TAW\Support\Alpine;

if (!defined('ABSPATH')) { 
    exit;
}

/**
 * Opt-in nestable Media Library folders ("TAW Media").
 *
 * Model: a single hierarchical taxonomy (taw_media_folder) registered on
 * 'attachment', with show_in_rest => true. That one flag is what gives us,
 * for free from WordPress core:
 *   - full term CRUD (including 'parent', for re-nesting) at
 *     wp/v2/taw_media_folder
 *   - a taw_media_folder param on wp/v2/media, for listing/filtering and
 *     for reassigning a file's folder (PATCH wp/v2/media/<id>)
 * No custom REST endpoint class is needed here — see folders.js, which
 * talks to those two built-in routes directly (plus wp/v2/media's own
 * multipart upload support for the dedicated screen's drag-and-drop upload).
 *
 * Three admin surfaces:
 *   1. A dedicated "Media -> TAW Media" screen (own markup + folders.js): a
 *      full Alpine.js app — folder tree, breadcrumb, folder cards, direct
 *      file upload, multi-select + bulk move/delete. 100% our own code —
 *      no WordPress core Backbone/Grid-view internals are touched.
 *   2. Lighter integration into the classic Media Library List view:
 *      a folder filter dropdown, a "Folder" column, and a "Move to
 *      folder..." bulk action.
 *   3. A FileBird-style sidebar bolted onto the default Media Library Grid
 *      view (upload.php's thumbnail grid).
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
     * Whether TAW Media has been explicitly enabled for this theme.
     * Must call MediaFolders::enable() in customizations.php to activate.
     */
    private static bool $enabled = false;

    /**
     * Opt-in to TAW Media.
     * Call this in the theme's customizations.php before Theme::boot().
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Whether TAW Media has been enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Boot TAW Media.
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

        // Lets the TAW Media screen's "Unfiled" pseudo-folder query
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
     * doesn't fit the attachment edit screen well; the TAW Media screen and
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
            // WP core's default count callback only counts attachments that
            // are 'publish' status (or whose parent post is) — standalone
            // Media Library uploads are 'inherit' with no parent, so they'd
            // never be counted and every folder badge would read 0 no
            // matter what's actually assigned. This counts relationships
            // directly, with no post-status filtering.
            'update_count_callback' => '_update_generic_term_count',
            'capabilities'      => [
                'manage_terms' => self::CAPABILITY,
                'edit_terms'   => self::CAPABILITY,
                'delete_terms' => self::CAPABILITY,
                'assign_terms' => self::CAPABILITY,
            ],
        ]);
    }

    /**
     * Add the "Media -> TAW Media" submenu page.
     */
    public static function registerFoldersPage(): void
    {
        add_submenu_page(
            'upload.php',
            __('TAW Media', 'taw-theme'),
            __('TAW Media', 'taw-theme'),
            self::CAPABILITY,
            'taw-media-folders',
            [self::class, 'renderFoldersPage']
        );
    }

    /**
     * Enqueue folders.js/folders.css on the TAW Media screen and on the
     * classic Media Library list screen (which only needs the filter
     * dropdown / bulk-move toggle piece of the script).
     */
    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'media_page_taw-media-folders' && $hook !== 'upload.php') {
            return;
        }

        Alpine::enqueue();

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
            ['alpinejs'],
            filemtime($dir . 'folders.js'),
            true
        );

        $icon = static fn (string $name, string $class = ''): string => Lucide::render($name, ['class' => $class]);

        wp_localize_script('taw-media-folders', 'tawMediaFolders', [
            'restUrl'      => rest_url('wp/v2/'),
            'nonce'        => wp_create_nonce('wp_rest'),
            'taxonomy'     => self::TAXONOMY,
            'screen'       => $hook === 'media_page_taw-media-folders' ? 'folders' : 'list',
            // Rendered once here (rather than have the JS build SVG strings
            // itself) so every screen's folder cards/breadcrumb/icons use
            // the same Lucide icon set.
            'folderIcon'   => $icon('folder', 'taw-folder-card__icon-svg'),
            'homeIcon'     => $icon('house', 'taw-breadcrumb__icon'),
            'fileIcon'     => $icon('image', 'taw-folders-grid__icon-svg'),
            'uploadIcon'   => $icon('upload-cloud', 'taw-icon'),
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

        // WP_Query::parse_tax_query() auto-detects any top-level query var
        // matching a registered taxonomy's query_var (self::TAXONOMY
        // resolves truthy on admin requests) and adds its OWN slug-based
        // tax_query clause from it, AND-combined with ours. Harmless here
        // only because the List view's dropdown already sends a slug, which
        // happens to match — clearing it removes the reliance on that
        // coincidence (see filterAjaxQueryAttachmentsArgs(), where the same
        // auto-detection makes every Grid-view folder selection return zero
        // results, since it sends a numeric term ID instead).
        $query->set(self::TAXONOMY, '');
    }

    /**
     * Build a tax_query clause for a folder selector value, accepted in any
     * of three shapes so the same taw_media_folder param works from every
     * caller: the classic List-view dropdown (term slug), the TAW Media
     * screen / Grid-view sidebar (numeric term ID or the literal 'unfiled'),
     * and the AJAX/Backbone bridge (same shapes as the sidebar, since the
     * sidebar is what sets them).
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
                'taxonomy'         => self::TAXONOMY,
                'field'            => 'term_id',
                'terms'            => (int) $folder,
                // WP_Query defaults tax_query to include descendant terms
                // for hierarchical taxonomies — without this, opening a
                // folder also shows every subfolder's files mixed in.
                // Subfolders get their own tile/card to navigate into
                // instead; a folder view should only ever show what's
                // directly inside it.
                'include_children' => false,
            ]];
        }

        return [[
            'taxonomy'         => self::TAXONOMY,
            'field'            => 'slug',
            'terms'            => (string) $folder,
            'include_children' => false,
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
     * term assigned, powering the "Unfiled" pseudo-folder on both the TAW
     * Media screen and the Grid-view sidebar.
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

        // Must come off $query regardless of $folder's value: WP_Query::
        // parse_tax_query() auto-detects any top-level key matching a
        // registered taxonomy's query_var (self::TAXONOMY resolves truthy
        // on admin requests, which includes admin-ajax.php) and adds its
        // OWN tax_query clause from it, by 'field' => 'slug' — since the
        // sidebar sends a numeric term ID (or 'unfiled') here, not a slug,
        // that auto-generated clause never matches anything, and being
        // AND-combined with our own explicit tax_query below, silently
        // makes every folder selection return zero results.
        unset($query[self::TAXONOMY]);

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
     * enqueueAssets() so that method's TAW Media screen/List-mode gate
     * stays unambiguous. Alpine.js is enqueued by both this and
     * enqueueAssets() (Alpine::enqueue() is idempotent — wp_enqueue_script
     * no-ops on an already-registered handle).
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
     * Render the Grid-view sidebar's static shell, wrapped in an inert
     * <template> — a <template> element's content is parsed but never
     * becomes part of the live document (no scripts run, no Alpine x-data
     * initializes), which is deliberate: Alpine's own bundle queues
     * Alpine.start() via queueMicrotask() the instant it executes, and the
     * HTML spec runs a microtask checkpoint after every classic <script>
     * finishes — before the next sibling script runs. If this markup were
     * live in the initial HTML, Alpine could scan and fail on
     * x-data="tawMediaSidebar" before sidebar.js's own script (which
     * registers that component) ever got a chance to run. Keeping it
     * inert and having sidebar.js clone this template's content into the
     * live DOM itself — strictly after registering the component —
     * sidesteps that race entirely: Alpine's built-in MutationObserver
     * (the same mechanism that lets it handle dynamically-inserted,
     * AJAX-loaded content) picks up the freshly-inserted node regardless
     * of whether Alpine.start() already ran.
     *
     * Alpine's x-for below iterates a client-flattened, depth-annotated
     * folder array (sidebar.js), since Alpine has no recursive-template
     * primitive comparable to folders.js's own childrenOf()/
     * renderTreeNodes() recursion.
     */
    public static function renderGridSidebarContainer(): void
    {
        if (self::isListMode()) {
            return;
        }
        $icon = static fn(string $name, string $class = ''): string => Lucide::render($name, ['class' => 'taw-icon ' . $class]);
    ?>
        <template id="taw-media-sidebar-template">
            <div id="taw-media-sidebar" class="taw-media-sidebar" :class="{ 'is-collapsed': sidebarCollapsed }" x-data="tawMediaSidebar" x-init="init()">
                <div class="taw-media-sidebar__header">
                    <span class="taw-media-sidebar__title" x-show="!sidebarCollapsed"><?php esc_html_e('Folders', 'taw-theme'); ?></span>
                    <button type="button" class="taw-media-sidebar__icon-btn" @click="toggleSidebar()" :title="sidebarCollapsed ? '<?php echo esc_js(__('Expand', 'taw-theme')); ?>' : '<?php echo esc_js(__('Collapse', 'taw-theme')); ?>'">
                        <span x-show="!sidebarCollapsed"><?php echo $icon('panel-left-close'); ?></span>
                        <span x-show="sidebarCollapsed" x-cloak><?php echo $icon('panel-left-open'); ?></span>
                    </button>
                </div>

                <div class="taw-media-sidebar__toolbar" x-show="!sidebarCollapsed">
                    <button type="button" class="taw-media-sidebar__btn taw-media-sidebar__btn--primary" @click="createFolder(typeof selectedFolderId === 'number' ? selectedFolderId : 0)">
                        <?php echo $icon('folder-plus'); ?>
                        <?php esc_html_e('New Folder', 'taw-theme'); ?>
                    </button>
                    <button type="button" class="taw-media-sidebar__btn" :disabled="!canRename" @click="renameSelected()" title="<?php esc_attr_e('Rename', 'taw-theme'); ?>">
                        <?php echo $icon('pencil'); ?>
                    </button>
                    <button type="button" class="taw-media-sidebar__btn" :disabled="!canDelete" @click="deleteSelected()" title="<?php esc_attr_e('Delete', 'taw-theme'); ?>">
                        <?php echo $icon('trash-2'); ?>
                    </button>
                    <div class="taw-media-sidebar__menu">
                        <button type="button" class="taw-media-sidebar__icon-btn" @click="menuOpen = !menuOpen" @click.outside="menuOpen = false" title="<?php esc_attr_e('More', 'taw-theme'); ?>">
                            <?php echo $icon('ellipsis-vertical'); ?>
                        </button>
                        <div class="taw-media-sidebar__dropdown" x-show="menuOpen" x-cloak>
                            <button type="button" @click="expandAll(); menuOpen = false">
                                <?php echo $icon('unfold-vertical'); ?>
                                <?php esc_html_e('Expand all', 'taw-theme'); ?>
                            </button>
                            <button type="button" @click="showFolderIds = !showFolderIds; menuOpen = false">
                                <?php echo $icon('hash'); ?>
                                <span x-text="showFolderIds ? '<?php echo esc_js(__('Hide folder IDs', 'taw-theme')); ?>' : '<?php echo esc_js(__('Display folder IDs', 'taw-theme')); ?>'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <ul class="taw-folders-tree" aria-busy="true" x-show="!sidebarCollapsed">
                    <div class="default-trees">
                        <li class="taw-folders-tree__node" :class="{ 'is-selected': selectedFolderId === null }" @click="selectFolder(null)">
                            <div class="taw-folders-tree__row">
                                <?php echo $icon('folder-tree'); ?>
                                <span class="taw-folders-tree__name"><?php esc_html_e('All Files', 'taw-theme'); ?></span>
                            </div>
                        </li>
                        <li
                            class="taw-folders-tree__node"
                            :class="{ 'is-selected': selectedFolderId === 'unfiled' }"
                            @click="selectFolder('unfiled')"
                            @dragover.prevent="onFolderDragOver($event)"
                            @dragleave="onFolderDragLeave($event)"
                            @drop.prevent="onFolderDrop($event, 'unfiled')">
                            <div class="taw-folders-tree__row">
                                <?php echo $icon('folder-x'); ?>
                                <span class="taw-folders-tree__name"><?php esc_html_e('Unfiled', 'taw-theme'); ?></span>
                            </div>
                        </li>
                    </div>
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
                            @drop.prevent="onFolderDrop($event, node.id)">
                            <div class="taw-folders-tree__row">
                                <button
                                    type="button"
                                    class="taw-folders-tree__toggle"
                                    :class="{ 'is-invisible': !node.hasChildren }"
                                    @click.stop="toggleExpanded(node.id)">
                                    <span x-show="!isCollapsed(node.id)"><?php echo $icon('chevron-down'); ?></span>
                                    <span x-show="isCollapsed(node.id)" x-cloak><?php echo $icon('chevron-right'); ?></span>
                                </button>
                                <span x-show="selectedFolderId === node.id" class="folder-icon folder-open"><?php echo $icon('folder-open'); ?></span>
                                <span x-show="selectedFolderId !== node.id" class="folder-icon folder-closed"><?php echo $icon('folder'); ?></span>
                                <span class="taw-folders-tree__name" x-text="node.name"></span>
                                <span class="taw-folders-tree__id" x-show="showFolderIds" x-text="'#' + node.id"></span>
                                <span class="taw-folders-tree__count" x-text="node.count"></span>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
    <?php
    }

    /**
     * Render the "Media -> TAW Media" admin page shell — a full Alpine.js
     * app (folders.js registers 'tawFoldersApp'). Deliberately not wrapped
     * in an inert <template> the way renderGridSidebarContainer() is: this
     * markup lives on its own dedicated page (not injected next to a
     * pre-existing wp.media Backbone app), so there's no equivalent
     * ordering race to worry about — folders.js is enqueued with 'alpinejs'
     * as a hard dependency, guaranteeing it runs after Alpine's own script,
     * same as every other Alpine component in this codebase
     * (Metabox/OptionsPage/Icons) except the Grid-view sidebar specifically.
     */
    public static function renderFoldersPage(): void
    {
        $icon = static fn (string $name, string $class = ''): string => Lucide::render($name, ['class' => 'taw-icon ' . $class]);
    ?>
        <div class="wrap">
            <h1><?php esc_html_e('TAW Media', 'taw-theme'); ?></h1>
            <div id="taw-folders-app" class="taw-folders-app" x-data="tawFoldersApp" x-init="init()">
                <div class="taw-folders-app__tree" :class="{ 'is-collapsed': sidebarCollapsed }">
                    <div class="taw-media-sidebar__header">
                        <span class="taw-media-sidebar__title" x-show="!sidebarCollapsed"><?php esc_html_e('Folders', 'taw-theme'); ?></span>
                        <button type="button" class="taw-media-sidebar__icon-btn" @click="sidebarCollapsed = !sidebarCollapsed" :title="sidebarCollapsed ? '<?php echo esc_js(__('Expand', 'taw-theme')); ?>' : '<?php echo esc_js(__('Collapse', 'taw-theme')); ?>'">
                            <span x-show="!sidebarCollapsed"><?php echo $icon('panel-left-close'); ?></span>
                            <span x-show="sidebarCollapsed" x-cloak><?php echo $icon('panel-left-open'); ?></span>
                        </button>
                    </div>

                    <div class="taw-media-sidebar__toolbar" x-show="!sidebarCollapsed">
                        <button type="button" class="taw-media-sidebar__btn taw-media-sidebar__btn--primary" @click="createFolder(typeof selectedFolderId === 'number' ? selectedFolderId : 0)">
                            <?php echo $icon('folder-plus'); ?>
                            <?php esc_html_e('New Folder', 'taw-theme'); ?>
                        </button>
                        <button type="button" class="taw-media-sidebar__btn" :disabled="!canRename" @click="renameSelected()" title="<?php esc_attr_e('Rename', 'taw-theme'); ?>">
                            <?php echo $icon('pencil'); ?>
                        </button>
                        <button type="button" class="taw-media-sidebar__btn" :disabled="!canDelete" @click="deleteSelected()" title="<?php esc_attr_e('Delete', 'taw-theme'); ?>">
                            <?php echo $icon('trash-2'); ?>
                        </button>
                        <div class="taw-media-sidebar__menu">
                            <button type="button" class="taw-media-sidebar__icon-btn" @click="menuOpen = !menuOpen" @click.outside="menuOpen = false" title="<?php esc_attr_e('More', 'taw-theme'); ?>">
                                <?php echo $icon('ellipsis-vertical'); ?>
                            </button>
                            <div class="taw-media-sidebar__dropdown" x-show="menuOpen" x-cloak>
                                <button type="button" @click="expandAll(); menuOpen = false">
                                    <?php echo $icon('unfold-vertical'); ?>
                                    <?php esc_html_e('Expand all', 'taw-theme'); ?>
                                </button>
                                <button type="button" @click="showFolderIds = !showFolderIds; menuOpen = false">
                                    <?php echo $icon('hash'); ?>
                                    <span x-text="showFolderIds ? '<?php echo esc_js(__('Hide folder IDs', 'taw-theme')); ?>' : '<?php echo esc_js(__('Display folder IDs', 'taw-theme')); ?>'"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <ul class="taw-folders-tree" x-show="!sidebarCollapsed">
                        <div class="default-trees">
                            <li class="taw-folders-tree__node" :class="{ 'is-selected': selectedFolderId === null }" @click="selectFolder(null)">
                                <div class="taw-folders-tree__row">
                                    <?php echo $icon('folder-tree'); ?>
                                    <span class="taw-folders-tree__name"><?php esc_html_e('All Files', 'taw-theme'); ?></span>
                                </div>
                            </li>
                            <li
                                class="taw-folders-tree__node"
                                :class="{ 'is-selected': selectedFolderId === 'unfiled' }"
                                @click="selectFolder('unfiled')"
                                @dragover.prevent="onFolderDragOver($event)"
                                @dragleave="onFolderDragLeave($event)"
                                @drop.prevent="onFolderDrop($event, 'unfiled')">
                                <div class="taw-folders-tree__row">
                                    <?php echo $icon('folder-x'); ?>
                                    <span class="taw-folders-tree__name"><?php esc_html_e('Unfiled', 'taw-theme'); ?></span>
                                </div>
                            </li>
                        </div>
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
                                @drop.prevent="onFolderDrop($event, node.id)">
                                <div class="taw-folders-tree__row">
                                    <button
                                        type="button"
                                        class="taw-folders-tree__toggle"
                                        :class="{ 'is-invisible': !node.hasChildren }"
                                        @click.stop="toggleExpanded(node.id)">
                                        <span x-show="!isCollapsed(node.id)"><?php echo $icon('chevron-down'); ?></span>
                                        <span x-show="isCollapsed(node.id)" x-cloak><?php echo $icon('chevron-right'); ?></span>
                                    </button>
                                    <span x-show="selectedFolderId === node.id" class="folder-icon folder-open"><?php echo $icon('folder-open'); ?></span>
                                    <span x-show="selectedFolderId !== node.id" class="folder-icon folder-closed"><?php echo $icon('folder'); ?></span>
                                    <span class="taw-folders-tree__name" x-text="node.name"></span>
                                    <span class="taw-folders-tree__id" x-show="showFolderIds" x-text="'#' + node.id"></span>
                                    <span class="taw-folders-tree__count" x-text="node.count"></span>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="taw-folders-app__grid" @dragover.prevent="onDropzoneDragOver($event)" @dragleave="onDropzoneDragLeave($event)" @drop.prevent="onDropzoneDrop($event)" :class="{ 'is-dropzone-active': isDraggingFiles }">
                    <div class="taw-media-breadcrumb" x-show="breadcrumb.length">
                        <a href="#" class="taw-media-breadcrumb__link" @click.prevent="selectFolder(null)">
                            <?php echo $icon('house', 'taw-breadcrumb__icon'); ?>
                            <span><?php esc_html_e('All Files', 'taw-theme'); ?></span>
                        </a>
                        <template x-for="(crumb, index) in breadcrumb" :key="crumb.id">
                            <span>
                                <span class="taw-media-breadcrumb__sep">/</span>
                                <a href="#" class="taw-media-breadcrumb__link" x-show="index < breadcrumb.length - 1" @click.prevent="selectFolder(crumb.id)" x-text="crumb.name"></a>
                                <span class="taw-media-breadcrumb__current" x-show="index === breadcrumb.length - 1" x-text="crumb.name"></span>
                            </span>
                        </template>
                    </div>

                    <h2 id="taw-folders-grid-title" x-show="!breadcrumb.length" x-text="selectedFolderId === 'unfiled' ? '<?php echo esc_js(__('Unfiled', 'taw-theme')); ?>' : '<?php echo esc_js(__('All Files', 'taw-theme')); ?>'"></h2>

                    <div class="taw-folder-cards" x-show="folderCards.length">
                        <template x-for="folder in folderCards" :key="folder.id">
                            <div
                                class="taw-folder-card"
                                @click="selectFolder(folder.id)"
                                @dragover.prevent="onFolderDragOver($event)"
                                @dragleave="onFolderDragLeave($event)"
                                @drop.prevent="onFolderDrop($event, folder.id)">
                                <span class="taw-folder-card__icon"><?php echo $icon('folder'); ?></span>
                                <span class="taw-folder-card__name" x-text="folder.name"></span>
                                <span class="taw-folder-card__count" x-text="folder.count"></span>
                            </div>
                        </template>
                    </div>

                    <div class="taw-folders-toolbar">
                        <button type="button" class="taw-media-sidebar__btn taw-media-sidebar__btn--primary" @click="$refs.fileInput.click()">
                            <?php echo $icon('upload-cloud'); ?>
                            <?php esc_html_e('Upload Files', 'taw-theme'); ?>
                        </button>
                        <input type="file" multiple hidden x-ref="fileInput" @change="onFileInputChange($event)">
                        <template x-if="hasSelection">
                            <div class="taw-folders-toolbar__selection">
                                <span x-text="selectedAttachments.length + ' <?php echo esc_js(__('selected', 'taw-theme')); ?>'"></span>
                                <button type="button" class="taw-media-sidebar__btn" @click="bulkDelete()">
                                    <?php echo $icon('trash-2'); ?>
                                    <?php esc_html_e('Delete', 'taw-theme'); ?>
                                </button>
                                <button type="button" class="taw-media-sidebar__btn" @click="clearSelection()">
                                    <?php esc_html_e('Clear', 'taw-theme'); ?>
                                </button>
                            </div>
                        </template>
                        <span class="taw-folders-toolbar__uploading" x-show="uploading" x-text="uploadProgress ? ('<?php echo esc_js(__('Uploading', 'taw-theme')); ?> ' + uploadProgress.done + '/' + uploadProgress.total) : ''"></span>
                    </div>

                    <div id="taw-folders-grid" class="taw-folders-grid">
                        <template x-for="media in gridItems" :key="media.id">
                            <div
                                class="taw-folders-grid__item"
                                :class="{ 'is-selected': isAttachmentSelected(media.id) }"
                                draggable="true"
                                :title="media.title"
                                @click="toggleAttachmentSelected(media.id)"
                                @dragstart="onAttachmentDragStart($event, media.id)">
                                <img x-show="media.thumb" :src="media.thumb" alt="">
                                <span x-show="!media.thumb"><?php echo $icon('image', 'taw-folders-grid__icon-svg'); ?></span>
                                <span class="taw-folders-grid__name" x-text="media.title"></span>
                                <span class="taw-folders-grid__check" x-show="isAttachmentSelected(media.id)"><?php echo $icon('check'); ?></span>
                            </div>
                        </template>
                    </div>
                    <p class="taw-folders-grid__empty" x-show="!gridLoading && !gridItems.length"><?php esc_html_e('No files in this folder.', 'taw-theme'); ?></p>
                    <button type="button" class="button" id="taw-folders-load-more" x-show="hasMore" @click="loadMoreGrid()">
                        <?php esc_html_e('Load more', 'taw-theme'); ?>
                    </button>

                    <div class="taw-folders-dropzone-overlay" x-show="isDraggingFiles" x-cloak>
                        <?php echo $icon('upload-cloud', 'taw-folders-dropzone-overlay__icon'); ?>
                        <span><?php esc_html_e('Drop to upload here', 'taw-theme'); ?></span>
                    </div>
                </div>
            </div>
        </div>
<?php
    }
}
