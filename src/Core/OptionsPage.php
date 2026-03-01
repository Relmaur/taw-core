<?php

declare(strict_types=1);

namespace TAW\Core;

/**
 * Configuration-driven Options Page.
 *
 * Uses the same field config format as Metabox, but stores
 * values in wp_options instead of post_meta.
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

        // Enqueue Alpine
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
    }

    /**
     * Register the admin menu page.
     *
     * WordPress menu pages need: slug, title, capability, callback.
     * The callback is what renders the HTML when the user visits the page.
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
     * The Settings API handles nonce verification and capability
     * checks for us — unlike metaboxes where we do it manually.
     * Each field becomes an option in wp_options.
     */
    public function register_settings(): void
    {
        foreach ($this->get_all_fields() as $field) {
            $option_name = $this->prefix . $field['id'];

            register_setting($this->id, $option_name, [
                'sanitize_callback' => function ($value) use ($field, $option_name) {
                    // Validate first
                    $validation = $this->validate_field($field, $value);
                    if ($validation !== true) {
                        add_settings_error(
                            $option_name,
                            $option_name . '_error',
                            $validation,
                            'error'
                        );
                        // Return the old value — don't save invalid data
                        return get_option($option_name);
                    }

                    // Then sanitize
                    return $this->sanitize_field($field, $value);
                },
            ]);
        }
    }

    /**
     * Flatten all fields including those inside tabs.
     * This gives us a single array of every field for registration and saving.
     */
    private function get_all_fields(): array
    {
        if (empty($this->tabs)) {
            return $this->fields;
        }

        $all = [];
        foreach ($this->tabs as $tab) {
            // Tab references field IDs — find matching fields
            foreach ($tab['fields'] as $field_id) {
                foreach ($this->fields as $field) {
                    if ($field['id'] === $field_id) {
                        $all[] = $field;
                    }
                }
            }
        }
        return $all;
    }

    /**
     * Render the full admin page.
     *
     * Key WordPress conventions:
     * - Wrap in .wrap for proper admin styling
     * - Use settings_fields() for nonce + hidden inputs
     * - Use a standard <form> that posts to options.php
     *   (WordPress handles the save internally)
     */
    public function render_page(): void
    {
        if (!current_user_can($this->capability)) {
            return;
        }
?>
        <div class="wrap taw-options-page">
            <h1><?php echo esc_html($this->title); ?></h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                // This outputs nonce, action, and option_page hidden fields.
                // WordPress validates these automatically on submit.
                settings_fields($this->id);
                ?>

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
     * Render tabbed layout — reuses Alpine.js just like your Metabox tabs.
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
     * Render a set of fields.
     *
     * Notice the key difference from Metabox: we read from get_option()
     * instead of get_post_meta(). Everything else is the same.
     */
    private function render_fields(array $fields): void
    {
        foreach ($fields as $field) {
            $field_id = $this->prefix . $field['id'];
            $value    = get_option($field_id, $field['default'] ?? '');
            $label    = $field['label'] ?? '';
            $desc     = $field['description'] ?? '';
            $width    = ($field['width'] ?? '100') . '%';
        ?>
            <div class="field" style="--width: <?php echo esc_attr($width); ?>;">
                <div class="field-and-label">
                    <label for="<?php echo esc_attr($field_id); ?>" class="field-label">
                        <?php echo esc_html($label); ?>
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
     * This mirrors Metabox::render_field() but with one key change:
     * the `name` attribute matches the option name (for Settings API),
     * not a post_meta key.
     *
     * For now, I'm including the most common types. You can expand
     * this by copying field renderers from your Metabox class.
     */
    private function render_field(array $field, string $field_id, mixed $value): void
    {
        $type        = $field['type'] ?? 'text';
        $placeholder = $field['placeholder'] ?? '';

        switch ($type) {
            case 'text':
            case 'url':
            case 'number':
                $input_type = $type === 'url' ? 'url' : ($type === 'number' ? 'number' : 'text');
            ?>
                <input type="<?php echo esc_attr($input_type); ?>"
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field_id); ?>"
                    value="<?php echo esc_attr($value); ?>"
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                    class="regular-text">
            <?php
                break;

            case 'textarea':
            ?>
                <textarea id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field_id); ?>"
                    rows="5"
                    class="large-text"
                    placeholder="<?php echo esc_attr($placeholder); ?>"><?php echo esc_textarea($value); ?></textarea>
            <?php
                break;

            case 'select':
                $choices = $field['choices'] ?? [];
            ?>
                <select id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field_id); ?>">
                    <?php foreach ($choices as $opt_value => $opt_label): ?>
                        <option value="<?php echo esc_attr($opt_value); ?>"
                            <?php selected($value, $opt_value); ?>>
                            <?php echo esc_html($opt_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php
                break;

            case 'checkbox':
            ?>
                <input type="hidden" name="<?php echo esc_attr($field_id); ?>" value="0">
                <input type="checkbox"
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field_id); ?>"
                    value="1"
                    <?php checked($value, '1'); ?>>
            <?php
                break;

            case 'wysiwyg':
                wp_editor($value ?: '', $field_id, [
                    'textarea_name' => $field_id,
                    'media_buttons' => true,
                    'textarea_rows' => 10,
                    'teeny'         => false,
                ]);
                break;

            case 'image':
                $image_url = $value ? wp_get_attachment_image_url((int) $value, 'thumbnail') : '';
            ?>
                <div class="taw-image-field">
                    <input type="hidden"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($field_id); ?>"
                        value="<?php echo esc_attr($value); ?>">
                    <div class="taw-image-preview">
                        <?php if ($image_url): ?>
                            <img src="<?php echo esc_url($image_url); ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <button type="button" class="button taw-image-upload">
                        <?php echo $value ? esc_html__('Change Image', 'taw-theme') : esc_html__('Select Image', 'taw-theme'); ?>
                    </button>
                    <?php if ($value): ?>
                        <button type="button" class="button taw-image-remove"><?php esc_html_e('Remove', 'taw-theme'); ?></button>
                    <?php endif; ?>
                </div>
            <?php
                break;

            case 'color':
            ?>
                <input type="color"
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field_id); ?>"
                    value="<?php echo esc_attr($value ?: '#000000'); ?>">
            <?php
                break;

            default:
            ?>
                <input type="text"
                    id="<?php echo esc_attr($field_id); ?>"
                    name="<?php echo esc_attr($field_id); ?>"
                    value="<?php echo esc_attr($value); ?>"
                    class="regular-text">
<?php
                break;
        }
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
            'color'          => sanitize_hex_color($value) ?: '',
            default          => sanitize_text_field($value),
        };
    }

    /* -----------------------------------------------------------------
     * Static Retrieval Helpers
     * ----------------------------------------------------------------- */

    /**
     * Get an option value.
     *
     * Usage: OptionsPage::get('company_phone')
     *
     * This is the equivalent of Metabox::get() but for options.
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
