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
     * IMPORTANT — Escaping Contract:
     * This method OWNS escaping for any value it returns. Because the
     * return value may contain HTML wrapper tags (when the editor is
     * active), callers MUST use `echo` without additional escaping:
     *
     *   <?php echo taw_editable($heading, 'hero', 'hero_heading'); ?>
     *
     * That means we escape the inner value here — on EVERY exit path —
     * using the field-type-aware escapeValue() helper.
     *
     * @param mixed  $value   The field value to display.
     * @param string $blockId The block's ID (e.g., 'hero').
     * @param string $fieldId The field's ID (e.g., 'hero_heading').
     * @param string $tag     The wrapper tag when annotating. Default 'span'.
     * @return string Escaped (and possibly editor-annotated) HTML string.
     */
    public static function field(
        mixed $value,
        string $blockId,
        string $fieldId,
        string $tag = 'span'
    ): string {

        // ── Resolve field type for escaping ─────────────────────
        //
        // We look this up BEFORE checking editor state because we
        // need it on every exit path. The lookup is a static array
        // access — effectively free.

        $fieldConfig = Metabox::get_field_config($fieldId);

        // Dev safety net: warn if the field isn't registered.
        // This catches typos like 'hero_headng' during development
        // without breaking production (where WP_DEBUG is off).
        if ($fieldConfig === null && defined('WP_DEBUG') && WP_DEBUG) {
            trigger_error(
                sprintf(
                    'taw_editable(): field "%s" is not registered in any Metabox. '
                        . 'Check for typos in the field ID.',
                    $fieldId
                ),
                E_USER_NOTICE
            );
        }

        $fieldType = $fieldConfig['type'] ?? 'text';

        // ── Escape the value based on field type ────────────────
        //
        // This is the VIP-mandated "late escaping" — applied at the
        // exact point of output, using the function that matches
        // the output context (HTML body, URL, trusted HTML, etc.)

        $escaped = self::escapeValue($value, $fieldType);

        // ── Fast path: not in edit mode ─────────────────────────
        //
        // ~99% of page views hit this path. The overhead vs. the
        // old code is one static array lookup + one match expression.

        if (! VisualEditor::isActive()) {
            return $escaped;
        }

        // ── Check if this field is editor-enabled ───────────────

        $editorConfig = Metabox::get_editor_config($fieldId);

        if ($editorConfig === null) {
            return $escaped;
        }

        // ── Build annotated output for the visual editor ────────

        $fieldLabel = $fieldConfig['label'] ?? $fieldId;

        $attrs = self::buildDataAttributes($blockId, $fieldId, $fieldType, $fieldLabel, $editorConfig);

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
     * Escape a field value using the appropriate function for its type.
     *
     * This is the SINGLE SOURCE OF TRUTH for "how do I safely output
     * this field type?" Every output helper in the framework should
     * funnel through here rather than choosing escape functions ad hoc.
     *
     * The mapping follows WordPress VIP's escaping conventions:
     *
     *   text, textarea, select, etc. → esc_html()
     *       Plain text rendered inside HTML tags. Converts <, >, &, "
     *       to HTML entities so they display as text, never execute.
     *
     *   wysiwyg → wp_kses_post()
     *       Trusted HTML from the rich text editor. Strips dangerous
     *       tags (<script>, <iframe>, event handlers) while preserving
     *       safe formatting tags (<strong>, <em>, <a>, <ul>, etc.)
     *
     *   url, image → esc_url()
     *       URLs for href/src attributes. Validates the protocol
     *       (http, https, mailto, tel) and strips dangerous schemes
     *       like javascript: — also encodes special characters.
     *
     * The default branch uses esc_html(), which is the MOST restrictive
     * option. If a new field type gets added and we forget to update
     * this method, the worst case is over-escaping (cosmetic), never
     * under-escaping (security hole).
     *
     * @param mixed  $value The raw field value from post_meta.
     * @param string $type  The field type from the Metabox registry.
     * @return string The escaped value, safe for its intended output context.
     */
    private static function escapeValue(mixed $value, string $type): string
    {
        $value = (string) $value;

        return match ($type) {
            'wysiwyg' => wp_kses_post($value),
            'url'     => esc_url($value),
            'image'   => esc_url($value),
            default   => esc_html($value),
        };
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
     *
     * @param string $blockId The block's ID.
     * @return string The data attribute string, or empty string when editor is inactive.
     */
    public static function section(string $blockId): string
    {
        if (! VisualEditor::isActive()) {
            return '';
        }

        return sprintf('data-taw-block-section="%s"', esc_attr($blockId));
    }
}
