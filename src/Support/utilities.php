<?php

declare(strict_types=1);

/**
 * Global template helpers for the TAW Visual Editor.
 *
 * These are thin wrappers around TAW\Helpers\Editor methods.
 * The class itself is autoloaded via PSR-4 only when first called.
 */

if (! function_exists('taw_editable')) {
    /**
     * Wrap a value with visual editor annotation when edit mode is active.
     */
    function taw_editable(mixed $value, string $blockId, string $fieldId, string $tag = 'span'): string
    {
        return \TAW\Helpers\Editor::field($value, $blockId, $fieldId, $tag);
    }
}

if (! function_exists('taw_editor_attrs')) {
    /**
     * Return data attributes for visual editor annotation.
     */
    function taw_editor_attrs(string $blockId, string $fieldId): string
    {
        return \TAW\Helpers\Editor::attrs($blockId, $fieldId);
    }
}

if (! function_exists('taw_editor_attrs_array')) {
    /**
     * Template helper — shorthand for Editor::attrsArray().
     */
    function taw_editor_attrs_array(string $blockId, string $fieldId): array
    {
        return \TAW\Helpers\Editor::attrsArray($blockId, $fieldId);
    }
}

if (! function_exists('taw_editor_section')) {
    function taw_editor_section(string $blockId): string
    {
        return \TAW\Helpers\Editor::section($blockId);
    }
}

/**
 * Global helper for the Dump
 */
\TAW\Helpers\Dump::skipFile(__FILE__);

/**
 * Dump helper functions
 */
function ml_dump(mixed $value, string $label = ''): void
{
    \TAW\Helpers\Dump::dump($value, $label);
}
function ml_dd(mixed $value, string $label = ''): void
{
    \TAW\Helpers\Dump::dd($value, $label);
}
