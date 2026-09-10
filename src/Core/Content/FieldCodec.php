<?php

declare(strict_types=1);

namespace TAW\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one place that knows how a stored `_taw_*` value maps to and from a
 * portable, human-reviewable shape for the content-interchange snapshot.
 *
 * "Decode" = raw meta/option string → portable value (repeater/files as
 * real arrays, checkbox as bool, image as int). "Encode" is deliberately
 * *not* here — writing goes through {@see \TAW\Core\Metabox\Metabox::writeMeta()}
 * / `sanitizeForStorage()`, which already accept arrays for the structured
 * types, so the importer hands decoded values straight back.
 *
 * Pure: every method is a static transform over its arguments with no
 * WordPress calls, so the exporter/importer decode logic is unit-testable
 * without a WP install.
 */
final class FieldCodec
{
    /**
     * Field types whose stored value is a JSON string wrapping structured data.
     */
    public const STRUCTURED_TYPES = ['repeater', 'files', 'post_select'];

    /**
     * Decode a raw stored value into its portable representation.
     *
     * @param array<string, mixed> $fieldConfig Field config (needs at least `type`; `multiple` for post_select, `fields` for repeater).
     * @param mixed                $raw         The raw value from `get_post_meta()` / `get_option()`.
     * @return mixed
     */
    public static function decode(array $fieldConfig, mixed $raw): mixed
    {
        $type = $fieldConfig['type'] ?? 'text';

        return match ($type) {
            'checkbox' => in_array($raw, ['1', 1, true], true),
            'image'    => (int) $raw,
            'number', 'range' => is_numeric($raw) ? $raw + 0 : $raw,
            'files'    => self::intList(self::decodeJsonArray($raw)),
            'post_select' => empty($fieldConfig['multiple'])
                ? ((int) $raw ?: null)
                : self::intList(self::decodeJsonArray($raw)),
            'repeater' => self::decodeRepeater($fieldConfig, $raw),
            default    => $raw === false ? '' : $raw,
        };
    }

    /**
     * @param mixed $raw
     * @return list<mixed>
     */
    private static function decodeJsonArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param array<string, mixed> $fieldConfig
     * @param mixed                $raw
     * @return list<array<string, mixed>>
     */
    private static function decodeRepeater(array $fieldConfig, mixed $raw): array
    {
        $rows = is_array($raw) ? $raw : json_decode(is_string($raw) ? $raw : '', true);

        if (!is_array($rows)) {
            return [];
        }

        $subFields = self::indexById($fieldConfig['fields'] ?? []);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decodedRow = [];
            foreach ($row as $key => $val) {
                $sub = $subFields[$key] ?? ['type' => 'text'];
                $decodedRow[$key] = self::decode($sub, $val);
            }
            $out[] = $decodedRow;
        }

        return $out;
    }

    /**
     * Every attachment ID referenced by a decoded field value — for the
     * exporter's `media[]` collection and the importer's reference rewrite.
     *
     * @param array<string, mixed> $fieldConfig
     * @param mixed                $decoded     A value already run through {@see self::decode()}.
     * @return list<int>
     */
    public static function referencedAttachmentIds(array $fieldConfig, mixed $decoded): array
    {
        $type = $fieldConfig['type'] ?? 'text';

        if ($type === 'image') {
            $id = (int) $decoded;
            return $id > 0 ? [$id] : [];
        }

        if ($type === 'files') {
            return self::intList(is_array($decoded) ? $decoded : []);
        }

        if ($type === 'repeater' && is_array($decoded)) {
            $subFields = self::indexById($fieldConfig['fields'] ?? []);
            $ids = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach ($row as $key => $val) {
                    $sub = $subFields[$key] ?? null;
                    if ($sub === null) {
                        continue;
                    }
                    $ids = array_merge($ids, self::referencedAttachmentIds($sub, $val));
                }
            }
            return array_values(array_unique($ids));
        }

        return [];
    }

    /**
     * Remap attachment IDs inside a decoded field value using an
     * `old id => new id` map. IDs absent from the map are left untouched.
     *
     * @param array<string, mixed> $fieldConfig
     * @param mixed                $decoded
     * @param array<int, int>      $idMap
     * @return mixed
     */
    public static function rewriteAttachmentIds(array $fieldConfig, mixed $decoded, array $idMap): mixed
    {
        $type = $fieldConfig['type'] ?? 'text';

        if ($type === 'image') {
            $id = (int) $decoded;
            return $idMap[$id] ?? $decoded;
        }

        if ($type === 'files' && is_array($decoded)) {
            return array_map(static fn ($v): int => $idMap[(int) $v] ?? (int) $v, self::intList($decoded));
        }

        if ($type === 'repeater' && is_array($decoded)) {
            $subFields = self::indexById($fieldConfig['fields'] ?? []);
            return array_map(static function ($row) use ($subFields, $idMap) {
                if (!is_array($row)) {
                    return $row;
                }
                foreach ($row as $key => $val) {
                    if (isset($subFields[$key])) {
                        $row[$key] = self::rewriteAttachmentIds($subFields[$key], $val, $idMap);
                    }
                }
                return $row;
            }, $decoded);
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function indexById(array $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            if (isset($field['id'])) {
                $map[(string) $field['id']] = $field;
            }
        }
        return $map;
    }

    /**
     * @param array<int, mixed> $values
     * @return list<int>
     */
    private static function intList(array $values): array
    {
        return array_values(array_filter(array_map('intval', $values), static fn (int $v): bool => $v > 0));
    }
}
