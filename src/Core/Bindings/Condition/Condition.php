<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Condition;

use TAW\Core\Bindings\Expression\Parser;

// No ABSPATH guard: pure class (no WordPress calls), like Expression\Parser.

/**
 * A condition's shape (ADR-0013): validates and normalizes the JSON a chip,
 * binding or block stores.
 *
 *   {"match": "all", "rules": [
 *     {"value": "@book_year", "op": "not_empty"},
 *     {"match": "any", "rules": [{"value": "@genre", "op": "in", "to": ["fiction", "poetry"]}]}
 *   ]}
 *
 * - `match` is `all` (default) or `any`; a rule can be a group, one level deep.
 * - `value` is one expression token (`@name` plus calls).
 * - `to` depends on the operator: none, one operand, a list, or a pair. An
 *   operand is text, a number, a boolean, or another token (`"@x"`; `"@@x"`
 *   is the literal text `@x`).
 * - At most MAX_RULES rules in total.
 *
 * Errors carry a code and a dotted path (`rules.1.rules.0.op`). A condition
 * with any error is null: callers treat it as false.
 */
final class Condition
{
    public const MAX_RULES = 20;

    public const MAX_DEPTH = 2;

    /** Operator → what `to` takes: none, one, list or pair. */
    public const OPERATORS = [
        'empty'         => 'none',
        'not_empty'     => 'none',
        'is_true'       => 'none',
        'is_false'      => 'none',
        'equals'        => 'one',
        'not_equals'    => 'one',
        'contains'      => 'one',
        'not_contains'  => 'one',
        'starts_with'   => 'one',
        'ends_with'     => 'one',
        'has'           => 'one',
        'has_not'       => 'one',
        'gt'            => 'one',
        'gte'           => 'one',
        'lt'            => 'one',
        'lte'           => 'one',
        'before'        => 'one',
        'after'         => 'one',
        'on'            => 'one',
        'in'            => 'list',
        'not_in'        => 'list',
        'between'       => 'pair',
        'between_dates' => 'pair',
    ];

    /** Operators that compare dates: their values are read as dates. */
    public const DATE_OPERATORS = ['before', 'after', 'on', 'between_dates'];

    /** Items in an `in` list. */
    public const MAX_LIST = 50;

    /**
     * @return array{condition: array<string, mixed>|null, errors: list<array{code: string, path: string}>}
     */
    public static function normalize(mixed $condition): array
    {
        $errors = [];
        $count  = 0;
        $group  = self::group($condition, '', 1, $count, $errors);
        if ($count > self::MAX_RULES) {
            $errors[] = ['code' => 'too_many_rules', 'path' => 'rules'];
        }

        return ['condition' => $errors === [] ? $group : null, 'errors' => $errors];
    }

    /** Whether a string is a token operand (`@x`), not text (`@@x` is text). */
    public static function isToken(string $operand): bool
    {
        return str_starts_with($operand, '@') && !str_starts_with($operand, '@@');
    }

    /** A single, valid expression token: `@name` and calls, nothing else. */
    public static function isValidToken(string $token): bool
    {
        if (!str_starts_with($token, '@')) {
            return false;
        }
        $parsed = Parser::parse($token);
        $parts  = $parsed['parts'];

        return $parsed['errors'] === [] && count($parts) === 1 && isset($parts[0]['name']) && !isset($parts[0]['error']);
    }

    /**
     * @param list<array{code: string, path: string}> $errors
     * @return array<string, mixed>|null
     */
    private static function group(mixed $group, string $path, int $depth, int &$count, array &$errors): ?array
    {
        if (!is_array($group) || !is_array($group['rules'] ?? null) || !array_is_list($group['rules'])) {
            $errors[] = ['code' => 'invalid', 'path' => self::join($path, 'rules')];
            return null;
        }
        $match = $group['match'] ?? 'all';
        if (!in_array($match, ['all', 'any'], true)) {
            $errors[] = ['code' => 'bad_match', 'path' => self::join($path, 'match')];
            $match = 'all';
        }

        $rules = [];
        foreach ($group['rules'] as $i => $rule) {
            $rulePath = self::join($path, "rules.{$i}");
            if (is_array($rule) && array_key_exists('rules', $rule)) {
                if ($depth >= self::MAX_DEPTH) {
                    $errors[] = ['code' => 'too_deep', 'path' => $rulePath];
                    continue;
                }
                $rules[] = self::group($rule, $rulePath, $depth + 1, $count, $errors);
                continue;
            }
            $count++;
            $rules[] = self::rule($rule, $rulePath, $errors);
        }

        return ['match' => $match, 'rules' => $rules];
    }

    /**
     * @param list<array{code: string, path: string}> $errors
     * @return array<string, mixed>|null
     */
    private static function rule(mixed $rule, string $path, array &$errors): ?array
    {
        if (!is_array($rule)) {
            $errors[] = ['code' => 'invalid', 'path' => $path];
            return null;
        }

        $value = $rule['value'] ?? null;
        if (!is_string($value) || !self::isValidToken(trim($value))) {
            $errors[] = ['code' => 'invalid_value', 'path' => "{$path}.value"];
        }

        $op = $rule['op'] ?? null;
        if (!is_string($op) || !isset(self::OPERATORS[$op])) {
            $errors[] = ['code' => 'unknown_op', 'path' => "{$path}.op"];
            return null;
        }

        $normalized = ['value' => is_string($value) ? trim($value) : '', 'op' => $op];
        $to = self::to(self::OPERATORS[$op], $rule['to'] ?? null);
        if ($to === false) {
            $errors[] = ['code' => 'invalid_to', 'path' => "{$path}.to"];
        } elseif ($to !== null) {
            $normalized['to'] = $to;
        }

        return $normalized;
    }

    /**
     * `to` for an operator's arity: null (none), a string, or a list of
     * strings; false when it doesn't fit.
     *
     * @return string|list<string>|false|null
     */
    private static function to(string $arity, mixed $to): string|array|false|null
    {
        if ($arity === 'none') {
            return null;
        }
        if ($arity === 'one') {
            return self::operand($to) ?? false;
        }

        // A list may also be written as comma-separated text: "fiction, poetry".
        if ($arity === 'list' && is_string($to) && !self::isToken($to)) {
            $to = array_values(array_filter(array_map('trim', explode(',', $to)), static fn (string $s): bool => $s !== ''));
        }
        if (!is_array($to) || !array_is_list($to) || $to === []) {
            return false;
        }
        if (($arity === 'pair' && count($to) !== 2) || count($to) > self::MAX_LIST) {
            return false;
        }

        $operands = [];
        foreach ($to as $item) {
            $operand = self::operand($item);
            if ($operand === null) {
                return false;
            }
            $operands[] = $operand;
        }

        return $operands;
    }

    /** An operand as a string; null when it isn't one. */
    private static function operand(mixed $operand): ?string
    {
        if (is_bool($operand)) {
            return $operand ? '1' : '0';
        }
        if (is_int($operand) || is_float($operand)) {
            return (string) $operand;
        }
        if (!is_string($operand)) {
            return null;
        }

        return self::isToken($operand) && !self::isValidToken(trim($operand)) ? null : $operand;
    }

    private static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }
}
