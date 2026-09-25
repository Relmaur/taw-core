<?php

declare(strict_types=1);

namespace TAW\Core\DataPanel;

use TAW\Core\Content\FieldCodec;
use TAW\Core\Metabox\Metabox;

// No ABSPATH guard: pure class. Stored values come in through a callable.

/**
 * Checks a REST save of a panel fieldset the way Metabox::save() checks a
 * form post (ADR-0007 § 5):
 *
 *   - readonly fields are never written: sending one is an error;
 *   - a field whose conditions aren't met is skipped, and its stored value is
 *     cleared after the save (Metabox::save() deletes it too);
 *   - required fields must not be empty (the request's value, or the stored
 *     one when the request doesn't send it);
 *   - `validate` callbacks run on the values the request sends.
 *
 * Conditions read the effective values: the request's, else the stored ones.
 */
final class Validation
{
    /**
     * @param array<string, mixed> $meta   The request's `meta` object.
     * @param array<string, mixed> $fields The request's top-level `taw_<id>` values that are present.
     * @param callable(string $kind, string $key): mixed $stored Current value: ('meta', key) or ('field', 'taw_<id>').
     * @return array{errors: list<array{field: string, message: string}>, clear: list<string>} `clear` = meta keys to delete.
     */
    public static function check(Metabox $box, array $meta, array $fields, callable $stored): array
    {
        $prefix = $box->prefix();
        $errors = [];
        $clear  = [];

        $present = static function (array $binding) use ($meta, $fields): bool {
            return isset($binding['meta']) ? array_key_exists($binding['meta'], $meta) : array_key_exists($binding['field'], $fields);
        };
        $value = static function (array $binding) use ($meta, $fields, $stored): mixed {
            if (isset($binding['meta'])) {
                return array_key_exists($binding['meta'], $meta) ? $meta[$binding['meta']] : $stored('meta', $binding['meta']);
            }

            return array_key_exists($binding['field'], $fields) ? $fields[$binding['field']] : $stored('field', $binding['field']);
        };

        // Effective values of top-level fields, by id, for conditions.
        $byId = [];
        foreach ($box->fields() as $field) {
            if (($field['type'] ?? 'text') !== 'group') {
                $byId[(string) $field['id']] = $value(self::binding($field, $prefix));
            }
        }

        foreach ($box->fields() as $field) {
            $label = (string) ($field['label'] ?? $field['id']);

            if (!empty($field['conditions']) && !self::conditionsMet((array) $field['conditions'], $byId)) {
                foreach (self::metaKeys($field, $prefix) as $key) {
                    $clear[] = $key;
                }
                continue;
            }

            $targets = ($field['type'] ?? 'text') === 'group'
                ? array_values(array_filter((array) ($field['fields'] ?? []), 'is_array'))
                : [$field];

            foreach ($targets as $target) {
                $binding = ($field['type'] ?? 'text') === 'group'
                    ? ['meta' => $prefix . $field['id'] . '_' . $target['id']]
                    : self::binding($target, $prefix);
                $name = ($field['type'] ?? 'text') === 'group' ? (string) ($target['label'] ?? $target['id']) : $label;

                if (!empty($target['readonly']) || !empty($field['readonly'])) {
                    if ($present($binding)) {
                        $errors[] = ['field' => self::key($binding), 'message' => sprintf('%s is read-only.', $name)];
                    }
                    continue;
                }

                $current = $value($binding);
                if (!empty($target['required']) && self::isEmpty($current)) {
                    $errors[] = ['field' => self::key($binding), 'message' => sprintf('%s is required.', $name)];
                    continue;
                }

                if ($present($binding) && isset($target['validate']) && is_callable($target['validate'])) {
                    $result = call_user_func($target['validate'], $current);
                    if ($result !== true) {
                        $errors[] = [
                            'field'   => self::key($binding),
                            'message' => is_string($result) ? $result : sprintf('%s is invalid.', $name),
                        ];
                    }
                }
            }
        }

        return ['errors' => $errors, 'clear' => array_values(array_unique($clear))];
    }

    /**
     * Where a top-level field's value lives on the REST post object.
     *
     * @param array<string, mixed> $field
     * @return array{meta: string}|array{field: string}
     */
    public static function binding(array $field, string $prefix): array
    {
        return in_array($field['type'] ?? 'text', FieldCodec::STRUCTURED_TYPES, true)
            ? ['field' => 'taw_' . $field['id']]
            : ['meta' => $prefix . $field['id']];
    }

    /**
     * Same operators and AND logic as Metabox::evaluate_conditions(), on
     * values normalised the way a form would post them.
     *
     * @param array<mixed>         $conditions
     * @param array<string, mixed> $values Effective values by field id.
     */
    public static function conditionsMet(array $conditions, array $values): bool
    {
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $actual   = self::asPosted($values[(string) ($condition['id'] ?? $condition['field'] ?? '')] ?? '');
            $expected = self::asPosted($condition['value'] ?? '');

            $met = match ($condition['operator'] ?? '==') {
                '!='     => $actual != $expected,
                'contains' => str_contains($actual, $expected),
                'empty'  => empty($actual),
                '!empty' => !empty($actual),
                default  => $actual == $expected,
            };

            if (!$met) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value as a form would have posted it: true → '1', false/null → ''.
     */
    private static function asPosted(mixed $value): string
    {
        return match (true) {
            $value === true               => '1',
            $value === false, $value === null => '',
            is_scalar($value)             => (string) $value,
            default                       => (string) json_encode($value),
        };
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === '' || $value === null || $value === [] || $value === false;
    }

    /**
     * @param array{meta: string}|array{field: string} $binding
     */
    private static function key(array $binding): string
    {
        return $binding['meta'] ?? $binding['field'];
    }

    /**
     * The meta keys a field (or a group's sub-fields) is stored under.
     *
     * @param array<string, mixed> $field
     * @return list<string>
     */
    private static function metaKeys(array $field, string $prefix): array
    {
        if (($field['type'] ?? 'text') !== 'group') {
            return [$prefix . $field['id']];
        }

        $keys = [];
        foreach ((array) ($field['fields'] ?? []) as $sub) {
            if (is_array($sub)) {
                $keys[] = $prefix . $field['id'] . '_' . $sub['id'];
            }
        }

        return $keys;
    }
}
