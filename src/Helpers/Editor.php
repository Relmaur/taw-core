<?php

declare(strict_types=1);

namespace TAW\Helpers;

use TAW\Core\VisualEditor;
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

        // Fast path: not in edit mode - zero overhead
        if (! VisualEditor::isActive()) {
            return (string) $value;
        }

        $editorConfig = Metabox::get_editor_config($fieldId);

        if ($editorConfig === null) {
            return (string) $value;
        }

        // Resolve field type from registry
        $fieldConfig = Metabox::get_field_config($fieldId);
        $fieldType = $fieldConfig['type'] ?? 'text';
        $fieldLabel = $fieldConfig['label'] ?? $fieldId;

        // Build data attributes
        $attrs = self::buildDataAttributes($blockId, $fieldId, $fieldType, $fieldLabel, $editorConfig);

        // Wrap the value
        return <<<HTML
            <{$tag} {$attrs}>{$value}</{$tag}>
        HTML;
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
        $fieldType   = $fieldConfig['type'] ?? 'text';
        $fieldLabel  = $fieldConfig['label'] ?? $fieldId;

        return self::buildDataAttributes($blockId, $fieldId, $fieldType, $fieldLabel, $editorConfig);
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
}
