<?php

declare(strict_types=1);

namespace TAW\Helpers;

use TAW\Core\Editor\VisualEditor;
use TAW\Core\Metabox\Metabox;

class Editor
{

    /**
     * Wrap a value with visual editor annotation if edit is active
     * and the field is editor-enabled.
     * 
     * Usage in templates:
     * <?php echo Editor::field($heading, 'hero', 'hero_heading'); ?>
     * 
     * For images:
     * <img src="<?php echo Editor::field($image_url, 'hero', 'hero_image'); ?>"
     *   <?php echo Editor::attrs('hero', 'hero_image'); ?>>
     * 
     * @param mixed $value The field value to display.
     * @param string $blockId The block's ID (e.g., 'hero'),
     * @param string $fieldId The field's ID (e.g., 'hero_heading').
     * @param string $tag The wrapper tag when annotating. Default 'span'.
     */
    public static function field(
        mixed $value,
        string $blockId,
        string $fieldId,
        string $tag = 'span'
    ): string {

        /**
         * Always escape, regardless of editor state
         * 
         * This method takes over escaping responsibility from the template developer.
         * Since the return value may include HTML wrapper tags (when the editor is active), the caller
         * MUST use 'echo' without additional escaping - which means we must handle it here, every time,
         * no exceptions.
         */
        $fieldConfig = Metabox::get_field_config($fieldId);
        $fieldType = $fieldConfig['type'] ?? 'text';

        $escaped = self::escapeValue($value, $fieldType);

        // Fast path: not in edit mode - return esacped value, zero markup overhead
        if (! VisualEditor::isActive()) {
            return $escaped;
        }

        $editorConfig = Metabox::get_editor_config($fieldId);

        if ($editorConfig === null) {
            return $escaped;
        }

        $fieldLabel = $fieldConfig['label'] ?? $fieldId;

        // Build data attributes
        $attrs = self::buildDataAttributes($blockId, $fieldId, $fieldType, $fieldLabel, $editorConfig);

        // Wrap the escaped value
        return "<{$tag} {$attrs}>{$escaped}</{$tag}>";
    }

    /**
     * Return just the data attributes string.
     * 
     * Useful for elements where you can't wrap with a tag,
     * e.g., <img> or elements where you want to add attrs
     * to an existing tag.
     *
     * Usage:
     *   <section <?php echo Editor::attrs('hero', 'hero_bg_image'); ?>>
     */
    public static function attrs(string $blockId, string $fieldId): string
    {
        if (! VisualEditor::isActive()) {
            return '';
        }

        $editorConfig = Metabox::get_editor_config($fieldId);

        if ($editorConfig === null) {
            return '';
        }

        $fieldConfig = Metabox::get_field_config($fieldId);

        if ($fieldConfig === null && defined('WP_DEBUG') && WP_DEBUG) {
            // Help developers catch typos during development
            trigger_error(
                sprintf('taw_editable(): field "%s" is not registered in any Metabox.', $fieldId),
                E_USER_NOTICE
            );
        }

        $fieldType   = $fieldConfig['type'] ?? 'text';

        $fieldLabel  = $fieldConfig['label'] ?? $fieldId;

        return self::buildDataAttributes($blockId, $fieldId, $fieldType, $fieldLabel, $editorConfig);
    }

    /**
     * Escape a field value using the appropriate function for its type.
     *
     * This is the single source of truth for "how do I safely output
     * this field type?" The mapping follows WordPress VIP conventions:
     *
     *  - text/textarea/select/etc. → esc_html()   (plain text into HTML body)
     *  - wysiwyg                   → wp_kses_post() (trusted HTML, strip dangerous tags)
     *  - url                       → esc_url()     (sanitize for href/src contexts)
     *  - image                     → esc_url()     (attachment URLs)
     *
     * @param mixed  $value The raw field value.
     * @param string $type  The field type from the registry.
     * @return string The escaped value, safe for its intended output context.
     */
    private static function escapeValue(mixed $value, string $type): string
    {
        $value = (string) $value;

        return match ($type) {
            'wysiwyg'  => wp_kses_post($value),
            'url'      => esc_url($value),
            'image'    => esc_url($value),
            default    => esc_html($value),
        };
    }

    /**
     * Build the HTML data attribute string for a field.
     */
    private static function buildDataAttributes(
        string $blockId,
        string $fieldId,
        string $fieldType,
        string $fieldLabel,
        mixed $editorConfig
    ): string {
        $attrs = sprintf(
            'data-taw-block="%s" data-taw-field="%s" data-taw-type="%s" data-taw-label="%s"',
            esc_attr($blockId),
            esc_attr($fieldId),
            esc_attr($fieldType),
            esc_attr($fieldLabel)
        );

        // If editor config is an array, encode any settings as JSON
        if (is_array($editorConfig)) {
            $attrs .= sprintf(
                ' data-taw-editor="%s"',
                esc_attr(wp_json_encode($editorConfig))
            );
        }

        return $attrs;
    }

    /**
     * Return the editor data attributes as a key-value array.
     * 
     * Designed for integration with helpers that build HTML
     * from attribute arrays (like Image::render()).
     *
     * Usage with Image::render():
     *   echo Image::render($id, 'full', 'Alt text', [
     *       'above_fold' => true,
     *       'attr'       => Editor::attrsArray('hero', 'hero_image'),
     *   ]);
     *
     * @return array<string, string> Empty array when editor is inactive.
     */
    public static function attrsArray(string $blockId, string $fieldId): array
    {
        if (! VisualEditor::isActive()) {
            return [];
        }

        $editorConfig = Metabox::get_editor_config($fieldId);

        if ($editorConfig === null) {
            return [];
        }

        $fieldConfig = Metabox::get_field_config($fieldId);
        $fieldType   = $fieldConfig['type'] ?? 'text';
        $fieldLabel  = $fieldConfig['label'] ?? $fieldId;

        $attrs = [
            'data-taw-block' => $blockId,
            'data-taw-field' => $fieldId,
            'data-taw-type'  => $fieldType,
            'data-taw-label' => $fieldLabel,
        ];

        if (is_array($editorConfig)) {
            $attrs['data-taw-editor'] = wp_json_encode($editorConfig);
        }

        return $attrs;
    }

    /**
     * Return a data attribute marking this element as a block section.
     * 
     * Place on the outermost element of a MetaBlock template so the
     * visual editor can detect section-level clicks.
     *
     * Usage:
     *   <section class="hero" <?php echo taw_editor_section('hero'); ?>>
     */
    public static function section(string $blockId): string
    {
        if (! VisualEditor::isActive()) {
            return '';
        }

        return sprintf('data-taw-block-section="%s"', esc_attr($blockId));
    }
}
