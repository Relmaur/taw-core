<?php

declare(strict_types=1);

namespace TAW\Core\Metabox;

use TAW\Helpers\Framework;

/**
 * Native WordPress Metabox Framework
 *
 * A reusable, configuration-driven class for registering metaboxes.
 * Supports: text, textarea, wysiwyg, image, url, number, select fields.
 *
 * Usage:
 *   new Metabox([
 *       'id'      => 'my_metabox',
 *       'title'   => 'My Fields',
 *       'screens' => ['page', 'post'],          // post type slugs
 *       // 'screens' => ['about-us', 'contact'], // page/post slugs
 *       // 'screens' => ['page-about.php'],       // template filenames
 *       // 'screens' => ['page', 'page-home.php', 'about-us'], // mixed
 *       'fields'  => [ ... ],
 *   ]);
 *
 * @package TAW
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metabox
{
    /** @var string Unique metabox identifier. Used as the HTML id and meta key base. */
    private string $id;

    /** @var string Human-readable title displayed in the WordPress editor. */
    private string $title;

    /** @var array<int, string> Post type slugs / template filenames this metabox is registered on (e.g. ['page', 'post']). */
    private array $screens;

    /** @var string Metabox position context: 'normal', 'side', or 'advanced'. */
    private string $context;

    /** @var string Metabox render priority: 'high', 'default', or 'low'. */
    private string $priority;

    /** @var string Prefix prepended to every meta key (e.g. '_taw_'). */
    private string $prefix;

    /** @var array<int, array<string, mixed>> Array of field definition arrays. */
    private array $fields;

    /** @var array<int, array<string, mixed>> Optional tab definitions grouping fields into tabs. */
    private array $tabs;

    /** @var string Raw icon value: a dashicons class (e.g. 'dashicons-admin-settings'), an SVG string, or empty. */
    private string $icon;

    /** @var bool Guards against enqueuing the WP Color Picker init script more than once. */
    private static bool $color_script_enqueued = false;

    /** @var bool Guards against enqueuing the post selector script more than once. */
    private static bool $post_selector_script_enqueued = false;

    /** @var bool Guards against enqueuing the repeater script more than once. */
    private static bool $repeater_script_enqueued = false;

    /** @var bool Guards against registering admin notices more than once. */
    private static bool $notices_registered = false;

    /** @var bool Guards against registering SEO integration hooks more than once. */
    private static bool $seo_integration_registered = false;

    /**
     * Global registry of field configurations, keyed by field ID.
     * Populated during metabox construction.
     * 
     * Structure: [
     *   'hero_heading' => [
     *     'id' => 'hero_heading',
     *     'type' => 'text',
     *     'editor' => true,
     *     'metabox_id' => 'taw_hero',
     *     // ... all other field config
     *   ],
     * ]
     */
    /** @var array stores field configurations */
    private static array $fieldRegistry = [];


    /** @var callable|null Callback to conditionally show the metabox. Receives WP_Post. */
    private $show_on;

    /** @var bool Guards against enqueuing the media-uploader image script more than once. */
    private static bool $image_script_enqueued = false;

    /** @var bool Guards against enqueuing the multi-file picker script more than once. */
    private static bool $files_script_enqueued = false;

    /** @var bool Guards against enqueuing the datepicker script more than once. */
    private static bool $datepicker_script_enqueued = false;

    /** @var bool Guards against enqueuing general admin assets (CSS/JS) more than once. */
    private static bool $assets_enqueued = false;

    /**
     * Screen type that determines which WordPress hooks are used.
     *
     * - 'post_type' (default): Standard post/page/CPT metabox via add_meta_boxes / save_post.
     * - 'nav_menu':            Menu item fields via wp_nav_menu_item_custom_fields / wp_update_nav_menu_item.
     *
     * @var string
     */
    private string $type;

    /**
     * @param array $config {
     *     @type string   $id       Unique metabox ID.
     *     @type string   $title    Metabox title shown in the editor.
     *     @type string|string[] $screens  Post type(s) to attach to. Also accepts legacy 'screen'. Default 'page'.
     *     @type string          $context  'normal', 'side', or 'advanced'. Default 'normal'.
     *     @type string   $priority 'high', 'default', or 'low'. Default 'high'.
     *     @type string   $prefix   Meta key prefix. Default '_taw_'.
     *     @type callable $show_on  Optional callback(WP_Post): bool — return false to hide the metabox.
     *     @type array    $fields   Array of field definitions.
     *     @type array    $tabs     Optional array of tab definitions.
     *     @type string   $icon     Optional icon shown in the metabox handle before the title.
     *                              Accepts a dashicons class (e.g. 'dashicons-admin-settings'),
     *                              a raw SVG string, or a URL to an image file.
     * }
     */
    public function __construct(array $config)
    {

        $this->id       = $config['id'];
        $this->title    = $config['title'];
        $this->screens  = (array)($config['screens'] ?? $config['screen'] ?? 'page');
        $this->context  = $config['context']  ?? 'normal';
        $this->priority = $config['priority'] ?? 'high';
        $this->prefix   = $config['prefix']   ?? '_taw_';
        $this->fields   = $config['fields']   ?? [];
        $this->show_on  = $config['show_on']  ?? null;
        $this->tabs     = $config['tabs'] ?? [];
        $this->icon     = $config['icon'] ?? '';

        $this->type = $config['type'] ?? 'post_type';

        if ($this->type === 'nav_menu') {
            add_action('wp_nav_menu_item_custom_fields', [$this, 'render_for_nav_menu'], 10, 5);
            add_action('wp_update_nav_menu_item', [$this, 'save_nav_menu'], 10, 3);
        } else {
            add_action('add_meta_boxes', [$this, 'register']);
            add_action('save_post', [$this, 'save'], 10, 2);
        }

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        if (!self::$notices_registered) {
            add_action('admin_notices', [self::class, 'displayValidationErrors']);
            self::$notices_registered = true;
        }

        if (!self::$seo_integration_registered) {
            self::$seo_integration_registered = true;
            new SeoContentIntegration();
        }

        // Register fields in the static registry for the visual editor
        foreach ($this->fields as $field) {
            self::$fieldRegistry[$field['id']] = array_merge($field, [
                'metabox_id' => $this->id,
                'prefix'     => $this->prefix,
            ]);

            // Group: register sub-fields with compound IDs
            if (($field['type'] ?? '') === 'group' && !empty($field['fields'])) {
                $groupEditorSetting = $field['editor'] ?? false;

                foreach ($field['fields'] as $subField) {
                    $compoundId = $field['id'] . '_' . $subField['id'];

                    if (!array_key_exists('editor', $subField)) {
                        $subField['editor'] = $groupEditorSetting;
                    }

                    self::$fieldRegistry[$compoundId] = array_merge($subField, [
                        'metabox_id'   => $this->id,
                        'prefix'       => $this->prefix,
                        'parent_group' => $field['id'],
                    ]);
                }
            }

            // Repeater TODO: sub-fields intentionally excluded from visual editor.
            // Repeater data is a single JSON blob requiring row-index-aware
            // editing — planned for a future iteration.
        }
    }

    /**
     * Returns the raw screens array as configured.
     *
     * Each entry may be a registered post type slug, a page/post slug, or a
     * template filename (ending in .php). Override in a subclass for dynamic
     * resolution, or pass 'screens' in the constructor config.
     *
     * @return string[]
     */
    public function screens(): array
    {
        return $this->screens;
    }

    /**
     * Splits the screens array into three buckets:
     *   - post types  (registered via post_type_exists())
     *   - templates   (strings ending in .php)
     *   - slugs       (everything else — matched against post_name)
     *
     * @return array{0: string[], 1: string[], 2: string[]}
     */
    private function parseScreens(): array
    {
        $postTypes = [];
        $templates = [];
        $slugs     = [];

        foreach ($this->screens() as $screen) {
            if (str_ends_with($screen, '.php')) {
                $templates[] = $screen;
            } elseif (post_type_exists($screen)) {
                $postTypes[] = $screen;
            } else {
                $slugs[] = $screen;
            }
        }

        return [$postTypes, $templates, $slugs];
    }

    /**
     * Checks whether a post matches any of the configured template screens.
     *
     * Two sources are checked for each template entry:
     *
     *   1. Explicit assignment — the `_wp_page_template` post meta set when the
     *      user selects a template from the Page Attributes dropdown.
     *
     *   2. Template hierarchy — WordPress automatically applies `page-{slug}.php`
     *      to the page whose slug is `{slug}` without writing any meta. We detect
     *      this pattern and compare against the post slug directly.
     *
     * @param \WP_Post $post      The post being evaluated.
     * @param string[] $templates Template filenames from parseScreens().
     * @return bool
     */
    private function postMatchesTemplate(\WP_Post $post, array $templates): bool
    {
        $assigned = get_post_meta($post->ID, '_wp_page_template', true) ?: '';

        $frontPageId = (int) get_option('page_on_front');

        foreach ($templates as $template) {
            // Explicit template assignment via Page Attributes
            if ($template === $assigned) {
                return true;
            }

            // front-page.php is applied by WP to the page set as the static
            // front page — no meta is written, so we match by page_on_front.
            if ($template === 'front-page.php' && $frontPageId && $post->ID === $frontPageId) {
                return true;
            }

            // Template hierarchy: page-{slug}.php auto-applies to the page
            // with that slug — no meta is ever written in this case.
            if (preg_match('/^page-(.+)\.php$/', $template, $m) && $post->post_name === $m[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register the metabox with WordPress via `add_meta_box()`.
     *
     * Hooked to `add_meta_boxes`. Each entry in screens() is evaluated:
     *   - Post type slugs   → registered unconditionally for that post type.
     *   - Template files    → registered when the current post uses that template
     *                         (explicit assignment OR template hierarchy naming).
     *   - Page/post slugs   → registered when the current post's slug matches.
     *
     * Skips registration entirely if the optional `show_on` callback returns false.
     *
     * @return void
     */
    public function register(): void
    {
        $post = get_post();

        if ($post && is_callable($this->show_on) && !call_user_func($this->show_on, $post)) {
            return;
        }

        [$postTypes, $templates, $slugs] = $this->parseScreens();

        // Evaluate slug/template conditions against the current post.
        if (($templates || $slugs) && $post) {
            $slugMatch     = $slugs && in_array($post->post_name, $slugs, true);
            $templateMatch = $templates && $this->postMatchesTemplate($post, $templates);

            if ($slugMatch || $templateMatch) {
                $postTypes[] = $post->post_type;
            }
        }

        $postTypes = array_unique($postTypes);

        if (empty($postTypes)) {
            return;
        }

        add_meta_box(
            $this->id,
            $this->buildTitleWithIcon(),
            [$this, 'render'],
            $postTypes,
            $this->context,
            $this->priority
        );
    }

    /**
     * Build the metabox handle title, optionally prepending an icon.
     *
     * Supports:
     *  - Dashicons class  e.g. 'dashicons-admin-settings'
     *  - Raw SVG string   e.g. '<svg xmlns="...">...</svg>'
     *  - Image URL        e.g. 'https://example.com/icon.png'
     */
    private function buildTitleWithIcon(): string
    {
        $title = esc_html($this->title);

        if (empty($this->icon)) {
            return $title;
        }

        if (str_starts_with($this->icon, 'dashicons-')) {
            $icon_html = sprintf(
                '<span class="dashicons %s" style="vertical-align:text-bottom;margin-right:5px;"></span>',
                esc_attr($this->icon)
            );
        } elseif (str_starts_with(ltrim($this->icon), '<svg')) {
            $src       = 'data:image/svg+xml;base64,' . base64_encode($this->icon);
            $icon_html = sprintf(
                '<img src="%s" style="width:16px;height:16px;vertical-align:text-bottom;margin-right:5px;" alt="">',
                esc_attr($src)
            );
        } else {
            $icon_html = sprintf(
                '<img src="%s" style="width:16px;height:16px;vertical-align:text-bottom;margin-right:5px;" alt="">',
                esc_url($this->icon)
            );
        }

        return $icon_html . $title;
    }

    /**
     * MARK: Nav Menu
     */

    /**
     * Render fields for a single nav menu item.
     *
     * Hooked to `wp_nav_menu_item_custom_fields`. Called once per menu item
     * on the Menus admin page. Field names use array notation keyed by item ID
     * (e.g. `_taw_field[123]`) so that when the whole menu form is submitted
     * each item's values remain distinct in `$_POST`.
     *
     * @param int      $item_id The menu item ID.
     * @param \WP_Post $item    The menu item as a WP_Post object.
     * @param int      $depth   Nesting depth of this item.
     * @param object   $args    Menu arguments.
     * @param int      $id      The nav menu ID.
     * @return void
     */
    public function render_for_nav_menu(int|string $_item_id, \WP_Post $item, int $_depth, mixed $_args, int $_id): void
    {
        wp_nonce_field($this->id . '_nonce_action', $this->id . '_nonce');

        foreach ($this->fields as $field) {
            $meta_key  = $this->prefix . $field['id'];
            $name_attr = $meta_key . '[' . $item->ID . ']';
            $html_id   = $meta_key . '-' . $item->ID;
            $value     = get_post_meta($item->ID, $meta_key, true);
?>
            <p class="taw-nav-field description description-thin">
                <label for="<?php echo esc_attr($html_id); ?>">
                    <?php echo esc_html($field['label'] ?? ''); ?>
                    <?php if (!empty($field['required'])): ?>
                        <span class="taw-required">*</span>
                    <?php endif; ?>
                </label>
                <?php $this->render_nav_menu_field($field, $html_id, $name_attr, $value); ?>
                <?php if (!empty($field['description'])): ?>
                    <span class="description"><?php echo esc_html($field['description']); ?></span>
                <?php endif; ?>
            </p>
        <?php
        }
    }

    /**
     * Render a single field for the nav-menu context.
     *
     * Uses a separate, simplified renderer (rather than reusing render_field) because:
     * - The `name` and `id` attributes differ (array-keyed by item ID).
     * - Complex field types (image, wysiwyg, repeater, color) are intentionally
     *   unsupported in the nav-menu panel; their assets are not loaded on nav-menus.php.
     *
     * Supported types: text, url, number, textarea, select, checkbox.
     *
     * @param array<string,mixed> $field     Field definition array.
     * @param string              $html_id   Unique HTML id attribute value.
     * @param string              $name_attr Input name attribute (e.g. `_taw_field[123]`).
     * @param mixed               $value     Current saved value.
     * @return void
     */
    private function render_nav_menu_field(array $field, string $html_id, string $name_attr, mixed $value): void
    {
        $type        = $field['type'] ?? 'text';
        $placeholder = $field['placeholder'] ?? '';

        switch ($type) {
            case 'text':
            case 'url':
                printf(
                    '<input type="%s" id="%s" name="%s" value="%s" placeholder="%s" class="widefat">',
                    esc_attr($type),
                    esc_attr($html_id),
                    esc_attr($name_attr),
                    esc_attr($value),
                    esc_attr($placeholder)
                );
                break;

            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s" value="%s" class="widefat" min="%s" max="%s" step="%s">',
                    esc_attr($html_id),
                    esc_attr($name_attr),
                    esc_attr($value),
                    esc_attr($field['min'] ?? ''),
                    esc_attr($field['max'] ?? ''),
                    esc_attr($field['step'] ?? '1')
                );
                break;

            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" class="widefat" rows="%d" placeholder="%s">%s</textarea>',
                    esc_attr($html_id),
                    esc_attr($name_attr),
                    intval($field['rows'] ?? 3),
                    esc_attr($placeholder),
                    esc_textarea($value)
                );
                break;

            case 'select':
                printf('<select id="%s" name="%s" class="widefat">', esc_attr($html_id), esc_attr($name_attr));
                foreach ($field['options'] ?? [] as $opt_value => $opt_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($opt_value),
                        selected($value, $opt_value, false),
                        esc_html($opt_label)
                    );
                }
                echo '</select>';
                break;

            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s>',
                    esc_attr($html_id),
                    esc_attr($name_attr),
                    checked('1', $value, false)
                );
                break;

            default:
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" placeholder="%s" class="widefat">',
                    esc_attr($html_id),
                    esc_attr($name_attr),
                    esc_attr($value),
                    esc_attr($placeholder)
                );
        }
    }

    /**
     * Save custom fields for a nav menu item.
     *
     * Hooked to `wp_update_nav_menu_item`, which fires once per item when the
     * Menus form is saved. Field values are stored as post meta on the menu
     * item (which is a `nav_menu_item` post internally).
     *
     * Because the same `$_POST` contains data for every item on the page,
     * each field is an array keyed by item ID (e.g. `$_POST['_taw_field'][123]`).
     *
     * @param int   $menu_id          The nav menu ID.
     * @param int   $menu_item_db_id  The menu item's post ID.
     * @param array $args             Sanitized item data passed by WordPress.
     * @return void
     */
    public function save_nav_menu(int $_menu_id, int $menu_item_db_id, array $_args): void
    {
        if (
            !isset($_POST[$this->id . '_nonce']) ||
            !wp_verify_nonce($_POST[$this->id . '_nonce'], $this->id . '_nonce_action')
        ) {
            return;
        }

        if (!current_user_can('edit_theme_options')) {
            return;
        }

        foreach ($this->fields as $field) {
            $meta_key  = $this->prefix . $field['id'];
            $raw_value = isset($_POST[$meta_key][$menu_item_db_id])
                ? wp_unslash($_POST[$meta_key][$menu_item_db_id])
                : null;

            if ($raw_value === null) {
                // Unchecked checkboxes are absent from POST — treat as "0".
                if (($field['type'] ?? '') === 'checkbox') {
                    update_post_meta($menu_item_db_id, $meta_key, '0');
                }
                continue;
            }

            $value = $this->sanitize_field($field, $raw_value);
            update_post_meta($menu_item_db_id, $meta_key, wp_slash($value));
        }
    }

    /**
     * MARK: Assets
     */

    /**
     * Enqueue all admin scripts and styles required by this metabox's fields.
     *
     * Hooked to `admin_enqueue_scripts`. Only runs on post edit screens.
     * Conditionally enqueues Alpine.js, the TAW admin stylesheet, and any
     * field-type-specific assets (image uploader, color picker, post selector,
     * repeater) — including sub-field assets inside repeater definitions.
     *
     * @param string $hook The current admin page hook (e.g. 'post.php').
     * @return void
     */
    public function enqueue_admin_assets(string $hook): void
    {
        $allowed_hooks = $this->type === 'nav_menu'
            ? ['nav-menus.php']
            : ['post.php', 'post-new.php'];

        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

        // Nav-menu context: only enqueue the stylesheet — no Alpine or complex field assets.
        if ($this->type === 'nav_menu') {
            wp_enqueue_style(
                'taw-metaboxes',
                Framework::url('assets/admin.css'),
                [],
                Framework::version()
            );
            return;
        }

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            [],
            '3.0',
            true
        );

        wp_enqueue_style(
            'taw-metaboxes',
            Framework::url('assets/admin.css'),
            [],
            Framework::version()  // Bonus: auto cache-bust with package version
        );

        self::enqueue_field_scripts($this->fields);
    }
    /**
     * Outputs the image-upload JS exactly once, using event delegation so it
     * handles any number of image fields across multiple metabox instances.
     */
    private static function enqueue_image_script(): void
    {
        if (self::$image_script_enqueued) {
            return;
        }

        self::$image_script_enqueued = true;

        add_action('admin_footer', static function () {
        ?>
            <script>
                (function($) {
                    'use strict';

                    // Upload button
                    $(document.body).on('click', '.taw-upload-image', function(e) {
                        e.preventDefault();
                        var $btn = $(this),
                            $wrapper = $btn.closest('.taw-image-field'),
                            $input = $wrapper.find('.taw-image-input'),
                            $preview = $wrapper.find('.taw-image-preview'),
                            $remove = $wrapper.find('.taw-remove-image');

                        var frame = wp.media({
                            title: '<?php echo esc_js(__('Select or Upload Image', 'taw-theme')); ?>',
                            button: {
                                text: '<?php echo esc_js(__('Use this image', 'taw-theme')); ?>'
                            },
                            multiple: false,
                            library: {
                                type: 'image'
                            }
                        });

                        frame.on('select', function() {
                            var attachment = frame.state().get('selection').first().toJSON();
                            var url = attachment.sizes && attachment.sizes.medium ?
                                attachment.sizes.medium.url :
                                attachment.url;
                            $input.val(attachment.id).trigger('change');
                            $preview.html(
                                '<img src="' + url + '" style="max-width:300px;height:auto;display:block;border:1px solid #ddd;padding:4px;border-radius:4px;">'
                            );
                            $remove.show();
                        });

                        frame.open();
                    });

                    // Remove button
                    $(document.body).on('click', '.taw-remove-image', function(e) {
                        e.preventDefault();
                        var $wrapper = $(this).closest('.taw-image-field');
                        $wrapper.find('.taw-image-input').val('').trigger('change');
                        $wrapper.find('.taw-image-preview').html('');
                        $(this).hide();
                    });
                })(jQuery);
            </script>
        <?php
        });
    }

    /**
     * Recursively collects all field 'type' values from a field definitions array,
     * descending into 'fields' sub-arrays at any nesting depth.
     *
     * Used by enqueue_admin_assets() to determine which asset scripts to load
     * without having to hard-code a maximum nesting level.
     *
     * @param array $fields Field definitions (may contain nested 'fields' arrays).
     * @return string[]     Flat list of all type strings found at any depth.
     */
    private static function collect_field_types(array $fields): array
    {
        $types = [];
        foreach ($fields as $field) {
            $types[] = $field['type'] ?? 'text';
            if (!empty($field['fields'])) {
                $types = array_merge($types, self::collect_field_types($field['fields']));
            }
        }
        return $types;
    }

    /**
     * Enqueue all field-type-specific admin scripts for a given field list.
     *
     * Called by both Metabox and OptionsPage so that repeaters, image pickers,
     * colour pickers, etc. are loaded regardless of which context the fields
     * appear in.
     *
     * @param array $fields Top-level field definitions (nested fields are walked recursively).
     * @return void
     */
    public static function enqueue_field_scripts(array $fields): void
    {
        $all_types = self::collect_field_types($fields);

        if (in_array('image', $all_types, true)) {
            wp_enqueue_media();
            self::enqueue_image_script();
        }

        if (in_array('color', $all_types, true)) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            self::enqueue_color_script();
        }

        if (in_array('post_select', $all_types, true)) {
            self::enqueue_post_selector_script();
        }

        if (in_array('files', $all_types, true)) {
            wp_enqueue_media();
            self::enqueue_files_script();
        }

        if (in_array('repeater', $all_types, true)) {
            self::enqueue_repeater_script();
        }

        if (in_array('datepicker', $all_types, true)) {
            wp_enqueue_script('jquery-ui-datepicker');
            self::enqueue_datepicker_script();
        }
    }

    /**
     * Outputs the multi-file picker JS exactly once via `admin_footer`.
     *
     * Exposes `window.tawInitFilesPickers(container)` so repeater rows can
     * initialize file pickers for dynamically-added fields.
     */
    private static function enqueue_files_script(): void
    {
        if (self::$files_script_enqueued) {
            return;
        }

        self::$files_script_enqueued = true;

        add_action('admin_footer', static function () {
        ?>
            <script>
                (function($) {
                    'use strict';

                    /**
                     * Initialize all .taw-files-field elements within a container.
                     * Exposed globally so repeater rows can call it for new rows.
                     */
                    window.tawInitFilesPickers = function(container) {
                        $(container).find('.taw-files-field').each(function() {
                            var $wrap = $(this);
                            if ($wrap.data('taw-files-init')) return;
                            $wrap.data('taw-files-init', true);

                            var $input   = $wrap.find('> .taw-files-input');
                            var $preview = $wrap.find('> .taw-files-preview');
                            var $addBtn  = $wrap.find('> .taw-files-add');
                            var limit     = parseInt($wrap.data('limit'), 10) || 0;
                            var fileTypes = $wrap.data('file-types') || '';

                            function getIds() {
                                try { return JSON.parse($input.val() || '[]'); }
                                catch(e) { return []; }
                            }

                            function saveIds(ids) {
                                $input.val(JSON.stringify(ids)).trigger('change');
                            }

                            function updateButton() {
                                var count = getIds().length;
                                $addBtn.prop('disabled', limit > 0 && count >= limit);
                            }

                            function addItem(attachment) {
                                var ids = getIds();
                                if (ids.indexOf(attachment.id) !== -1) return;
                                if (limit > 0 && ids.length >= limit) return;

                                ids.push(attachment.id);
                                saveIds(ids);

                                var isImage = attachment.type === 'image';
                                var thumbUrl = isImage && attachment.sizes && attachment.sizes.thumbnail
                                    ? attachment.sizes.thumbnail.url
                                    : (isImage ? attachment.url : '');

                                var $item = $('<div class="taw-files-item"></div>').attr('data-id', attachment.id);

                                if (isImage && thumbUrl) {
                                    $item.append('<img src="' + thumbUrl + '" alt="">');
                                } else {
                                    $item.append('<span class="taw-files-icon dashicons dashicons-media-default"></span>');
                                    $item.append('<span class="taw-files-name">' + $('<span>').text(attachment.filename || attachment.title || '').html() + '</span>');
                                }
                                $item.append('<button type="button" class="taw-files-remove" title="<?php echo esc_js(__('Remove', 'taw-theme')); ?>">&times;</button>');
                                $preview.append($item);
                                updateButton();
                            }

                            // Remove a file
                            $preview.on('click', '.taw-files-remove', function() {
                                var $item   = $(this).closest('.taw-files-item');
                                var removed = parseInt($item.data('id'), 10);
                                saveIds(getIds().filter(function(id) { return id !== removed; }));
                                $item.remove();
                                updateButton();
                            });

                            // Drag-to-reorder
                            $preview.sortable({
                                items: '.taw-files-item',
                                placeholder: 'taw-files-item-placeholder',
                                tolerance: 'pointer',
                                update: function() {
                                    var ids = [];
                                    $preview.find('.taw-files-item').each(function() {
                                        ids.push(parseInt($(this).data('id'), 10));
                                    });
                                    saveIds(ids);
                                }
                            });

                            // Open WP media picker
                            $addBtn.on('click', function() {
                                if (limit > 0 && getIds().length >= limit) return;

                                var cfg = {
                                    title:    '<?php echo esc_js(__('Select or Upload Files', 'taw-theme')); ?>',
                                    button:   { text: '<?php echo esc_js(__('Add selected', 'taw-theme')); ?>' },
                                    multiple: true
                                };
                                if (fileTypes) cfg.library = { type: fileTypes };

                                var frame = wp.media(cfg);
                                frame.on('select', function() {
                                    frame.state().get('selection').each(function(a) {
                                        addItem(a.toJSON());
                                    });
                                });
                                frame.open();
                            });

                            updateButton();
                        });
                    };

                    $(document).ready(function() {
                        window.tawInitFilesPickers(document.body);
                    });
                })(jQuery);
            </script>
        <?php
        });
    }

    /**
     * Outputs the WP Color Picker init script exactly once via `admin_footer`.
     *
     * Exposes `window.tawInitColorPickers(container)` globally so repeater
     * rows can initialize color pickers for dynamically-added fields.
     *
     * @return void
     */
    private static function enqueue_color_script(): void
    {
        if (self::$color_script_enqueued) {
            return;
        }

        self::$color_script_enqueued = true;

        add_action('admin_footer', static function () {
        ?>
            <script>
                (function($) {
                    'use strict';

                    // Initialize all color pickers found within a container.
                    // We make this a named function so repeaters can call it later.
                    window.tawInitColorPickers = function(container) {
                        $(container).find('.taw-color-input').each(function() {
                            // Skip already-initialized fields
                            if ($(this).closest('.wp-picker-container').length) {
                                return;
                            }

                            $(this).wpColorPicker({
                                change: function(event, ui) {
                                    // Trigger 'input' so Alpine/other listeners can react
                                    $(event.target).val(ui.color.toString()).trigger('input');
                                },
                                clear: function(event) {
                                    $(event.target).val('').trigger('input');
                                }
                            });
                        });
                    };

                    // Initialize on page load
                    $(document).ready(function() {
                        tawInitColorPickers(document.body);
                    });
                })(jQuery);
            </script>
        <?php
        });
    }

    /**
     * Outputs the jQuery UI Datepicker init script exactly once via `admin_footer`.
     *
     * Exposes `window.tawInitDatepickers(container)` so repeater rows can
     * initialize datepickers for dynamically-added fields.
     */
    private static function enqueue_datepicker_script(): void
    {
        if (self::$datepicker_script_enqueued) {
            return;
        }

        self::$datepicker_script_enqueued = true;

        add_action('admin_footer', static function () {
        ?>
            <style>
                /* Field wrapper */
                .taw-datepicker-wrap { position: relative; display: inline-block; }
                .taw-datepicker-wrap .taw-datepicker-icon {
                    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
                    pointer-events: none; color: #757575;
                }

                /* ---- jQuery UI Datepicker widget ---- */
                .ui-datepicker {
                    z-index: 100500 !important;
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 4px;
                    box-shadow: 0 4px 16px rgba(0,0,0,.15);
                    padding: 10px;
                    width: 240px;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    font-size: 13px;
                    color: #1d2327;
                }
                .ui-datepicker .ui-datepicker-header {
                    background: #f6f7f7;
                    border: 1px solid #dcdcde;
                    border-radius: 3px;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 4px 6px;
                    margin-bottom: 6px;
                }
                .ui-datepicker .ui-datepicker-prev,
                .ui-datepicker .ui-datepicker-next {
                    cursor: pointer;
                    width: 24px; height: 24px;
                    border-radius: 3px;
                    display: flex; align-items: center; justify-content: center;
                    user-select: none;
                    color: #646970;
                    font-size: 16px;
                    line-height: 1;
                }
                .ui-datepicker .ui-datepicker-prev:hover,
                .ui-datepicker .ui-datepicker-next:hover { background: #dcdcde; color: #1d2327; }
                .ui-datepicker .ui-datepicker-prev span,
                .ui-datepicker .ui-datepicker-next span { display: none; }
                .ui-datepicker .ui-datepicker-prev::before { content: "‹"; }
                .ui-datepicker .ui-datepicker-next::before { content: "›"; }
                .ui-datepicker .ui-datepicker-title {
                    flex: 1; text-align: center; font-weight: 600; font-size: 13px;
                    display: flex; align-items: center; justify-content: center; gap: 4px;
                }
                .ui-datepicker .ui-datepicker-title select {
                    font-size: 12px; padding: 2px 4px; border: 1px solid #dcdcde;
                    border-radius: 3px; background: #fff;
                }
                .ui-datepicker table { width: 100%; border-collapse: collapse; }
                .ui-datepicker th {
                    text-align: center; font-size: 11px; font-weight: 600;
                    color: #646970; padding: 4px 2px;
                }
                .ui-datepicker td { padding: 2px; text-align: center; }
                .ui-datepicker td a,
                .ui-datepicker td span {
                    display: block; padding: 4px 2px; border-radius: 3px;
                    text-decoration: none; color: #1d2327; font-size: 12px;
                }
                .ui-datepicker td a:hover { background: #f0f0f1; color: #1d2327; }
                .ui-datepicker td.ui-datepicker-today a { background: #f0f6fc; font-weight: 600; }
                .ui-datepicker td.ui-datepicker-current-day a,
                .ui-datepicker td .ui-state-active {
                    background: #2271b1 !important; color: #fff !important;
                    border-radius: 3px;
                }
                .ui-datepicker td.ui-datepicker-unselectable span { color: #bbb; cursor: default; }
                .ui-datepicker .ui-datepicker-buttonpane {
                    border-top: 1px solid #dcdcde; margin-top: 8px; padding-top: 8px;
                    display: flex; justify-content: space-between;
                }
                .ui-datepicker .ui-datepicker-buttonpane button {
                    background: #fff; border: 1px solid #dcdcde; border-radius: 3px;
                    cursor: pointer; font-size: 12px; padding: 4px 10px; color: #1d2327;
                }
                .ui-datepicker .ui-datepicker-buttonpane button:hover { background: #f6f7f7; }
                .ui-datepicker .ui-datepicker-buttonpane .ui-datepicker-current { color: #2271b1; }
            </style>
            <script>
                (function($) {
                    'use strict';

                    window.tawInitDatepickers = function(container) {
                        $(container).find('.taw-datepicker-input').each(function() {
                            if ($(this).hasClass('hasDatepicker')) {
                                return;
                            }
                            var $input = $(this);
                            $input.datepicker({
                                dateFormat:  $input.data('date-format')  || 'yy-mm-dd',
                                minDate:     $input.data('min-date')     || null,
                                maxDate:     $input.data('max-date')     || null,
                                changeMonth: true,
                                changeYear:  true,
                                onSelect: function(dateText) {
                                    $(this).val(dateText).trigger('change');
                                }
                            });
                        });
                    };

                    $(document).ready(function() {
                        tawInitDatepickers(document.body);
                    });
                })(jQuery);
            </script>
        <?php
        });
    }

    /**
     * Outputs the post selector AJAX + UI script exactly once via `admin_footer`.
     *
     * Localizes `window.tawRest` with the REST API URL and a nonce, then
     * exposes `window.tawInitPostSelectors(container)` so repeater rows can
     * re-initialize selectors for dynamically-added fields.
     *
     * @return void
     */
    private static function enqueue_post_selector_script(): void
    {
        if (self::$post_selector_script_enqueued) {
            return;
        }

        self::$post_selector_script_enqueued = true;

        // Localize REST data so JS knows where to send requests
        add_action('admin_footer', static function () {
            $rest_data = [
                'url'   => esc_url_raw(rest_url('taw/v1/')),
                'nonce' => wp_create_nonce('wp_rest'),
            ];
        ?>
            <script>
                var tawRest = <?php echo wp_json_encode($rest_data); ?>;

                (function($) {
                    'use strict';

                    /**
                     * Debounce helper — delays function execution until the user
                     * stops triggering it for `wait` milliseconds.
                     */
                    function debounce(fn, wait) {
                        var timer;
                        return function() {
                            var context = this,
                                args = arguments;
                            clearTimeout(timer);
                            timer = setTimeout(function() {
                                fn.apply(context, args);
                            }, wait);
                        };
                    }

                    /**
                     * Initialize all post selectors within a container.
                     * Exposed globally so repeaters can call it for new rows.
                     */
                    window.tawInitPostSelectors = function(container) {
                        $(container).find('.taw-post-selector').each(function() {
                            var $wrap = $(this);

                            // Skip already initialized
                            if ($wrap.data('taw-init')) return;
                            $wrap.data('taw-init', true);

                            var $input = $wrap.find('.taw-post-selector-input');
                            var $search = $wrap.find('.taw-post-selector-search');
                            var $results = $wrap.find('.taw-post-selector-results');
                            var $selected = $wrap.find('.taw-post-selector-selected');

                            var multiple = $wrap.data('multiple') === 1;
                            var postTypes = $wrap.data('post-types') || 'post';
                            var max = parseInt($wrap.data('max'), 10) || 0;

                            // Current selection — initialized from pre-fetched data
                            var selection = $wrap.data('selected') || [];

                            // Render the current selection on load
                            renderSelection();

                            // --- Search ---

                            $search.on('input', debounce(function() {
                                var query = $.trim($(this).val());

                                if (query.length < 1) {
                                    $results.empty().hide();
                                    return;
                                }

                                // Build exclude list from current selection
                                var excludeIds = selection.map(function(p) {
                                    return p.id;
                                }).join(',');

                                $.ajax({
                                    url: tawRest.url + 'search-posts',
                                    method: 'GET',
                                    beforeSend: function(xhr) {
                                        xhr.setRequestHeader('X-WP-Nonce', tawRest.nonce);
                                    },
                                    data: {
                                        s: query,
                                        post_type: postTypes,
                                        per_page: 10,
                                        exclude: excludeIds
                                    },
                                    success: function(data) {
                                        renderResults(data);
                                    },
                                    error: function() {
                                        $results.html(
                                            '<div class="taw-ps-no-results"><?php echo esc_js(__('Search failed. Please try again.', 'taw-theme')); ?></div>'
                                        ).show();
                                    }
                                });
                            }, 300));

                            // Close results when clicking outside
                            $(document).on('mousedown', function(e) {
                                if (!$(e.target).closest($wrap).length) {
                                    $results.empty().hide();
                                }
                            });

                            // --- Render results dropdown ---

                            function renderResults(posts) {
                                $results.empty();

                                if (!posts.length) {
                                    $results.html(
                                        '<div class="taw-ps-no-results"><?php echo esc_js(__('No posts found.', 'taw-theme')); ?></div>'
                                    ).show();
                                    return;
                                }

                                posts.forEach(function(post) {
                                    var $item = $(
                                        '<div class="taw-ps-result">' +
                                        (post.thumbnail ?
                                            '<img src="' + post.thumbnail + '" class="taw-ps-thumb" alt="">' :
                                            '<span class="taw-ps-thumb taw-ps-thumb--empty"></span>') +
                                        '<span class="taw-ps-result-info">' +
                                        '<span class="taw-ps-result-title">' + $('<span>').text(post.title || '<?php echo esc_js(__('(no title)', 'taw-theme')); ?>').html() + '</span>' +
                                        '<span class="taw-ps-result-meta">' + post.post_type + ' · ' + post.date + '</span>' +
                                        '</span>' +
                                        '</div>'
                                    );

                                    $item.on('click', function() {
                                        selectPost(post);
                                    });

                                    $results.append($item);
                                });

                                $results.show();
                            }

                            // --- Select a post ---

                            function selectPost(post) {
                                if (!multiple) {
                                    // Single mode: replace
                                    selection = [post];
                                } else {
                                    // Multi mode: add if not at max
                                    if (max > 0 && selection.length >= max) return;
                                    // Prevent duplicates
                                    var exists = selection.some(function(p) {
                                        return p.id === post.id;
                                    });
                                    if (exists) return;
                                    selection.push(post);
                                }

                                updateValue();
                                renderSelection();

                                // Clear and close search
                                $search.val('');
                                $results.empty().hide();

                                // In single mode, hide the search after selection
                                if (!multiple) {
                                    $search.closest('.taw-post-selector-search-wrap').hide();
                                }
                            }

                            // --- Remove a post ---

                            function removePost(postId) {
                                selection = selection.filter(function(p) {
                                    return p.id !== postId;
                                });
                                updateValue();
                                renderSelection();

                                // In single mode, show search again after removal
                                if (!multiple) {
                                    $search.closest('.taw-post-selector-search-wrap').show();
                                }
                            }

                            // --- Render selected posts ---

                            function renderSelection() {
                                $selected.empty();

                                if (!selection.length) {
                                    // Hide search wrap for single mode only if something is selected
                                    if (!multiple) {
                                        $search.closest('.taw-post-selector-search-wrap').show();
                                    }
                                    return;
                                }

                                // In single mode, hide search when we have a selection
                                if (!multiple && selection.length) {
                                    $search.closest('.taw-post-selector-search-wrap').hide();
                                }

                                selection.forEach(function(post) {
                                    var $pill = $(
                                        '<div class="taw-ps-pill" data-id="' + post.id + '">' +
                                        (post.thumbnail ?
                                            '<img src="' + post.thumbnail + '" class="taw-ps-pill-thumb" alt="">' :
                                            '') +
                                        '<span class="taw-ps-pill-title">' + $('<span>').text(post.title || '<?php echo esc_js(__('(no title)', 'taw-theme')); ?>').html() + '</span>' +
                                        '<span class="taw-ps-pill-meta">' + post.post_type + '</span>' +
                                        '<button type="button" class="taw-ps-pill-remove" title="<?php echo esc_js(__('Remove', 'taw-theme')); ?>">&times;</button>' +
                                        '</div>'
                                    );

                                    $pill.find('.taw-ps-pill-remove').on('click', function() {
                                        removePost(post.id);
                                    });

                                    $selected.append($pill);
                                });

                                // Show count for multi mode with max
                                if (multiple && max > 0) {
                                    $selected.append(
                                        '<div class="taw-ps-count">' + selection.length + ' / ' + max + ' <?php echo esc_js(__('selected', 'taw-theme')); ?></div>'
                                    );
                                }
                            }

                            // --- Sync hidden input ---

                            function updateValue() {
                                if (!multiple) {
                                    $input.val(selection.length ? selection[0].id : '');
                                } else {
                                    var ids = selection.map(function(p) {
                                        return p.id;
                                    });
                                    $input.val(JSON.stringify(ids));
                                }
                            }
                        });
                    };

                    // Initialize on page load
                    $(document).ready(function() {
                        tawInitPostSelectors(document.body);
                    });

                })(jQuery);
            </script>
        <?php
        });
    }

    /**
     * Outputs the repeater UI script exactly once via `admin_footer`.
     *
     * Enqueues jQuery UI Sortable for drag-and-drop row reordering, then
     * injects the repeater JS which handles add/remove rows, collapse/expand,
     * drag-and-drop, and JSON serialization into the hidden input.
     *
     * @return void
     */
    private static function enqueue_repeater_script(): void
    {
        if (self::$repeater_script_enqueued) {
            return;
        }

        self::$repeater_script_enqueued = true;

        // We need jQuery UI Sortable for drag-and-drop reordering
        wp_enqueue_script('jquery-ui-sortable');

        add_action('admin_footer', static function () {
        ?>
            <script>
                (function($) {
                    'use strict';

                    function debounce(fn, wait) {
                        var timer;
                        return function() {
                            var context = this,
                                args = arguments;
                            clearTimeout(timer);
                            timer = setTimeout(function() {
                                fn.apply(context, args);
                            }, wait);
                        };
                    }

                    // initRepeater is called for every .taw-repeater on page load,
                    // and again for any nested repeaters that appear inside newly added rows.
                    function initRepeater($repeater) {
                        if ($repeater.data('taw-repeater-init')) return;
                        $repeater.data('taw-repeater-init', true);

                        var $input  = $repeater.children('.taw-repeater-input');
                        var $rows   = $repeater.children('.taw-repeater-rows');
                        var $addBtn = $repeater.children('.taw-repeater-add');
                        // Read the <template> content via .content DocumentFragment clone.
                        // tmplEl.innerHTML is unreliable for <template> elements — in some
                        // browser/jQuery combinations the element reports no DOM children
                        // (content lives in .content, a DocumentFragment) and returns ''.
                        // Cloning .content into a throwaway div gives a correct serialization.
                        var tmplEl = $repeater.children('.taw-repeater-template')[0];
                        var template = '';
                        if (tmplEl) {
                            var _tmpDiv = document.createElement('div');
                            _tmpDiv.appendChild(tmplEl.content.cloneNode(true));
                            template = _tmpDiv.innerHTML;
                        }
                        var max = parseInt($repeater.data('max'), 10) || 0;
                        var min = parseInt($repeater.data('min'), 10) || 0;

                        // Each repeater has its own placeholder (e.g. __TAWRPT__taw_outer__)
                        // so nested repeater templates are never corrupted by the outer replace.
                        var placeholder = $repeater.data('placeholder');
                        var placeholderRe = new RegExp(placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');

                        // Build a regex scoped to this repeater's field ID so serialize()
                        // never picks up inputs that belong to a nested repeater's rows.
                        // Matches: taw_repeater[this_field_id][any_index][sub_field_id]
                        var fieldId = $repeater.data('field-id');
                        var escapedFieldId = fieldId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        var nameRe = new RegExp('^taw_repeater\\[' + escapedFieldId + '\\]\\[[^\\]]+\\]\\[([^\\]]+)\\]$');

                        // Counter for unique indexes — timestamp avoids collisions
                        var nextIndex = Date.now();

                        // --- Tabbed layout support ---
                        var layout = $repeater.data('layout') || '';
                        var isTabbed = layout === 'tabbed_horizontal' || layout === 'tabbed_vertical';
                        var activeTabIndex = 0;
                        var $tabNav = null;

                        function rebuildTabs() {
                            $tabNav.children('.taw-repeater-tab').remove();
                            $rows.children('.taw-repeater-row').each(function(i) {
                                var $row = $(this);
                                var $tab = $('<button type="button" class="taw-repeater-tab">');
                                $tab.append('<span class="taw-repeater-tab-label">#' + (i + 1) + '</span>');
                                var $x = $('<span class="taw-repeater-tab-remove" title="Remove">&times;</span>');
                                $x.on('click', function(e) {
                                    e.stopPropagation();
                                    var count = $rows.children('.taw-repeater-row').length;
                                    if (min > 0 && count <= min) return;
                                    $row.remove();
                                    var newCount = $rows.children('.taw-repeater-row').length;
                                    var newActive = Math.min(activeTabIndex, Math.max(0, newCount - 1));
                                    rebuildTabs();
                                    if (newCount > 0) setActiveTab(newActive);
                                    updateButtonState();
                                    serialize();
                                });
                                $tab.append($x);
                                $tab.on('click', function() { setActiveTab(i); });
                                $tab.insertBefore($tabNav.children('.taw-repeater-tab-add'));
                            });
                        }

                        function setActiveTab(index) {
                            activeTabIndex = index;
                            $rows.children('.taw-repeater-row').each(function(i) {
                                $(this).children('.taw-repeater-row-content').toggle(i === index);
                            });
                            $tabNav.children('.taw-repeater-tab').each(function(i) {
                                $(this).toggleClass('active', i === index);
                            });
                        }

                        function initTabbedUI() {
                            $tabNav = $('<div class="taw-repeater-tab-nav">');
                            $tabNav.insertBefore($rows);

                            // Move the Add button into the tab nav and compact its label
                            $addBtn.addClass('taw-repeater-tab-add').text('+').appendTo($tabNav);

                            // Hide all row headers; hide all row content (setActiveTab reveals one)
                            $rows.children('.taw-repeater-row').children('.taw-repeater-row-header').hide();
                            $rows.children('.taw-repeater-row').children('.taw-repeater-row-content').hide();

                            rebuildTabs();
                            if ($rows.children('.taw-repeater-row').length > 0) {
                                setActiveTab(0);
                            }
                        }

                        // --- Add Row ---

                        $addBtn.on('click', function() {
                            if (max > 0 && $rows.children('.taw-repeater-row').length >= max) {
                                return;
                            }

                            var newIndex = nextIndex++;
                            var rowHtml = template.replace(placeholderRe, newIndex);
                            var $row = $(rowHtml);

                            $rows.append($row);

                            // Initialize JS-dependent fields, including any nested repeaters
                            initFieldsInRow($row);

                            if (isTabbed) {
                                $row.children('.taw-repeater-row-header').hide();
                                $row.children('.taw-repeater-row-content').hide();
                                rebuildTabs();
                                setActiveTab($rows.children('.taw-repeater-row').length - 1);
                            }

                            updateNumbers();
                            updateButtonState();
                            serialize();
                        });

                        // --- Remove Row (accordion mode only; tabbed uses tab × button) ---

                        $rows.on('click', '.taw-repeater-row-remove', function(e) {
                            if (isTabbed) return;
                            e.preventDefault();
                            var $row = $(this).closest('.taw-repeater-row');
                            var count = $rows.children('.taw-repeater-row').length;

                            if (min > 0 && count <= min) return;

                            $row.slideUp(200, function() {
                                $row.remove();
                                updateNumbers();
                                updateButtonState();
                                serialize();
                            });
                        });

                        // --- Collapse/Expand Row (accordion mode only) ---

                        $rows.on('click', '.taw-repeater-row-toggle', function(e) {
                            if (isTabbed) return;
                            e.preventDefault();
                            var $row = $(this).closest('.taw-repeater-row');
                            var $content = $row.children('.taw-repeater-row-content');
                            $content.slideToggle(150);
                            $(this).text($content.is(':visible') ? '▾' : '▸');
                        });

                        // --- Sortable (accordion mode only) ---

                        if (!isTabbed) {
                            $rows.sortable({
                                handle: '.taw-repeater-row-drag',
                                axis: 'y',
                                placeholder: 'taw-repeater-row-placeholder',
                                tolerance: 'pointer',
                                start: function(e, ui) {
                                    ui.placeholder.height(ui.item.outerHeight());
                                },
                                update: function() {
                                    updateNumbers();
                                    serialize();
                                }
                            });
                        }

                        // --- Initialize JS fields in a row (including nested repeaters) ---

                        function initFieldsInRow($row) {
                            if (typeof window.tawInitColorPickers === 'function') {
                                window.tawInitColorPickers($row[0]);
                            }
                            if (typeof window.tawInitPostSelectors === 'function') {
                                window.tawInitPostSelectors($row[0]);
                            }
                            if (typeof window.tawInitFilesPickers === 'function') {
                                window.tawInitFilesPickers($row[0]);
                            }
                            // Recursively initialize any nested repeaters in the new row
                            $row.find('.taw-repeater').each(function() {
                                initRepeater($(this));
                            });
                        }

                        // --- Update row numbers ---

                        function updateNumbers() {
                            if (isTabbed && $tabNav) {
                                $tabNav.children('.taw-repeater-tab').each(function(i) {
                                    $(this).children('.taw-repeater-tab-label').text('#' + (i + 1));
                                });
                            } else {
                                $rows.children('.taw-repeater-row').each(function(i) {
                                    $(this).children('.taw-repeater-row-header').find('.taw-repeater-row-title').text('#' + (i + 1));
                                });
                            }
                        }

                        // --- Update add button state ---

                        function updateButtonState() {
                            var count = $rows.children('.taw-repeater-row').length;
                            $addBtn.prop('disabled', max > 0 && count >= max);
                        }

                        // --- Serialize all rows into the hidden input ---
                        // Uses nameRe (scoped to this field ID) so inputs from nested
                        // repeater rows are not mistakenly included in this level's data.

                        // Guard flag — prevents re-entrant calls that would otherwise loop
                        // when a nested repeater's $input.trigger('change') bubbles up and
                        // fires this serialize() again before it has finished.
                        var _serializing = false;

                        function serialize() {
                            if (_serializing) return;
                            _serializing = true;

                            var data = [];

                            $rows.children('.taw-repeater-row').each(function() {
                                var $row = $(this);
                                var rowData = {};

                                $row.find('input, select, textarea').each(function() {
                                    var $el = $(this);
                                    var name = $el.attr('name');
                                    if (!name) return;

                                    // Only collect fields belonging to this repeater level
                                    var match = name.match(nameRe);
                                    if (!match) return;

                                    var subKey = match[1];

                                    // Checkboxes: the checkbox value wins over the paired hidden "0"
                                    if ($el.attr('type') === 'checkbox') {
                                        rowData[subKey] = $el.is(':checked') ? '1' : '0';
                                        return;
                                    }

                                    if ($el.attr('type') === 'hidden') {
                                        // Skip hidden fields paired with a checkbox of the same name
                                        if ($row.find('input[type="checkbox"][name="' + name + '"]').length) return;

                                        // If this hidden input belongs to a nested repeater,
                                        // flush that repeater NOW before we read its value.
                                        // This guarantees we capture the user's latest edits
                                        // even if the debounce hasn't fired yet.
                                        // The _serializing guard above prevents the change event
                                        // emitted by the child from re-entering this serialize().
                                        if ($el.hasClass('taw-repeater-input')) {
                                            $el.closest('.taw-repeater').trigger('taw-flush-serialize');
                                        }
                                    }

                                    rowData[subKey] = $el.val();
                                });

                                if (Object.keys(rowData).length) {
                                    data.push(rowData);
                                }
                            });

                            $input.val(JSON.stringify(data));
                            // Notify ancestor repeaters so they can re-serialize
                            // their own hidden inputs with the updated nested data.
                            _serializing = false;
                            $input.trigger('change');
                        }

                        // --- Listen for changes ---

                        var debouncedSerialize = debounce(serialize, 200);

                        $rows.on('change input', 'input, select, textarea', function(e) {
                            // A hidden input changing means a nested repeater just serialized.
                            // Skip the debounce so the parent captures the latest nested data
                            // immediately — otherwise a 200ms gap lets the user save stale data.
                            if ($(e.target).attr('type') === 'hidden') {
                                serialize();
                            } else {
                                debouncedSerialize();
                            }
                        });

                        // Expose serialize via a custom event so the pre-submit flush
                        // (registered below) can invoke it without breaking the closure.
                        $repeater.on('taw-flush-serialize', serialize);

                        updateButtonState();

                        if (isTabbed) {
                            initTabbedUI();
                        }
                    }

                    $(document).ready(function() {
                        // Initialize all top-level repeaters; nested ones are initialized
                        // recursively by initFieldsInRow when a new outer row is added.
                        $('.taw-repeater').each(function() {
                            initRepeater($(this));
                        });

                        // Pre-submit flush: serialize all repeaters synchronously before
                        // the form is sent so that debounce lag never causes stale data.
                        // Repeaters are triggered in reverse DOM order (innermost first)
                        // so each child updates its hidden input before its parent reads it.
                        $('form#post, form#post-new').one('submit.tawRepeater', function() {
                            var $all = $(this).find('.taw-repeater').get().reverse();
                            $.each($all, function(_, el) {
                                $(el).trigger('taw-flush-serialize');
                            });
                        });
                    });
                })(jQuery);
            </script>
        <?php
        });
    }

    /**
     * Enqueue shared metabox assets (CSS/JS) exactly once.
     *
     * Reserved for global admin styles or scripts that should only be
     * loaded a single time regardless of how many Metabox instances exist.
     *
     * @return void
     */
    public function enqueue_assets(): void
    {
        if (self::$assets_enqueued) {
            return;
        }

        self::$assets_enqueued = true;

        // Enqueue additional CSS/JS on the admin for custom styling and functionality for the metaboxes
        // add_action('admin_enqueue_scripts', function () {
        //     wp_enqueue_style('taw-metaboxes', get_template_directory_uri() . '/inc/metaboxes/metaboxes.css', [], '1.0');
        // });
    }

    /*
     * MARK: Render 
     */

    /**
     * Render the metabox HTML inside the WordPress editor.
     *
     * Outputs a nonce field for security, initialises an Alpine.js reactive
     * data object pre-populated with saved meta values, then loops over all
     * registered fields — applying conditional visibility and optional widths.
     *
     * @param \WP_Post $post The post currently being edited.
     * @return void
     */
    public function render(\WP_Post $post): void
    {
        if (is_callable($this->show_on) && !call_user_func($this->show_on, $post)) {
            return;
        }

        wp_nonce_field($this->id . '_nonce_action', $this->id . '_nonce');

        // Build initial field values for Alpine.js reactive state
        $initial_values = [];
        foreach ($this->fields as $field) {
            $field_id = $this->prefix . $field['id'];
            $initial_values[$field_id] = get_post_meta($post->ID, $field_id, true) ?: '';
        }
        ?>

        <div class="fields-container"
            x-data="{ fields: <?php echo esc_attr(wp_json_encode($initial_values, JSON_UNESCAPED_UNICODE)); ?> }"
            x-init="
            // Watch all inputs within this metabox and sync to reactive state
            $el.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.name && fields.hasOwnProperty(el.name)) {
                    el.addEventListener('input', () => { fields[el.name] = el.value; });
                    el.addEventListener('change', () => { fields[el.name] = el.value; });
                }
            });
         ">

            <?php foreach ($this->fields as $field):
                $field_id = $this->prefix . $field['id'];
                $value    = get_post_meta($post->ID, $field_id, true);
                $has_conditions = !empty($field['conditions']);
            ?>

                <div class="field"
                    style="--span: <?php echo esc_attr($field['width'] ?? '100'); ?>;"
                    <?php if ($has_conditions): ?>
                    x-show="<?php echo esc_attr($this->build_conditions_expression($field['conditions'])); ?>"
                    x-cloak
                    <?php endif; ?>>

                    <div class="field-and-label">
                        <label for="<?php echo esc_attr($field_id); ?>" class="field-label">
                            <?php echo esc_html($field['label'] ?? ''); ?>
                            <?php if (!empty($field['required'])): ?>
                                <span class="taw-required">*</span>
                            <?php endif; ?>
                        </label>
                        <?php $this->render_field($field, $field_id, $value, $post->ID); ?>
                    </div>

                    <?php if (!empty($field['description'])): ?>
                        <p class="description"><?php echo esc_html($field['description']); ?></p>
                    <?php endif; ?>
                </div>

            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * MARK: Renderers
     */

    /**
     * Render a single field's HTML by its type.
     *
     * Delegates to the appropriate inline renderer (or sub-method) based on
     * `$field['type']`. Supported types: text, url, number, textarea, wysiwyg,
     * select, checkbox, color, range, image, group, post_select, repeater.
     *
     * @param array<string, mixed> $field    Field definition array.
     * @param string               $field_id Full meta key including prefix (e.g. '_taw_heading').
     * @param mixed                $value    Current saved value for this field.
     * @param int|null             $post_id  ID of the post being edited, or null inside templates.
     * @return void
     */
    private function render_field(array $field, string $field_id, mixed $value, ?int $post_id = null): void
    {
        $type        = $field['type'] ?? 'text';
        $placeholder = $field['placeholder'] ?? '';
        $tabs  = $field['tabs'] ?? null;

        switch ($type) {

            /* ---- MARK: Text ---- */
            case 'text':
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text">',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder)
                );
                break;

            /* ---- MARK: URL ---- */
            case 'url':
                printf(
                    '<input type="url" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text">',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_url($value),
                    esc_attr($placeholder)
                );
                break;

            /* ---- MARK: Number ---- */
            case 'number':
                printf(
                    '<input type="number" id="%s" name="%s" value="%s" placeholder="%s" class="small-text" min="%s" max="%s" step="%s">',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder),
                    esc_attr($field['min'] ?? ''),
                    esc_attr($field['max'] ?? ''),
                    esc_attr($field['step'] ?? '1')
                );
                break;

            /* ---- MARK: Textarea ---- */
            case 'textarea':
                // Allow for custom snippets (e.g., code, shortcodes) by not escaping the value. The placeholder can still be escaped.
                printf(
                    '<textarea id="%s" name="%s" rows="%d" class="large-text" placeholder="%s">%s</textarea>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    intval($field['rows'] ?? 4),
                    esc_attr($placeholder),
                    esc_textarea($value)
                );
                break;

            /* ---- MARK: WYSIWYG ---- */
            case 'wysiwyg':
                wp_editor($value ?: '', $field_id, [
                    'textarea_name' => $field_id,
                    'textarea_rows' => intval($field['rows'] ?? 8),
                    'media_buttons' => $field['media_buttons'] ?? true,
                    'teeny'         => $field['teeny'] ?? false,
                ]);
                break;

            /* ---- MARK: Select ---- */
            case 'select':
                $options = $field['options'] ?? [];
                printf('<select id="%s" name="%s">', esc_attr($field_id), esc_attr($field_id));
                foreach ($options as $opt_value => $opt_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($opt_value),
                        selected($value, $opt_value, false),
                        esc_html($opt_label)
                    );
                }
                echo '</select>';
                break;

            /* ---- MARK: Checkbox ---- */
            case 'checkbox':
                printf(
                    '<input type="hidden" name="%s" value="0">',
                    esc_attr($field_id)
                );
                printf(
                    '<label class="taw-toggle"><input type="checkbox" id="%s" name="%s" value="1" %s><span class="taw-toggle-slider"></span></label>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    checked($value, '1', false) // Returns 'checked="checked"' or ''
                );
                break;

            /* ---- MARK: Color Picker ---- */
            case 'color':
                $default_color = $field['default'] ?? '';
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" class="taw-color-input" data-default-color="%s">',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($default_color)
                );
                break;

            /* ---- MARK: Datepicker ---- */
            case 'datepicker':
                printf(
                    '<div class="taw-datepicker-wrap"><input type="text" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text taw-datepicker-input" data-date-format="%s" data-min-date="%s" data-max-date="%s" autocomplete="off"><span class="taw-datepicker-icon dashicons dashicons-calendar-alt"></span></div>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder ?: ($field['date_format'] ?? 'YYYY-MM-DD')),
                    esc_attr($field['date_format'] ?? 'yy-mm-dd'),
                    esc_attr($field['min_date'] ?? ''),
                    esc_attr($field['max_date'] ?? '')
                );
                break;

            /* ---- MARK: Range ---- */
            case 'range':
                $min  = $field['min']  ?? 0;
                $max  = $field['max']  ?? 100;
                $step = $field['step'] ?? 1;
                $unit = $field['unit'] ?? '';  // e.g. 'px', '%', 'ms'
                $current = $value !== '' ? $value : ($field['default'] ?? $min);
        ?>
                <div
                    class="taw-range-field"
                    x-data="{ val: <?php echo floatval($current); ?> }">
                    <div class="taw-range-controls">
                        <input
                            type="range"
                            id="<?php echo esc_attr($field_id); ?>"
                            name="<?php echo esc_attr($field_id); ?>"
                            min="<?php echo esc_attr($min); ?>"
                            max="<?php echo esc_attr($max); ?>"
                            step="<?php echo esc_attr($step); ?>"
                            x-model="val"
                            value="<?php echo esc_attr($current); ?>">
                        <span class="taw-range-value">
                            <span x-text="val"></span><?php echo esc_html($unit); ?>
                        </span>
                    </div>
                    <span class="taw-range-limits">
                        <?php echo esc_html($min . $unit); ?> — <?php echo esc_html($max . $unit); ?>
                    </span>
                </div>
            <?php
                break;

            /* ---- MARK: Image ---- */
            case 'image':
                $image_url = $value ? wp_get_attachment_url(absint($value)) : '';
            ?>
                <div class="taw-image-field">
                    <input type="hidden"
                        class="taw-image-input"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($field_id); ?>"
                        value="<?php echo esc_attr($value); ?>">

                    <div class="taw-image-preview" style="margin-bottom: 10px;">
                        <?php if ($image_url): ?>
                            <img src="<?php echo esc_url($image_url); ?>"
                                style="max-width:300px;height:auto;display:block;border:1px solid #ddd;padding:4px;border-radius:4px;">
                        <?php endif; ?>
                    </div>

                    <button type="button" class="button taw-upload-image">
                        <?php esc_html_e('Select Image', 'taw-theme'); ?>
                    </button>
                    <button type="button" class="button taw-remove-image"
                        style="<?php echo $value ? '' : 'display:none;'; ?>">
                        <?php esc_html_e('Remove Image', 'taw-theme'); ?>
                    </button>
                </div>
            <?php break;

            /* ---- MARK: Files (multi-attachment gallery) ---- */
            case 'files':
                $ids = $value ? json_decode($value, true) : [];
                if (!is_array($ids)) $ids = [];
                $limit      = $field['limit'] ?? 0;      // 0 = unlimited
                $file_types = $field['file_types'] ?? ''; // e.g. 'image' or '' for all

                // Pre-load attachment data for existing selections
                $attachments = [];
                foreach ($ids as $attach_id) {
                    $attach_id = absint($attach_id);
                    if (!$attach_id) continue;
                    $mime     = get_post_mime_type($attach_id);
                    $is_image = $mime && str_starts_with((string) $mime, 'image/');
                    $attachments[] = [
                        'id'       => $attach_id,
                        'url'      => $is_image
                            ? wp_get_attachment_image_url($attach_id, 'thumbnail')
                            : wp_get_attachment_url($attach_id),
                        'name'     => get_the_title($attach_id)
                            ?: basename((string) (wp_get_attachment_url($attach_id) ?: '')),
                        'is_image' => $is_image,
                    ];
                }
            ?>
                <div class="taw-files-field"
                    data-limit="<?php echo intval($limit); ?>"
                    data-file-types="<?php echo esc_attr($file_types); ?>">

                    <input type="hidden"
                        class="taw-files-input"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($field_id); ?>"
                        value="<?php echo esc_attr($value ?: '[]'); ?>">

                    <div class="taw-files-preview">
                        <?php foreach ($attachments as $att): ?>
                            <div class="taw-files-item" data-id="<?php echo esc_attr((string) $att['id']); ?>">
                                <?php if ($att['is_image'] && $att['url']): ?>
                                    <img src="<?php echo esc_url($att['url']); ?>" alt="">
                                <?php else: ?>
                                    <span class="taw-files-icon dashicons dashicons-media-default"></span>
                                    <span class="taw-files-name"><?php echo esc_html($att['name']); ?></span>
                                <?php endif; ?>
                                <button type="button" class="taw-files-remove" title="<?php esc_attr_e('Remove', 'taw-theme'); ?>">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="button taw-files-add">
                        <?php echo esc_html($field['button_label'] ?? __('Add Files', 'taw-theme')); ?>
                    </button>
                </div>
            <?php break;

            /* ---- MARK: Group ---- */
            case 'group':
                $group_fields = $field['fields'] ?? [];
                $this->render_group($group_fields, $field_id, $post_id);
                break;

            /* ---- MARK: Post Selector ---- */
            case 'post_select':
                $multiple   = !empty($field['multiple']);
                $post_types = $field['post_type'] ?? 'post';  // string or comma-separated
                $max        = $field['max'] ?? 0;              // 0 = unlimited (multi only)

                // Decode currently saved value(s)
                if ($multiple) {
                    $selected_ids = $value ? json_decode($value, true) : [];
                    if (!is_array($selected_ids)) $selected_ids = [];
                } else {
                    $selected_ids = $value ? [absint($value)] : [];
                }

                // Pre-fetch selected posts so we can render them immediately
                $selected_posts = [];
                foreach ($selected_ids as $pid) {
                    $p = get_post($pid);
                    if ($p && $p->post_status === 'publish') {
                        $thumb_id  = get_post_thumbnail_id($p->ID);
                        $selected_posts[] = [
                            'id'        => $p->ID,
                            'title'     => get_the_title($p),
                            'post_type' => $p->post_type,
                            'thumbnail' => $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : '',
                            'edit_url'  => get_edit_post_link($p->ID, 'raw'),
                        ];
                    }
                }
            ?>
                <div class="taw-post-selector"
                    data-field-id="<?php echo esc_attr($field_id); ?>"
                    data-multiple="<?php echo $multiple ? '1' : '0'; ?>"
                    data-post-types="<?php echo esc_attr($post_types); ?>"
                    data-max="<?php echo intval($max); ?>"
                    data-selected="<?php echo esc_attr(wp_json_encode($selected_posts)); ?>">

                    <?php // Hidden input holds the actual value that gets submitted 
                    ?>
                    <input type="hidden"
                        class="taw-post-selector-input"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($field_id); ?>"
                        value="<?php echo esc_attr($value); ?>">

                    <?php // Selected posts display 
                    ?>
                    <div class="taw-post-selector-selected"></div>

                    <?php // Search interface 
                    ?>
                    <div class="taw-post-selector-search-wrap">
                        <input type="text"
                            class="taw-post-selector-search regular-text"
                            placeholder="<?php echo esc_attr($multiple ? __('Search to add posts…', 'taw-theme') : __('Search for a post…', 'taw-theme')); ?>"
                            autocomplete="off">
                        <div class="taw-post-selector-results"></div>
                    </div>
                </div>
            <?php
                break;

            /* ---- MARK: Repeater ---- */
            case 'repeater':
                $sub_fields  = $field['fields'] ?? [];
                $max_rows    = $field['max'] ?? 0;      // 0 = unlimited
                $min_rows    = $field['min'] ?? 0;
                $button_label = $field['button_label'] ?? __('Add Row', 'taw-theme');
                $layout      = $field['layout'] ?? '';  // '', 'tabbed_horizontal', 'tabbed_vertical'

                // $value may arrive as a PHP array when this is a nested repeater
                // (the parent sanitize_repeater stores nested rows as decoded arrays
                // to avoid double-JSON-encoding, which breaks update_post_meta).
                if (is_array($value)) {
                    $value = wp_json_encode($value) ?: '[]';
                }

                // Decode saved rows
                $rows = $value ? json_decode($value, true) : [];
                if (!is_array($rows)) $rows = [];
                // Each repeater uses its own unique placeholder so nested repeaters
                // don't corrupt each other's templates when JS does the replace.
                $tpl_placeholder = '__TAWRPT_' . $field_id . '__';
            ?>
                <div class="taw-repeater"
                    data-field-id="<?php echo esc_attr($field_id); ?>"
                    data-placeholder="<?php echo esc_attr($tpl_placeholder); ?>"
                    data-max="<?php echo intval($max_rows); ?>"
                    data-min="<?php echo intval($min_rows); ?>"
                    <?php if ($layout): ?>data-layout="<?php echo esc_attr($layout); ?>"<?php endif; ?>>

                    <?php // Hidden input holds the serialized JSON value
                    ?>
                    <input type="hidden"
                        class="taw-repeater-input"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($field_id); ?>"
                        value="<?php echo esc_attr($value ?: '[]'); ?>">

                    <?php // Sortable container for rows
                    ?>
                    <div class="taw-repeater-rows">
                        <?php foreach ($rows as $row_index => $row_data):
                            $this->render_repeater_row($sub_fields, $field_id, $row_index, $row_data, $post_id);
                        endforeach; ?>
                    </div>

                    <?php // "Add Row" button
                    ?>
                    <button type="button" class="button taw-repeater-add">
                        <?php echo esc_html($button_label); ?>
                    </button>

                    <?php // Template row — hidden, cloned by JS when adding new rows.
                    // <template> keeps content inert (no form submissions, no JS init)
                    // and — unlike <script type="text/html"> — supports nesting:
                    // a nested </template> never prematurely closes the outer one.
                    ?>
                    <template class="taw-repeater-template">
                        <?php $this->render_repeater_row($sub_fields, $field_id, $tpl_placeholder, [], $post_id); ?>
                    </template>
                </div>
            <?php
                break;
        }
    }

    /**
     * Render all sub-fields that belong to a group field.
     *
     * Each sub-field's meta key is built as `{$field_id_prefix}_{sub_field_id}`.
     * Values are read directly from post meta when a post ID is available.
     *
     * @param array<int, array<string, mixed>> $group_fields Sub-field definitions.
     * @param string                           $field_id_prefix Prefix including the group field's own key.
     * @param int|null                         $post_id Post ID for reading saved values, or null.
     * @return void
     */
    private function render_group(array $group_fields, string $field_id_prefix, ?int $post_id = null): void
    {
        ?><div class="fields-container"><?php
        foreach ($group_fields as $field) {
            $field_id = $field_id_prefix . '_' . $field['id']; ?>
            <div class="field" style="--span: <?php echo esc_attr($field['width'] ?? '100'); ?>;">
                <div class="field-and-label">
                    <label for="<?php echo esc_attr($field_id); ?>" class="field-label"><?php echo esc_html($field['label'] ?? ''); ?></label>
                    <?php
                    $value = $post_id ? get_post_meta($post_id, $field_id, true) : '';
                    $this->render_field($field, $field_id, $value, $post_id);
                    ?>
                </div>
                <?php if (!empty($field['description'])): ?>
                    <p class="description"><?php echo esc_html($field['description']); ?></p>
                <?php endif; ?>
            </div>
        <?php }
        ?></div><?php
    }

    /**
     * Render an Alpine.js-powered tabbed interface grouping metabox fields.
     *
     * Each tab definition may include an `id`, `label`, `icon`, and a `fields`
     * array of field IDs that should be displayed under that tab. Fields are
     * matched against `$this->fields` and rendered in their declared order.
     *
     * @param array<int, array<string, mixed>> $tabs            Tab definition arrays.
     * @param string                           $field_id_prefix Prefix applied to field meta keys.
     * @param \WP_Post                         $post            The post currently being edited.
     * @return void
     */
    private function render_tabs(array $tabs, string $field_id_prefix, \WP_Post $post): void
    {

        ?>
        <div class="taw-tabbed" x-data="{ activeTab: 0 }">
            <div class="tabs">
                <?php foreach ($tabs as $index => $tab): ?>
                    <?php
                    $tab_id = $field_id_prefix . '_' . $tab['id'];
                    $tab_label = $tab['label'] ?? 'Tab';
                    $tab_fields = $tab['fields'] ?? [];
                    $tab_icon = isset($tab['icon']) ? $tab['icon'] : '';
                    ?>
                    <div class="tab-title" :class="activeTab === <?php echo $index; ?> ? 'active' : ''" @click="activeTab = <?php echo $index; ?>">
                        <?php if ($tab_icon): ?>
                            <img src="<?php echo $tab_icon ?>" alt="Tab Icon">
                        <?php endif; ?>
                        <p><?php echo esc_html($tab_label); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="tab-content--wrapper">
                <?php foreach ($tabs as $index => $tab):
                    $tab_id = $field_id_prefix . '_' . $tab['id'];
                    $tab_label = $tab['label'] ?? 'Tab';
                    $tab_fields = $tab['fields'] ?? []; // Array of field IDs
                ?>
                    <div class="fields-container tab-content-<?php echo $index ?>" x-show="activeTab === <?php echo $index; ?>" x-cloak>
                        <?php $matches = array_filter($this->fields, function ($field) use ($tab_fields) {
                            return in_array($field['id'], $tab_fields);
                        }) ?>
                        <?php foreach ($matches as $field_index => $field): ?>
                            <?php
                            $field_id = $field_id_prefix . $field['id'];
                            $value    = get_post_meta($post->ID, $field_id, true);
                            $label    = $field['label'] ?? '';
                            $desc     = $field['description'] ?? '';
                            $width    = (int) ($field['width'] ?? 100);
                            $border   = '';
                            // If it's the last field and its width is less than 100%, add a right border
                            if ($field_index === array_key_last($matches) && $width < 100) {
                                $border = 'border-right: 0.5px solid rgb(195, 196, 199);';
                            }
                            ?>

                            <div class="tab-field field" style="--span: <?php echo esc_attr((string) $width) ?>; <?php echo esc_attr($border); ?>">

                                <div class="field-and-label">
                                    <label for="<?php echo esc_attr($field_id); ?>" class="tab-field-label"><?php echo esc_html($label); ?></label>
                                    <?php $this->render_field($field, $field_id, $value, $post->ID); ?>
                                </div>

                                <?php if ($desc): ?>
                                    <p class="description"><?php echo esc_html($desc); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php
                        endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php }

    /**
     * Render a single repeater row.
     * Used both for saved rows (with data) and the template row (empty).
     */
    private function render_repeater_row(array $sub_fields, string $field_id, int|string $index, array $row_data, ?int $post_id): void
    {
        // IDs of sub-fields local to this row — used by build_conditions_expression to route
        // sibling references to rowFields[id] vs. outer-scope references to fields[prefixed_id].
        $local_field_ids = array_column($sub_fields, 'id');

        // Seed rowFields with current row values so Alpine initialises in the right state.
        $initial_row_values = [];
        foreach ($sub_fields as $sf) {
            $initial_row_values[$sf['id']] = $row_data[$sf['id']] ?? '';
        }
    ?>
        <div class="taw-repeater-row" data-index="<?php echo esc_attr((string) $index); ?>">
            <div class="taw-repeater-row-header">
                <span class="taw-repeater-row-drag" title="<?php esc_attr_e('Drag to reorder', 'taw-theme'); ?>">☰</span>
                <span class="taw-repeater-row-title">
                    <?php echo esc_html('#' . (is_int($index) ? $index + 1 : '')); ?>
                </span>
                <button type="button" class="taw-repeater-row-toggle" title="<?php esc_attr_e('Collapse', 'taw-theme'); ?>">▾</button>
                <button type="button" class="taw-repeater-row-remove" title="<?php esc_attr_e('Remove row', 'taw-theme'); ?>">&times;</button>
            </div>
            <div class="taw-repeater-row-content">
                <div class="fields-container"
                    x-data="{ rowFields: <?php echo esc_attr(wp_json_encode($initial_row_values, JSON_UNESCAPED_UNICODE)); ?> }"
                    x-init="
                        $el.querySelectorAll('input, select, textarea').forEach(function(el) {
                            var m = el.name && el.name.match(/\[([^\]]+)\]$/);
                            if (m && rowFields.hasOwnProperty(m[1])) {
                                el.addEventListener('input',  function() { rowFields[m[1]] = el.value; });
                                el.addEventListener('change', function() { rowFields[m[1]] = el.value; });
                            }
                        });
                    ">
                    <?php foreach ($sub_fields as $sub_field):
                        $sub_id        = $sub_field['id'];
                        $sub_value     = $row_data[$sub_id] ?? '';
                        $width         = (int) ($sub_field['width'] ?? 100);
                        $has_sub_cond  = !empty($sub_field['conditions']);

                        // Build a unique name for serialization.
                        // Format: taw_repeater[field_id][INDEX][sub_field_id]
                        // JS reads these to build the JSON before submit.
                        $input_name = 'taw_repeater[' . $field_id . '][' . $index . '][' . $sub_id . ']';
                    ?>
                        <div class="field" style="--span: <?php echo esc_attr((string) $width); ?>;"
                            <?php if ($has_sub_cond): ?>
                            x-show="<?php echo esc_attr($this->build_conditions_expression($sub_field['conditions'], $local_field_ids)); ?>"
                            x-cloak
                            <?php endif; ?>>
                            <div class="field-and-label">
                                <label class="field-label">
                                    <?php echo esc_html($sub_field['label'] ?? ''); ?>
                                </label>
                                <?php
                                // We render the sub-field using the SAME render_field()
                                // method, but with a special name for repeater context.
                                // We temporarily override the field_id for rendering.
                                $this->render_field($sub_field, $input_name, $sub_value, $post_id);
                                ?>
                            </div>
                            <?php if (!empty($sub_field['description'])): ?>
                                <p class="description"><?php echo esc_html($sub_field['description']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
<?php
    }

    /**
     * Check if a field's conditions are met based on the submitted form data.
     */
    private function evaluate_conditions(array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $field_name = $this->prefix . ($condition['id'] ?? $condition['field'] ?? '');
            $expected   = $condition['value'];
            $operator   = $condition['operator'] ?? '==';

            // Read from $_POST via helper (applies wp_unslash)
            $actual = $this->getPostValue($field_name);

            $met = match ($operator) {
                '=='       => $actual == $expected,
                '!='       => $actual != $expected,
                'contains' => str_contains($actual, $expected),
                'empty'    => empty($actual),
                '!empty'   => !empty($actual),
                default    => $actual == $expected,
            };

            if (!$met) return false; // AND logic — all conditions must pass
        }

        return true;
    }

    /* 
     * MARK: Save
     * 
     */
    public function save(int $post_id, \WP_Post $post): void
    {
        // Nonce check
        if (
            !isset($_POST[$this->id . '_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST[$this->id . '_nonce'])),
                $this->id . '_nonce_action'
            )
        ) {
            return;
        }

        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Capability check
        $post_type_obj = get_post_type_object($post->post_type);
        if (!current_user_can($post_type_obj->cap->edit_post, $post_id)) {
            return;
        }

        // Must match a registered screen (post type, slug, or template)
        [$postTypes, $templates, $slugs] = $this->parseScreens();

        $postTypeMatch = in_array($post->post_type, $postTypes, true);
        $slugMatch     = $slugs && in_array($post->post_name, $slugs, true);
        $templateMatch = $templates && $this->postMatchesTemplate($post, $templates);

        if (!$postTypeMatch && !$slugMatch && !$templateMatch) {
            return;
        }

        $errors = [];

        foreach ($this->fields as $field) {

            // ── Assign $field_id FIRST ──────────────────────────
            //
            // BUG FIX: Previously, $field_id was assigned AFTER the
            // conditions check, which meant the conditions block used
            // a stale value from the previous iteration (or was
            // undefined on the first).

            $field_id = $this->prefix . $field['id'];

            // ── Conditional fields ──────────────────────────

            if (!empty($field['conditions']) && !$this->evaluate_conditions($field['conditions'])) {
                // Conditions not met — clean up any stale value
                delete_post_meta($post_id, $field_id);
                continue;
            }


            // ── Conditional fields ──────────────────────────

            if (($field['type'] ?? '') === 'group') {
                $group_fields = $field['fields'] ?? [];
                foreach ($group_fields as $group_field) {
                    $group_field_id = $field_id . '_' . $group_field['id'];
                    $raw_value = $_POST[$group_field_id] ?? '';

                    // Validate before saving
                    $validation = $this->validate_field($group_field, $raw_value);
                    if ($validation !== true) {
                        $errors[] = $validation;
                        continue;
                    }

                    if (!isset($_POST[$group_field_id])) {
                        delete_post_meta($post_id, $group_field_id);
                        continue;
                    }
                    // Sanitize the unslashed value — NOT the raw $_POST value
                    $value = $this->sanitize_field($group_field, $this->getPostValue($group_field_id));
                    update_post_meta($post_id, $group_field_id, wp_slash($value));
                }

                continue;
            }

            // ── Handle Regular Fields ───────────────────────────

            // Read from $_POST via helper (applies wp_unslash)
            $raw_value = $this->getPostValue($field_id);

            // Validate 
            $validation = $this->validate_field($field, $raw_value);
            if ($validation !== true) {
                $errors[] = $validation;
                // Still save the raw (sanitized) value so the user's input isn't lost,
                // but show the error. This is a UX decision — some frameworks
                // refuse to save entirely, but losing user input is worse.
                if (isset($_POST[$field_id])) {
                    $value = $this->sanitize_field($field, $this->getPostValue($field_id));
                    update_post_meta($post_id, $field_id, wp_slash($value));
                }
                continue;
            }

            if (!isset($_POST[$field_id])) {
                delete_post_meta($post_id, $field_id);
                continue;
            }

            // Sanitize the unslashed value — NOT the raw $_POST value
            $value = $this->sanitize_field($field, $this->getPostValue($field_id));
            update_post_meta($post_id, $field_id, wp_slash($value));
        }

        // Display validation errors as admin notices
        if (!empty($errors)) {
            // Stores errors transiently - they'll be displayed on the next page load
            set_transient(
                'taw_validation_errors_' . $post_id,
                $errors,
                30 // Expires in 30 seconds
            );
        }
    }

    /**
     * MARK: Helpers
     */

    /**
     * Build an Alpine.js x-show expression from a field's conditions.
     *
     * The trick: Alpine watches the actual form inputs by their name attribute.
     * We use $watch or direct references via x-model/x-ref, but the simplest
     * approach for WordPress metaboxes is using the `$el.closest('form')` to
     * read sibling field values.
     *
     * We attach an x-data listener at the metabox level that tracks all field values.
     */
    /**
     * @param array  $conditions     Field condition definitions.
     * @param array  $local_field_ids Sub-field IDs local to the current repeater row.
     *                                When a condition references one of these, the expression
     *                                reads from `rowFields[id]` (row-level reactive state)
     *                                instead of falling back to the outer `fields[prefixed_id]`.
     */
    private function build_conditions_expression(array $conditions, array $local_field_ids = []): string
    {
        $parts = [];

        foreach ($conditions as $condition) {
            $raw_id   = $condition['id'] ?? $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '==';
            $value    = $condition['value'];

            // Sibling within the same repeater row → rowFields scope; otherwise outer fields scope.
            if ($raw_id !== '' && in_array($raw_id, $local_field_ids, true)) {
                $field_ref = "rowFields['{$raw_id}']";
            } else {
                $field_name = $this->prefix . $raw_id;
                $field_ref  = "fields['{$field_name}']";
            }

            $safe_val  = is_numeric($value) ? $value : "'" . esc_js($value) . "'";

            $parts[] = match ($operator) {
                '=='       => "{$field_ref} == {$safe_val}",
                '!='       => "{$field_ref} != {$safe_val}",
                'contains' => "{$field_ref} && {$field_ref}.includes({$safe_val})",
                'empty'    => "!{$field_ref} || {$field_ref} === ''",
                '!empty'   => "{$field_ref} && {$field_ref} !== ''",
                default    => "{$field_ref} == {$safe_val}",
            };
        }

        return implode(' && ', $parts);
    }

    /**
     * Safely read a value from $_POST with WordPress unslashing.
     *
     * WordPress adds slashes to all $_POST data during boot (legacy
     * from magic_quotes). wp_unslash() reverses that so values like
     * "Marco's Theme" don't get saved as "Marco\'s Theme".
     *
     * This method deliberately does NOT sanitize — that's the caller's
     * job via sanitize_field(), because the correct sanitizer depends
     * on the field type (text vs textarea vs URL vs wysiwyg, etc.)
     *
     * @param string $key The $_POST key to read.
     * @return string The unslashed value, or empty string if not set.
     */
    private function getPostValue(string $key): string
    {
        if (!isset($_POST[$key])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in save() before any getPostValue() calls
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- caller handles type-specific sanitization via sanitize_field()
        return wp_unslash($_POST[$key]);
    }

    /**
     * MARK: Sanitization
     */

    /**
     * Sanitize a raw submitted value according to its field type.
     *
     * Fields with `'sanitize' => 'code'` bypass standard sanitization and
     * preserve raw content for users with the `unfiltered_html` capability
     * (falling back to `wp_kses_post()` for everyone else).
     *
     * @param array<string, mixed> $field Field definition array.
     * @param mixed                $value Raw value from `$_POST`.
     * @return mixed Sanitized value ready to be stored via `update_post_meta()`.
     */
    private function sanitize_field(array $field, mixed $value): mixed
    {
        // Fields with 'sanitize' => 'code' preserve raw content for trusted users.
        if (($field['sanitize'] ?? '') === 'code') {
            return current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
        }

        $type = $field['type'] ?? 'text';

        return match ($type) {
            'text', 'select' => sanitize_text_field($value),
            'textarea'       => sanitize_textarea_field($value),
            'url'            => esc_url_raw($value),
            'number'         => floatval($value),
            'image'          => absint($value),
            'wysiwyg'        => wp_kses_post($value),
            'checkbox'       => in_array($value, ['1', 1, true], true) ? '1' : '0',
            'range'          => floatval($value),
            'color'          => sanitize_hex_color($value) ?: '',
            'datepicker'     => sanitize_text_field($value),
            'post_select'       => $this->sanitize_post_select($field, $value),
            'repeater'          => $this->sanitize_repeater($field, $value),
            'files'             => $this->sanitize_files($value),
            default          => sanitize_text_field($value),
        };
    }

    /**
     * Sanitize post selector value.
     * Single mode: returns a post ID as string.
     * Multi mode: returns a JSON array of post IDs.
     */
    private function sanitize_post_select(array $field, mixed $value): string
    {
        $multiple = !empty($field['multiple']);

        if (!$multiple) {
            // Single mode: just a post ID
            $id = absint($value);
            return $id ? (string) $id : '';
        }

        // Multi mode: JSON array of IDs
        $ids = json_decode($value, true);
        if (!is_array($ids)) {
            return '[]';
        }

        // Sanitize each ID, remove zeros and duplicates
        $clean = array_values(array_unique(array_filter(array_map('absint', $ids))));

        // Enforce max if set
        $max = $field['max'] ?? 0;
        if ($max > 0) {
            $clean = array_slice($clean, 0, $max);
        }

        return wp_json_encode($clean);
    }

    /**
     * Sanitize files field value.
     * Decodes the JSON array of attachment IDs and sanitizes each as absint.
     */
    private function sanitize_files(mixed $value): string
    {
        $ids = json_decode((string) $value, true);
        if (!is_array($ids)) return '[]';

        $clean = array_values(array_filter(array_map('absint', $ids)));
        return wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sanitize repeater value.
     * Decodes JSON, sanitizes each sub-field in each row,
     * then re-encodes as clean JSON.
     */
    private function sanitize_repeater(array $field, mixed $value): string
    {
        $rows = json_decode($value, true);
        if (!is_array($rows)) {
            return '[]';
        }

        $sub_fields = $field['fields'] ?? [];
        $max = $field['max'] ?? 0;

        // Enforce max rows
        if ($max > 0) {
            $rows = array_slice($rows, 0, $max);
        }

        // Build a lookup of sub-field definitions by ID
        $field_map = [];
        foreach ($sub_fields as $sf) {
            $field_map[$sf['id']] = $sf;
        }

        $clean_rows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $clean_row = [];
            foreach ($row as $key => $val) {
                // Only allow known sub-field keys
                if (!isset($field_map[$key])) continue;

                $cleaned = $this->sanitize_field($field_map[$key], $val);

                // Nested repeaters: store as a decoded PHP array rather than a JSON
                // string so that the parent wp_json_encode() produces clean nested JSON
                // (e.g. {"child":[{...}]}) with no backslash-escaped inner quotes.
                // update_post_meta() internally calls wp_unslash(), which would strip
                // those escapes and corrupt the outer JSON if we stored it as a string.
                if (($field_map[$key]['type'] ?? '') === 'repeater') {
                    $clean_row[$key] = json_decode($cleaned, true) ?: [];
                } else {
                    $clean_row[$key] = $cleaned;
                }
            }

            // Only keep rows that have at least one non-empty value.
            // Nested repeaters are stored as PHP arrays, so also filter out [].
            $has_content = array_filter($clean_row, fn($v) => $v !== '' && $v !== '0' && $v !== '[]' && $v !== []);
            if (!empty($has_content)) {
                $clean_rows[] = $clean_row;
            }
        }

        return wp_json_encode($clean_rows, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sanitize a value based on a field config from the registry.
     * 
     * Used by the visual editor REST endpoint to sanitize incoming
     * values using the same rules as the metabox save handler.
     *
     * @param array $fieldConfig The field config array from getFieldConfig().
     * @param mixed $value       The raw value to sanitize.
     * @return mixed The sanitized value.
     */
    public static function sanitizeValue(array $fieldConfig, mixed $value): mixed
    {
        // Fields with 'sanitize' => 'code' preserve raw content for trusted users
        if (($fieldConfig['sanitize'] ?? '') === 'code') {
            return current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
        }

        $type = $fieldConfig['type'] ?? 'text';

        return match ($type) {
            'text', 'select' => sanitize_text_field($value),
            'textarea'       => sanitize_textarea_field($value),
            'url'            => esc_url_raw($value),
            'number'         => floatval($value),
            'image'          => absint($value),
            'wysiwyg'        => wp_kses_post($value),
            'checkbox'       => in_array($value, ['1', 1, true], true) ? '1' : '0',
            'range'          => floatval($value),
            'color'          => sanitize_hex_color($value) ?: '',
            'datepicker'     => sanitize_text_field($value),
            default          => sanitize_text_field($value),
        };
    }

    /**
     * MARK: Validation
     * 
     * Validate a single field value.
     *
     * @return true|string True if valid, error message string if invalid.
     */
    private function validate_field(array $field, mixed $value): true|string
    {
        $label = $field['label'] ?? $field['id'];

        // Required check
        if (!empty($field['required']) && ($value === '' || $value === null)) {
            /* translators: %s: field label */
            return sprintf(__('%s is required.', 'taw-theme'), $label);
        }

        // Custom validation callback
        if (isset($field['validate']) && is_callable($field['validate'])) {
            $result = call_user_func($field['validate'], $value);
            if ($result !== true) {
                /* translators: %s: field label */
                return is_string($result) ? $result : sprintf(__('%s is invalid.', 'taw-theme'), $label);
            }
        }

        return true;
    }

    /* -------------------------------------------------------------------------
     * Template Helpers (static)
     * ---------------------------------------------------------------------- */

    /**
     * Retrieve a single meta value.
     *
     * @param int    $post_id  The post/page ID.
     * @param string $field_id Field ID (without prefix).
     * @param string $prefix   Meta key prefix. Default '_taw_'.
     * @return mixed The raw meta value, or empty string if not set.
     */
    public static function get(int $post_id, string $field_id, string $prefix = '_taw_'): mixed
    {
        return get_post_meta($post_id, $prefix . $field_id, true);
    }

    /**
     * Retrieve a checkbox/toggle meta value as a boolean.
     *
     * Usage:
     * ```php
     * if (Metabox::get_bool($post->ID, 'hero_show_cta')) {
     *     // render the CTA
     * }
     * ```
     *
     * @param int    $post_id  The post/page ID.
     * @param string $field_id Field ID (without prefix).
     * @param string $prefix   Meta key prefix. Default '_taw_'.
     * @return bool True if the stored value is '1', false otherwise.
     */
    public static function get_bool(int $post_id, string $field_id, string $prefix = '_taw_'): bool
    {
        return (string) get_post_meta($post_id, $prefix . $field_id, true) === '1';
    }


    /**
     * Retrieve an image URL from a saved attachment ID meta value.
     *
     * @param int    $post_id  The post/page ID.
     * @param string $field_id Field ID (without prefix).
     * @param string $size     WordPress image size. Default 'full'.
     * @param string $prefix   Meta key prefix. Default '_taw_'.
     * @return string Image URL, or empty string if none is set.
     */
    public static function get_image_url(int $post_id, string $field_id, string $size = 'full', string $prefix = '_taw_'): string
    {
        $attachment_id = absint(self::get($post_id, $field_id, $prefix));
        if (!$attachment_id) {
            return '';
        }

        $src = wp_get_attachment_image_url($attachment_id, $size);

        return $src ?: '';
    }

    /**
     * Retrieve a color meta value with an optional fallback.
     * 
     * Usage:
     * $bg = Metabox::get_color($post->ID, 'hero_bg_color', '#ffffff');
     * echo '<section style="background-color: ' . esc_attr($bg) . ';">';
     *
     * @param int    $post_id  The post/page ID.
     * @param string $field_id Field ID (without prefix).
     * @param string $fallback Fallback color if none is set. Default ''.
     * @param string $prefix   Meta key prefix. Default '_taw_'.
     */
    public static function get_color(int $post_id, string $field_id, string $fallback = '', string $prefix = '_taw_'): string
    {
        $color = (string) get_post_meta($post_id, $prefix . $field_id, true);
        return $color !== '' ? $color : $fallback;
    }

    /**
     * Retrieve post selector value as an array of post IDs.
     * Works for both single and multi mode.
     * 
     * Usage:
     * // Single mode — get the one post
     * $featured_id = Metabox::get_posts($post->ID, 'featured_post')[0] ?? null;
     * 
     * // Multi mode — loop through
     * $related_ids = Metabox::get_posts($post->ID, 'related_posts');
     * foreach ($related_ids as $related_id) {
     *  // render each related post
     * }
     *
     * @param int    $post_id  The post/page ID.
     * @param string $field_id Field ID (without prefix).
     * @param string $prefix   Meta key prefix. Default '_taw_'.
     * @return int[] Array of post IDs.
     */
    public static function get_posts(int $post_id, string $field_id, string $prefix = '_taw_'): array
    {
        $raw = get_post_meta($post_id, $prefix . $field_id, true);

        if (empty($raw)) {
            return [];
        }

        // Try JSON first (multi mode)
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_filter(array_map('absint', $decoded));
        }

        // Fall back to single ID
        $id = absint($raw);
        return $id ? [$id] : [];
    }

    /**
     * Retrieve repeater data as an array of associative arrays.
     * 
     * Usage:
     * $team = Metabox::get_repeater($post->ID, 'team_members');
     * foreach($team as $member) {
     *  echo '<div class="team-card">';
     *  echo '<h3>' . esc_html($member['name'] ?? '') . '</h3>';
     *  echo '<p>' . esc_html($member['role'] ?? '') . '</p>';
     *  echo '</div>';
     * }
     *
     * @param int    $post_id  The post/page ID.
     * @param string $field_id Field ID (without prefix).
     * @param string $prefix   Meta key prefix. Default '_taw_'.
     * @return array[] Array of rows, each row is an associative array.
     */
    public static function get_repeater(int $post_id, string $field_id, string $prefix = '_taw_'): array
    {
        $raw = get_post_meta($post_id, $prefix . $field_id, true);
        if (empty($raw)) return [];

        $rows = json_decode($raw, true);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Retrieve the config for a registered field.
     * Returns null if the field isn't registered or isn't editor-enabled
     */
    public static function get_field_config(string $fieldId): ?array
    {
        return self::$fieldRegistry[$fieldId] ?? null;
    }

    public static function getFieldRegistry(): array
    {
        return self::$fieldRegistry;
    }

    /**
     * Retrieve the editor config for a field
     * Returns null if the field doesn't exists or has editor disabled.
     * Returns true for simple 'editor' => true declarations.
     * Returns the settings array for 'editor' => [...] declarations.
     */
    public static function get_editor_config(string $fieldId): mixed
    {
        $field = self::$fieldRegistry[$fieldId] ?? null;

        if (! $field) {
            return null;
        }

        $editor = $field['editor'] ?? false;

        // Not editor-enabled
        if ($editor === false || $editor === null) {
            return null;
        }

        return $editor;
    }

    /**
     * Display validation errors stored in a transient as admin notices.
     * 
     * This method is hooked to 'admin_notices' and checks for a transient named
     * 'taw_validation_errors_{post_id}'. If found, it displays each error as
     * an admin notice and then deletes the transient.
     */
    public static function displayValidationErrors(): void
    {
        global $post;
        if (!$post) return;

        $errors = get_transient('taw_validation_errors_' . $post->ID);
        if (!$errors) return;

        delete_transient('taw_validation_errors_' . $post->ID);

        foreach ($errors as $error) {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($error)
            );
        }
    }
}
