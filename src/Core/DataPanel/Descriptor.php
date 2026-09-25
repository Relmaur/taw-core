<?php

declare(strict_types=1);

namespace TAW\Core\DataPanel;

use TAW\Core\Content\FieldCodec;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\Field;

// No ABSPATH guard: pure class (reads a Metabox's config, calls no WordPress).

/**
 * What the data panel receives for a fieldset (ADR-0007 § 6): plain JSON,
 * never PHP callables.
 *
 *   {
 *     "id": "book_details", "title": "Book details", "icon": "dashicons-book",
 *     "templates": [],                  template-file screens, re-checked live
 *     "tabs": [{"label": "…", "icon": "…", "fields": ["book_author"]}],
 *     "fields": [
 *       {"id": "book_author", "type": "text", "label": "Author",
 *        "binding": {"meta": "_taw_book_author"}, …},
 *       {"id": "book_links", "type": "repeater",
 *        "binding": {"field": "taw_book_links"}, "fields": [ …no bindings… ]}
 *     ]
 *   }
 *
 * A binding says where the value lives on the REST post object: a key in
 * `meta` (scalar fields, and group sub-fields under "{prefix}{group}_{sub}"),
 * or a top-level `taw_<id>` field (repeater, files, post_select; see
 * Rest\FieldMetaRegistrar). Repeater sub-fields have none: they're keys of
 * each row.
 */
final class Descriptor
{
    /** Every type the panel renders: all of Field::TYPES (ADR-0007 § 8). */
    public const TYPES = Field::TYPES;

    /** Field keys passed through as-is (when JSON-safe). */
    private const PASS_KEYS = [
        'label', 'description', 'placeholder', 'default', 'required', 'readonly', 'width',
        'options', 'multiple', 'min', 'max', 'step', 'unit', 'rows', 'teeny', 'media_buttons',
        'date_format', 'min_date', 'max_date', 'post_type', 'limit', 'button_label', 'layout',
        'file_types', 'blocks',
    ];

    /**
     * Whether every field (nested ones too) has a type the panel renders. A
     * fieldset that doesn't stays a metabox as a whole (ADR-0007 § 8).
     */
    public static function supports(Metabox $box): bool
    {
        return self::allSupported($box->fields());
    }

    /**
     * @return array<string, mixed>|null Null when the fieldset isn't supported.
     */
    public static function fieldset(Metabox $box): ?array
    {
        if (!self::supports($box)) {
            return null;
        }

        $tabs = [];
        foreach ($box->tabs() as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $tabs[] = [
                'label'  => is_string($tab['label'] ?? null) ? $tab['label'] : '',
                'icon'   => is_string($tab['icon'] ?? null) ? $tab['icon'] : '',
                'fields' => array_values(array_filter((array) ($tab['fields'] ?? []), 'is_string')),
            ];
        }

        return [
            'id'        => $box->id(),
            'title'     => $box->title(),
            'icon'      => $box->icon(),
            'templates' => array_values($box->templateScreens()),
            'tabs'      => $tabs,
            'fields'    => array_map(
                static fn (array $field): array => self::field($field, $box->prefix()),
                array_values(array_filter($box->fields(), 'is_array'))
            ),
        ];
    }

    /**
     * @param array<string, mixed> $field
     * @param string|null $groupId  Set for a group's sub-fields.
     * @return array<string, mixed>
     */
    public static function field(array $field, string $prefix, ?string $groupId = null, bool $inRepeater = false): array
    {
        $id   = (string) ($field['id'] ?? '');
        $type = (string) ($field['type'] ?? 'text');

        $out = ['id' => $id, 'type' => $type];
        foreach (self::PASS_KEYS as $key) {
            if (array_key_exists($key, $field) && self::isJsonSafe($field[$key])) {
                $out[$key] = $field[$key];
            }
        }

        // The panel shows the server's verdict after a save; it only needs
        // to know a check exists (the callable itself stays in PHP).
        if (isset($field['validate'])) {
            $out['validated'] = true;
        }

        if (is_array($field['conditions'] ?? null)) {
            $out['conditions'] = self::conditions($field['conditions']);
        }

        if (!$inRepeater) {
            $out['binding'] = match (true) {
                $groupId !== null                                   => ['meta' => $prefix . $groupId . '_' . $id],
                in_array($type, FieldCodec::STRUCTURED_TYPES, true) => ['field' => 'taw_' . $id],
                $type === 'group'                                   => null,
                default                                             => ['meta' => $prefix . $id],
            };
            if ($out['binding'] === null) {
                unset($out['binding']);
            }
        }

        $children = array_values(array_filter((array) ($field['fields'] ?? []), 'is_array'));
        if ($type === 'group') {
            $out['fields'] = array_map(static fn (array $sub): array => self::field($sub, $prefix, $id), $children);
        } elseif ($type === 'repeater') {
            $out['fields'] = array_map(static fn (array $sub): array => self::field($sub, $prefix, null, true), $children);
        }

        return $out;
    }

    /**
     * @param array<mixed> $conditions
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    private static function conditions(array $conditions): array
    {
        $out = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $value = $condition['value'] ?? null;
            $out[] = [
                'field'    => (string) ($condition['id'] ?? $condition['field'] ?? ''),
                'operator' => (string) ($condition['operator'] ?? '=='),
                'value'    => self::isJsonSafe($value) ? $value : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<mixed> $fields
     */
    private static function allSupported(array $fields): bool
    {
        foreach ($fields as $field) {
            if (!is_array($field) || !in_array($field['type'] ?? 'text', self::TYPES, true)) {
                return false;
            }
            if (is_array($field['fields'] ?? null) && !self::allSupported($field['fields'])) {
                return false;
            }
        }

        return true;
    }

    private static function isJsonSafe(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false; // objects, closures, resources
        }
        foreach ($value as $item) {
            if (!self::isJsonSafe($item)) {
                return false;
            }
        }

        return true;
    }
}
