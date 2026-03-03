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
            add_action('wp_footer', [self::class, 'renderEditorShell'], 99);
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
    public static function enqueueEditorAssets(): void
    {
        // WordPress media picker (needed for image fields)
        wp_enqueue_media();

        $editorDir = Framework::path() . '/src/Support/visual-editor';
        $editorUrl = Framework::url() . '/src/Support/visual-editor';

        wp_enqueue_style(
            'taw-visual-editor',
            $editorUrl . '/editor.css',
            [],
            filemtime($editorDir . '/editor.css')
        );

        // Editor script - depends on Alpine (already loaded by the theme)
        wp_enqueue_script(
            'taw-visual-editor',
            $editorUrl . '/editor.js',
            [],
            filemtime($editorDir . '/editor.js'),
            true
        );

        // Pass data to the editor script
        wp_localize_script('taw-visual-editor', 'tawEditor', [
            'postId' => get_queried_object_id(),
            'restUrl' => rest_url('taw/v1/visual-editor/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'exitUrl' => get_permalink(get_queried_object_id())
        ]);

        // Add body class for layout shift
        add_filter('body_class', function (array $classes): array {
            $classes[] = 'taw-visual-editor-active';
            return $classes;
        });
    }

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

    /**
     * Inject the editor wrapper and save bar HTML into the page footer.
     */
    public static function renderEditorShell(): void
    {
?>
        <div x-data="tawVisualEditor" id="taw-visual-editor-root">

            <!-- ── Right-Side Panel ─────────────────────────── -->
            <div class="taw-editor-panel">

                <!-- Panel Header -->
                <div class="taw-editor-panel__header">
                    <h2 class="taw-editor-panel__title">Visual Editor</h2>
                    <a :href="tawEditor.exitUrl" class="taw-editor-panel__close" title="Exit editor">✕</a>
                </div>

                <!-- Idle State: list available blocks -->
                <div x-show="panelMode === 'idle'" class="taw-editor-panel__body">
                    <p class="taw-editor-panel__hint">
                        Click any editable element on the page, or select a section below.
                    </p>
                    <div class="taw-editor-panel__blocks">
                        <template x-for="block in availableBlocks" :key="block.blockId">
                            <button class="taw-editor-panel__block-btn"
                                @click="selectSection(block.blockId)">
                                <span class="taw-editor-panel__block-name" x-text="block.blockId"></span>
                                <span class="taw-editor-panel__block-count"
                                    x-text="block.fieldCount + ' field' + (block.fieldCount !== 1 ? 's' : '')"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Field Mode: single field editor -->
                <div x-show="panelMode === 'field'" class="taw-editor-panel__body">
                    <button class="taw-editor-panel__back" @click="expandToSection()">
                        ← All <span x-text="activeBlockId"></span> fields
                    </button>

                    <template x-if="activeFieldInfo">
                        <div class="taw-editor-panel__field">
                            <label class="taw-editor-panel__field-label" x-text="activeFieldInfo.label"></label>
                            <span class="taw-editor-panel__field-type" x-text="activeFieldInfo.type"></span>

                            <!-- Text / URL / Number input -->
                            <template x-if="['text', 'url', 'number'].includes(activeFieldInfo.type)">
                                <input class="taw-editor-panel__input"
                                    :type="activeFieldInfo.type === 'number' ? 'number' : 'text'"
                                    :value="getFieldValue(activeFieldInfo.fieldId)"
                                    @input="panelFieldUpdate(activeFieldInfo.fieldId, $event.target.value)">
                            </template>

                            <!-- Textarea -->
                            <template x-if="activeFieldInfo.type === 'textarea'">
                                <textarea class="taw-editor-panel__textarea"
                                    rows="4"
                                    :value="getFieldValue(activeFieldInfo.fieldId)"
                                    @input="panelFieldUpdate(activeFieldInfo.fieldId, $event.target.value)"></textarea>
                            </template>

                            <!-- WYSIWYG (simplified textarea for MVP) -->
                            <template x-if="activeFieldInfo.type === 'wysiwyg'">
                                <textarea class="taw-editor-panel__textarea"
                                    rows="6"
                                    :value="getFieldValue(activeFieldInfo.fieldId)"
                                    @input="panelFieldUpdate(activeFieldInfo.fieldId, $event.target.value)"></textarea>
                            </template>

                            <!-- Image -->
                            <template x-if="activeFieldInfo.type === 'image'">
                                <div class="taw-editor-panel__image-field">
                                    <img :src="getFieldValue(activeFieldInfo.fieldId)"
                                        class="taw-editor-panel__image-preview"
                                        x-show="getFieldValue(activeFieldInfo.fieldId)">
                                    <button class="taw-editor-panel__btn"
                                        @click="panelImagePicker(activeFieldInfo.fieldId)">
                                        Change Image
                                    </button>
                                </div>
                            </template>

                            <!-- Inline edit shortcut for text types -->
                            <template x-if="['text', 'textarea', 'wysiwyg'].includes(activeFieldInfo.type)">
                                <button class="taw-editor-panel__btn taw-editor-panel__btn--secondary"
                                    @click="startInlineEdit(activeFieldInfo.el)">
                                    Edit inline on page
                                </button>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Section Mode: all fields for a block -->
                <div x-show="panelMode === 'section'" class="taw-editor-panel__body">
                    <button class="taw-editor-panel__back" @click="deselect()">
                        ← Back to overview
                    </button>
                    <h3 class="taw-editor-panel__section-title" x-text="activeBlockId"></h3>

                    <template x-for="field in activeSectionFields" :key="field.fieldId">
                        <div class="taw-editor-panel__field"
                            @click.stop="focusField(field.fieldId)">
                            <label class="taw-editor-panel__field-label" x-text="field.label"></label>
                            <span class="taw-editor-panel__field-type" x-text="field.type"></span>

                            <template x-if="['text', 'url', 'number'].includes(field.type)">
                                <input class="taw-editor-panel__input"
                                    :type="field.type === 'number' ? 'number' : 'text'"
                                    :value="getFieldValue(field.fieldId)"
                                    @input="panelFieldUpdate(field.fieldId, $event.target.value)"
                                    @click.stop>
                            </template>

                            <template x-if="field.type === 'textarea' || field.type === 'wysiwyg'">
                                <textarea class="taw-editor-panel__textarea"
                                    rows="3"
                                    :value="getFieldValue(field.fieldId)"
                                    @input="panelFieldUpdate(field.fieldId, $event.target.value)"
                                    @click.stop></textarea>
                            </template>

                            <template x-if="field.type === 'image'">
                                <div class="taw-editor-panel__image-field" @click.stop>
                                    <img :src="getFieldValue(field.fieldId)"
                                        class="taw-editor-panel__image-preview"
                                        x-show="getFieldValue(field.fieldId)">
                                    <button class="taw-editor-panel__btn"
                                        @click="panelImagePicker(field.fieldId)">
                                        Change
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Save Bar (always at bottom of panel) -->
                <div class="taw-editor-panel__footer" :class="{ 'has-changes': hasChanges }">
                    <div class="taw-editor-panel__changes" x-show="hasChanges">
                        <span x-text="changeCount + ' unsaved ' + (changeCount === 1 ? 'change' : 'changes')"></span>
                    </div>
                    <div class="taw-editor-panel__actions">
                        <button class="taw-editor-panel__btn taw-editor-panel__btn--secondary"
                            @click="discard()"
                            x-show="hasChanges">
                            Discard
                        </button>
                        <button class="taw-editor-panel__btn taw-editor-panel__btn--primary"
                            @click="save()"
                            :disabled="!hasChanges || saving"
                            x-text="saving ? 'Saving…' : 'Save'">
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Toast Container ──────────────────────────── -->
            <div class="taw-editor-toasts">
                <template x-for="toast in toasts" :key="toast.id">
                    <div class="taw-editor-toast"
                        :class="['taw-editor-toast--' + toast.type, { 'visible': toast.visible }]"
                        @click="dismissToast(toast.id)">
                        <span x-text="toast.message"></span>
                    </div>
                </template>
            </div>

        </div>
<?php
    }
}
