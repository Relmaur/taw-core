<?php

declare(strict_types=1);

namespace TAW\Core\OptionsPage;

use TAW\Core\Metabox\Metabox;
use TAW\Helpers\Framework;

/**
 * Configuration-driven Options Page.
 *
 * Uses the same field config format as Metabox, but stores
 * values in wp_options instead of post_meta.
 *
 * Supported field types: text, url, number, textarea, wysiwyg, select,
 * checkbox, color, range, datepicker, image, files, group, post_select, repeater.
 *
 * Usage:
 *   new OptionsPage([
 *       'id'         => 'taw_general',
 *       'title'      => 'TAW Settings',
 *       'menu_title' => 'TAW Settings',
 *       'fields'     => [
 *           ['id' => 'company_phone', 'label' => 'Phone', 'type' => 'text'],
 *       ],
 *   ]);
 *
 * Retrieval:
 *   OptionsPage::get('company_phone');
 */
class OptionsPage
{
    private string $id;
    private string $title;
    private string $menu_title;
    private string $capability;
    private string $prefix;
    private array  $fields;
    private array  $tabs;
    private string $icon;
    private ?int   $position;

    public function __construct(array $config)
    {
        $this->id         = $config['id'];
        $this->title      = $config['title']      ?? $config['id'];
        $this->menu_title = $config['menu_title']  ?? $this->title;
        $this->capability = $config['capability']  ?? 'manage_options';
        $this->prefix     = $config['prefix']      ?? '_taw_';
        $this->fields     = $config['fields']      ?? [];
        $this->tabs       = $config['tabs']        ?? [];
        $this->icon       = $config['icon']        ?? 'dashicons-admin-generic';
        $this->position   = $config['position']    ?? null;

        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . $this->id) {
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
            Framework::version()
        );

        Metabox::enqueue_field_scripts($this->fields);
    }

    /**
     * Register the admin menu page.
     */
    public function register_page(): void
    {
        add_menu_page(
            $this->title,
            $this->menu_title,
            $this->capability,
            $this->id,
            [$this, 'render_page'],
            $this->icon,
            $this->position
        );
    }

    /**
     * Register each field as a WordPress setting.
     *
     * Group sub-fields are expanded to separate options (e.g. _taw_group_subfield).
     * Repeater fields are registered as a single option storing JSON.
     */
    public function register_settings(): void
    {
        foreach ($this->get_all_fields() as $field) {
            $option_name = $this->prefix . $field['id'];

            register_setting($this->id, $option_name, [
                'sanitize_callback' => function ($value) use ($field, $option_name) {
                    $validation = $this->validate_field($field, $value);
                    if ($validation !== true) {
                        add_settings_error(
                            $option_name,
                            $option_name . '_error',
                            $validation,
                            'error'
                        );
                        return get_option($option_name);
                    }

                    return $this->sanitize_field($field, $value);
                },
            ]);
        }
    }

    /**
     * Flatten all fields for Settings API registration.
     *
     * - Tab fields: pulled from $this->fields by ID reference.
     * - Group fields: expanded to sub-fields with compound IDs ({group_id}_{sub_id}).
     * - Repeater fields: included as a single field (stores JSON blob).
     */
    private function get_all_fields(): array
    {
        $source = [];

        if (empty($this->tabs)) {
            $source = $this->fields;
        } else {
            foreach ($this->tabs as $tab) {
                foreach ($tab['fields'] as $field_id) {
                    foreach ($this->fields as $field) {
                        if ($field['id'] === $field_id) {
                            $source[] = $field;
                        }
                    }
                }
            }
        }

        $flat = [];
        foreach ($source as $field) {
            if (($field['type'] ?? '') === 'group' && !empty($field['fields'])) {
                foreach ($field['fields'] as $sub) {
                    $flat[] = array_merge($sub, ['id' => $field['id'] . '_' . $sub['id']]);
                }
            } else {
                $flat[] = $field;
            }
        }

        return $flat;
    }

    /**
     * Build initial Alpine.js reactive values from all current option values.
     */
    private function build_initial_values(): array
    {
        $values = [];
        foreach ($this->get_all_fields() as $field) {
            $option_name = $this->prefix . $field['id'];
            $values[$option_name] = get_option($option_name, $field['default'] ?? '');
        }
        return $values;
    }

    /**
     * Render the full admin page.
     *
     * The form element carries the Alpine.js reactive state so that
     * conditional fields (x-show) can reference any option value by name.
     */
    public function render_page(): void
    {
        if (!current_user_can($this->capability)) {
            return;
        }

        $initial_values = $this->build_initial_values();
        $x_data_json    = wp_json_encode($initial_values, JSON_UNESCAPED_UNICODE);
?>
        <div class="wrap taw-options-page">
            <h1><?php echo esc_html($this->title); ?></h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php"
                x-data="{ fields: <?php echo esc_attr($x_data_json); ?> }"
                x-init="
                    $el.querySelectorAll('input, select, textarea').forEach(el => {
                        if (el.name && fields.hasOwnProperty(el.name)) {
                            el.addEventListener('input', () => { fields[el.name] = el.value; });
                            el.addEventListener('change', () => { fields[el.name] = el.value; });
                        }
                    });
                ">
                <?php settings_fields($this->id); ?>

                <?php if (!empty($this->tabs)): ?>
                    <?php $this->render_tabs(); ?>
                <?php else: ?>
                    <div class="taw-options-fields fields-container">
                        <?php $this->render_fields($this->fields); ?>
                    </div>
                <?php endif; ?>

                <?php submit_button(); ?>
            </form>
        </div>
    <?php
    }

    /**
     * Render tabbed layout.
     *
     * Uses its own x-data scope for activeTab; the fields reactive state
     * is inherited from the form's parent Alpine scope.
     */
    private function render_tabs(): void
    {
    ?>
        <div class="taw-tabbed" x-data="{ activeTab: 0 }">
            <div class="tabs">
                <?php foreach ($this->tabs as $index => $tab): ?>
                    <div class="tab-title"
                        :class="activeTab === <?php echo $index; ?> ? 'active' : ''"
                        @click="activeTab = <?php echo $index; ?>">
                        <?php if (!empty($tab['icon'])): ?>
                            <img src="<?php echo esc_url($tab['icon']); ?>" alt="">
                        <?php endif; ?>
                        <p><?php echo esc_html($tab['label'] ?? __('Tab', 'taw-theme')); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="tab-content--wrapper">
                <?php foreach ($this->tabs as $index => $tab):
                    $tab_fields = array_filter($this->fields, function ($field) use ($tab) {
                        return in_array($field['id'], $tab['fields'] ?? []);
                    });
                ?>
                    <div class="fields-container"
                        x-show="activeTab === <?php echo $index; ?>"
                        x-cloak>
                        <?php $this->render_fields($tab_fields); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a set of fields, reading values from wp_options.
     *
     * Supports: width (--span), required asterisk, and conditional visibility
     * via Alpine.js x-show — requires the parent form to carry x-data fields.
     */
    private function render_fields(array $fields): void
    {
        foreach ($fields as $field) {
            $field_id      = $this->prefix . $field['id'];
            $value         = get_option($field_id, $field['default'] ?? '');
            $label         = $field['label'] ?? '';
            $desc          = $field['description'] ?? '';
            $has_conditions = !empty($field['conditions']);
        ?>
            <div class="field" style="--span: <?php echo esc_attr($field['width'] ?? '100'); ?>;"
                <?php if ($has_conditions): ?>
                x-show="<?php echo esc_attr($this->build_conditions_expression($field['conditions'])); ?>"
                x-cloak
                <?php endif; ?>>
                <div class="field-and-label">
                    <label for="<?php echo esc_attr($field_id); ?>" class="field-label">
                        <?php echo esc_html($label); ?>
                        <?php if (!empty($field['required'])): ?>
                            <span class="taw-required">*</span>
                        <?php endif; ?>
                    </label>
                    <?php $this->render_field($field, $field_id, $value); ?>
                </div>
                <?php if ($desc): ?>
                    <p class="description"><?php echo esc_html($desc); ?></p>
                <?php endif; ?>
            </div>
        <?php
        }
    }

    /**
     * Render a single field by type.
     *
     * Mirrors Metabox::render_field() — the name attribute equals the option name
     * so the WordPress Settings API picks it up on form submit.
     */
    private function render_field(array $field, string $field_id, mixed $value): void
    {
        $type        = $field['type'] ?? 'text';
        $placeholder = $field['placeholder'] ?? '';

        switch ($type) {

            /* ---- Text ---- */
            case 'text':
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text">',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value),
                    esc_attr($placeholder)
                );
                break;

            /* ---- URL ---- */
            case 'url':
                printf(
                    '<input type="url" id="%s" name="%s" value="%s" placeholder="%s" class="regular-text">',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_url($value),
                    esc_attr($placeholder)
                );
                break;

            /* ---- Number ---- */
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

            /* ---- Textarea ---- */
            case 'textarea':
                printf(
                    '<textarea id="%s" name="%s" rows="%d" class="large-text" placeholder="%s">%s</textarea>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    intval($field['rows'] ?? 4),
                    esc_attr($placeholder),
                    esc_textarea($value)
                );
                break;

            /* ---- WYSIWYG ---- */
            case 'wysiwyg':
                wp_editor($value ?: '', $field_id, [
                    'textarea_name' => $field_id,
                    'textarea_rows' => intval($field['rows'] ?? 8),
                    'media_buttons' => $field['media_buttons'] ?? true,
                    'teeny'         => $field['teeny'] ?? false,
                ]);
                break;

            /* ---- Select ---- */
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

            /* ---- Checkbox ---- */
            case 'checkbox':
                printf('<input type="hidden" name="%s" value="0">', esc_attr($field_id));
                printf(
                    '<label class="taw-toggle"><input type="checkbox" id="%s" name="%s" value="1" %s><span class="taw-toggle-slider"></span></label>',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    checked($value, '1', false)
                );
                break;

            /* ---- Color Picker ---- */
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

            /* ---- Datepicker ---- */
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

            /* ---- Range ---- */
            case 'range':
                $min     = $field['min']  ?? 0;
                $max     = $field['max']  ?? 100;
                $step    = $field['step'] ?? 1;
                $unit    = $field['unit'] ?? '';
                $current = $value !== '' ? $value : ($field['default'] ?? $min);
            ?>
                <div class="taw-range-field" x-data="{ val: <?php echo floatval($current); ?> }">
                    <div class="taw-range-controls">
                        <input type="range"
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

            /* ---- Image ---- */
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
            <?php
                break;

            /* ---- Files (multi-attachment gallery) ---- */
            case 'files':
                $ids        = $value ? json_decode($value, true) : [];
                if (!is_array($ids)) $ids = [];
                $limit      = $field['limit']      ?? 0;
                $file_types = $field['file_types']  ?? '';

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
            <?php
                break;

            /* ---- Group ---- */
            case 'group':
                $this->render_group($field['fields'] ?? [], $field_id);
                break;

            /* ---- Post Selector ---- */
            case 'post_select':
                $multiple   = !empty($field['multiple']);
                $post_types = $field['post_type'] ?? 'post';
                $max        = $field['max'] ?? 0;

                if ($multiple) {
                    $selected_ids = $value ? json_decode($value, true) : [];
                    if (!is_array($selected_ids)) $selected_ids = [];
                } else {
                    $selected_ids = $value ? [absint($value)] : [];
                }

                $selected_posts = [];
                foreach ($selected_ids as $pid) {
                    $p = get_post($pid);
                    if ($p && $p->post_status === 'publish') {
                        $thumb_id       = get_post_thumbnail_id($p->ID);
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
                    <input type="hidden"
                        class="taw-post-selector-input"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($field_id); ?>"
                        value="<?php echo esc_attr($value); ?>">
                    <div class="taw-post-selector-selected"></div>
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

            /* ---- Repeater ---- */
            case 'repeater':
                $sub_fields   = $field['fields']       ?? [];
                $max_rows     = $field['max']           ?? 0;
                $min_rows     = $field['min']           ?? 0;
                $button_label = $field['button_label']  ?? __('Add Row', 'taw-theme');
                $layout       = $field['layout']        ?? '';

                $rows = $value ? json_decode($value, true) : [];
                if (!is_array($rows)) $rows = [];

                $tpl_placeholder = '__TAWRPT_' . $field_id . '__';
            ?>
                <div class="taw-repeater"
                    data-field-id="<?php echo esc_attr($field_id); ?>"
                    data-placeholder="<?php echo esc_attr($tpl_placeholder); ?>"
                    data-max="<?php echo intval($max_rows); ?>"
                    data-min="<?php echo intval($min_rows); ?>"
                    <?php if ($layout): ?>data-layout="<?php echo esc_attr($layout); ?>"<?php endif; ?>>
                    <input type="hidden"
                        class="taw-repeater-input"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($field_id); ?>"
                        value="<?php echo esc_attr($value ?: '[]'); ?>">
                    <div class="taw-repeater-rows">
                        <?php foreach ($rows as $row_index => $row_data):
                            $this->render_repeater_row($sub_fields, $field_id, $row_index, $row_data);
                        endforeach; ?>
                    </div>
                    <button type="button" class="button taw-repeater-add">
                        <?php echo esc_html($button_label); ?>
                    </button>
                    <template class="taw-repeater-template">
                        <?php $this->render_repeater_row($sub_fields, $field_id, $tpl_placeholder, []); ?>
                    </template>
                </div>
            <?php
                break;

            /* ---- Default ---- */
            default:
                printf(
                    '<input type="text" id="%s" name="%s" value="%s" class="regular-text">',
                    esc_attr($field_id),
                    esc_attr($field_id),
                    esc_attr($value)
                );
                break;
        }
    }

    /**
     * Render group sub-fields, each stored as its own option (_taw_{group_id}_{sub_id}).
     */
    private function render_group(array $group_fields, string $field_id_prefix): void
    {
        ?><div class="fields-container"><?php
        foreach ($group_fields as $field) {
            $field_id = $field_id_prefix . '_' . $field['id'];
            $value    = get_option($field_id, $field['default'] ?? '');
        ?>
            <div class="field" style="--span: <?php echo esc_attr($field['width'] ?? '100'); ?>;">
                <div class="field-and-label">
                    <label for="<?php echo esc_attr($field_id); ?>" class="field-label">
                        <?php echo esc_html($field['label'] ?? ''); ?>
                    </label>
                    <?php $this->render_field($field, $field_id, $value); ?>
                </div>
                <?php if (!empty($field['description'])): ?>
                    <p class="description"><?php echo esc_html($field['description']); ?></p>
                <?php endif; ?>
            </div>
        <?php }
        ?></div><?php
    }

    /**
     * Render a single repeater row (saved data or template row).
     */
    private function render_repeater_row(array $sub_fields, string $field_id, int|string $index, array $row_data): void
    {
        $local_field_ids    = array_column($sub_fields, 'id');
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
                        $sub_id       = $sub_field['id'];
                        $sub_value    = $row_data[$sub_id] ?? '';
                        $width        = (int) ($sub_field['width'] ?? 100);
                        $has_sub_cond = !empty($sub_field['conditions']);
                        $input_name   = 'taw_repeater[' . $field_id . '][' . $index . '][' . $sub_id . ']';
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
                                <?php $this->render_field($sub_field, $input_name, $sub_value); ?>
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
     * Build an Alpine.js x-show expression from field conditions.
     *
     * Sibling sub-fields within a repeater row are read from rowFields[id];
     * all other references read from the form-level fields[option_name].
     *
     * @param array  $conditions     Condition definitions.
     * @param array  $local_field_ids Sub-field IDs in the current repeater row scope.
     */
    private function build_conditions_expression(array $conditions, array $local_field_ids = []): string
    {
        $parts = [];

        foreach ($conditions as $condition) {
            $raw_id   = $condition['id'] ?? $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '==';
            $value    = $condition['value'];

            if ($raw_id !== '' && in_array($raw_id, $local_field_ids, true)) {
                $field_ref = "rowFields['{$raw_id}']";
            } else {
                $option_name = $this->prefix . $raw_id;
                $field_ref   = "fields['{$option_name}']";
            }

            $safe_val = is_numeric($value) ? $value : "'" . esc_js($value) . "'";

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
     * Validate a field value before saving.
     *
     * Returns true on success, or a string error message on failure.
     */
    private function validate_field(array $field, mixed $value): true|string
    {
        $label = $field['label'] ?? $field['id'];

        if (!empty($field['required']) && ($value === '' || $value === null)) {
            return sprintf(__('%s is required.', 'taw-core'), $label);
        }

        if (($field['type'] ?? '') === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            return sprintf(__('%s must be a valid URL.', 'taw-core'), $label);
        }

        if (($field['type'] ?? '') === 'number' && $value !== '') {
            $num = floatval($value);
            if (isset($field['min']) && $num < $field['min']) {
                return sprintf(__('%s must be at least %s.', 'taw-core'), $label, $field['min']);
            }
            if (isset($field['max']) && $num > $field['max']) {
                return sprintf(__('%s must be at most %s.', 'taw-core'), $label, $field['max']);
            }
        }

        if (isset($field['validate']) && is_callable($field['validate'])) {
            $result = call_user_func($field['validate'], $value);
            if ($result !== true) {
                return is_string($result) ? $result : sprintf(__('%s is invalid.', 'taw-core'), $label);
            }
        }

        return true;
    }

    /**
     * Sanitize a field value — mirrors Metabox::sanitize_field().
     */
    private function sanitize_field(array $field, mixed $value): mixed
    {
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
            'post_select'    => $this->sanitize_post_select($field, $value),
            'files'          => $this->sanitize_files($value),
            'repeater'       => $this->sanitize_repeater($field, $value),
            default          => sanitize_text_field($value),
        };
    }

    /**
     * Sanitize post_select — mirrors Metabox::sanitize_post_select().
     */
    private function sanitize_post_select(array $field, mixed $value): string
    {
        $multiple = !empty($field['multiple']);

        if (!$multiple) {
            $id = absint($value);
            return $id ? (string) $id : '';
        }

        $ids = json_decode($value, true);
        if (!is_array($ids)) {
            return '[]';
        }

        $clean = array_values(array_unique(array_filter(array_map('absint', $ids))));

        $max = $field['max'] ?? 0;
        if ($max > 0) {
            $clean = array_slice($clean, 0, $max);
        }

        return wp_json_encode($clean);
    }

    /**
     * Sanitize files — mirrors Metabox::sanitize_files().
     */
    private function sanitize_files(mixed $value): string
    {
        $ids = json_decode((string) $value, true);
        if (!is_array($ids)) return '[]';

        $clean = array_values(array_filter(array_map('absint', $ids)));
        return wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sanitize repeater — mirrors Metabox::sanitize_repeater().
     */
    private function sanitize_repeater(array $field, mixed $value): string
    {
        $rows = json_decode($value, true);
        if (!is_array($rows)) {
            return '[]';
        }

        $sub_fields = $field['fields'] ?? [];
        $max        = $field['max']    ?? 0;

        if ($max > 0) {
            $rows = array_slice($rows, 0, $max);
        }

        $field_map = [];
        foreach ($sub_fields as $sf) {
            $field_map[$sf['id']] = $sf;
        }

        $clean_rows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $clean_row = [];
            foreach ($row as $key => $val) {
                if (!isset($field_map[$key])) continue;

                $cleaned = $this->sanitize_field($field_map[$key], $val);

                if (($field_map[$key]['type'] ?? '') === 'repeater') {
                    $clean_row[$key] = json_decode($cleaned, true) ?: [];
                } else {
                    $clean_row[$key] = $cleaned;
                }
            }

            $has_content = array_filter($clean_row, fn($v) => $v !== '' && $v !== '0' && $v !== '[]' && $v !== []);
            if (!empty($has_content)) {
                $clean_rows[] = $clean_row;
            }
        }

        return wp_json_encode($clean_rows, JSON_UNESCAPED_UNICODE);
    }

    /* -----------------------------------------------------------------
     * Static Retrieval Helpers
     * ----------------------------------------------------------------- */

    /**
     * Get an option value.
     *
     * Usage: OptionsPage::get('company_phone')
     */
    public static function get(string $field_id, string $prefix = '_taw_', mixed $default = ''): mixed
    {
        return get_option($prefix . $field_id, $default);
    }

    /**
     * Get an image URL from an option that stores an attachment ID.
     */
    public static function get_image_url(string $field_id, string $size = 'full', string $prefix = '_taw_'): string
    {
        $attachment_id = (int) get_option($prefix . $field_id, 0);
        if (!$attachment_id) {
            return '';
        }
        return wp_get_attachment_image_url($attachment_id, $size) ?: '';
    }
}
