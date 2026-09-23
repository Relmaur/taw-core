<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

use TAW\Core\Editing\Rules;
use TAW\Core\Schema\Definition\EditingPolicy;

// No ABSPATH guard: `bin/taw schema:validate` runs this before (and without)
// WordPress.

/**
 * Validates one decoded JSON schema definition (format version 1).
 *
 * Hand-written on purpose (ADR-0004 § 4): a JSON Schema library would be a
 * third-party dependency in the one layer TAW exists to own. The same rules
 * are also published as resources/schema/taw-schema-1.0.json so editors can
 * autocomplete and flag mistakes while typing; a unit test keeps the two in
 * sync.
 *
 * The format mirrors the PHP API, so there is one mental model:
 *
 *   {"version": 1, "kind": "post_type",    "key": "book",  "labels": {...}, "args": {...}}
 *   {"version": 1, "kind": "taxonomy",     "key": "genre", "for": ["book"], "labels": {...}, "args": {...}}
 *   {"version": 1, "kind": "fieldset",     "key": "book_details", "on": ["book"], "fields": [...]}
 *   {"version": 1, "kind": "options_page", "key": "site", "fields": [...]}
 *
 * Every error names the JSON pointer it's about ("/fields/2/type"), so a
 * developer can find the problem in a long file.
 */
final class Validator
{
    public const VERSION = 1;

    public const KINDS = ['post_type', 'taxonomy', 'fieldset', 'options_page', 'editing'];

    /** Field types — exactly those the Metabox engine renders. */
    public const FIELD_TYPES = Field::TYPES;

    /**
     * Top-level keys each kind accepts. Anything else is an error: a typo like
     * "feilds" should fail loudly, not silently produce an empty fieldset.
     */
    private const ALLOWED_KEYS = [
        'post_type'    => ['labels', 'args', 'editing'],
        'taxonomy'     => ['for', 'labels', 'args'],
        'fieldset'     => ['title', 'on', 'fields', 'context', 'priority', 'prefix', 'config'],
        'options_page' => ['title', 'menu_title', 'capability', 'fields', 'config'],
        'editing'      => Rules::POLICY_KEYS,
    ];

    private const COMMON_KEYS = ['$schema', 'version', 'kind', 'key', 'override'];

    /** Field keys with a known type; any other field key passes through as-is. */
    private const FIELD_STRING_KEYS = ['label', 'description', 'placeholder'];
    private const FIELD_BOOL_KEYS = ['required', 'readonly'];
    private const FIELD_NUMBER_KEYS = ['min', 'max', 'step'];

    /**
     * @return list<string> Errors as "<json pointer>: <message>". Empty when valid.
     */
    public static function validate(mixed $data): array
    {
        if (!is_array($data) || array_is_list($data)) {
            return ['/: a definition must be a JSON object'];
        }

        $errors = [];

        if (($data['version'] ?? null) !== self::VERSION) {
            $errors[] = '/version: must be ' . self::VERSION;
        }

        $kind = $data['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            $errors[] = '/kind: must be one of ' . implode(', ', self::KINDS);

            return $errors; // everything below depends on the kind
        }

        if (!is_string($data['key'] ?? null) || $data['key'] === '') {
            $errors[] = '/key: must be a non-empty string';
        } elseif ($kind === 'editing' && $data['key'] !== EditingPolicy::KEY) {
            $errors[] = '/key: must be "site" (a site has one editing policy)';
        }

        if (array_key_exists('override', $data) && !is_bool($data['override'])) {
            $errors[] = '/override: must be true or false';
        }

        foreach (array_keys($data) as $name) {
            if (!in_array($name, self::COMMON_KEYS, true) && !in_array($name, self::ALLOWED_KEYS[$kind], true)) {
                $article = in_array($kind[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
                $errors[] = sprintf('/%s: unknown key for %s %s (allowed: %s)', $name, $article, $kind, implode(', ', self::ALLOWED_KEYS[$kind]));
            }
        }

        foreach (['args', 'config'] as $objectKey) {
            if (array_key_exists($objectKey, $data) && !self::isObject($data[$objectKey])) {
                $errors[] = "/{$objectKey}: must be an object";
            }
        }

        foreach (['title', 'menu_title', 'capability', 'prefix'] as $stringKey) {
            if (array_key_exists($stringKey, $data) && !is_string($data[$stringKey])) {
                $errors[] = "/{$stringKey}: must be a string";
            }
        }

        if (array_key_exists('labels', $data)) {
            $errors = [...$errors, ...self::validateLabels($data['labels'])];
        }

        return match ($kind) {
            'post_type'    => array_key_exists('editing', $data)
                ? [...$errors, ...Rules::validateContentRule($data['editing'], '/editing')]
                : $errors,
            'taxonomy'     => [...$errors, ...self::validateStringList($data, 'for')],
            'fieldset'     => [
                ...$errors,
                ...self::validateStringList($data, 'on'),
                ...self::validateEnum($data, 'context', ['normal', 'side', 'advanced']),
                ...self::validateEnum($data, 'priority', ['high', 'default', 'low']),
                ...self::validateFieldList($data['fields'] ?? null, '/fields'),
            ],
            'options_page' => [...$errors, ...self::validateFieldList($data['fields'] ?? null, '/fields')],
            'editing'      => [...$errors, ...Rules::validatePolicy($data)],
        };
    }

    /**
     * @return list<string>
     */
    private static function validateLabels(mixed $labels): array
    {
        if (!self::isObject($labels)) {
            return ['/labels: must be an object with "singular" and "plural"'];
        }

        $errors = [];
        foreach (['singular', 'plural'] as $name) {
            if (!is_string($labels[$name] ?? null) || $labels[$name] === '') {
                $errors[] = "/labels/{$name}: must be a non-empty string";
            }
        }

        return $errors;
    }

    /**
     * A required, non-empty list of strings (fieldset "on", taxonomy "for").
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function validateStringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            return ["/{$key}: must be a non-empty list of strings"];
        }

        $errors = [];
        foreach ($value as $i => $item) {
            if (!is_string($item) || $item === '') {
                $errors[] = "/{$key}/{$i}: must be a non-empty string";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function validateEnum(array $data, string $key, array $allowed): array
    {
        if (!array_key_exists($key, $data) || in_array($data[$key], $allowed, true)) {
            return [];
        }

        return ["/{$key}: must be one of " . implode(', ', $allowed)];
    }

    /**
     * @return list<string>
     */
    private static function validateFieldList(mixed $fields, string $pointer): array
    {
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            return ["{$pointer}: must be a non-empty list of fields"];
        }

        $errors = [];
        $seen = [];

        foreach ($fields as $i => $field) {
            $at = "{$pointer}/{$i}";

            if (!self::isObject($field)) {
                $errors[] = "{$at}: a field must be an object";
                continue;
            }

            $id = $field['id'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
                $errors[] = "{$at}/id: must use letters, numbers, underscores and dashes only";
            } elseif (isset($seen[$id])) {
                $errors[] = "{$at}/id: \"{$id}\" is used twice in this list";
            } else {
                $seen[$id] = true;
            }

            $type = $field['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::FIELD_TYPES, true)) {
                $errors[] = "{$at}/type: must be one of " . implode(', ', self::FIELD_TYPES);
                continue;
            }

            foreach (self::FIELD_STRING_KEYS as $key) {
                if (array_key_exists($key, $field) && !is_string($field[$key])) {
                    $errors[] = "{$at}/{$key}: must be a string";
                }
            }
            foreach (self::FIELD_BOOL_KEYS as $key) {
                if (array_key_exists($key, $field) && !is_bool($field[$key])) {
                    $errors[] = "{$at}/{$key}: must be true or false";
                }
            }
            foreach (self::FIELD_NUMBER_KEYS as $key) {
                if (array_key_exists($key, $field) && !is_int($field[$key]) && !is_float($field[$key])) {
                    $errors[] = "{$at}/{$key}: must be a number";
                }
            }
            if (array_key_exists('options', $field) && !self::isObject($field['options'])) {
                $errors[] = "{$at}/options: must be an object of value → label";
            }

            // Nested fields: required for containers, meaningless elsewhere.
            if (in_array($type, ['group', 'repeater'], true)) {
                $errors = [...$errors, ...self::validateFieldList($field['fields'] ?? null, "{$at}/fields")];
            } elseif (array_key_exists('fields', $field)) {
                $errors[] = "{$at}/fields: only group and repeater fields hold nested fields";
            }
        }

        return $errors;
    }

    /**
     * A decoded JSON object: an associative array, or an empty array (which
     * is how json_decode(..., true) represents "{}").
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
