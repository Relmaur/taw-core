<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

// No ABSPATH guard: pure class (no WordPress calls), like Parser.

/**
 * The expression functions (ADR-0015): their signatures, which both parsers
 * check (resources/data-panel/src/bindings/expression.ts mirrors SIGNATURES),
 * and the pure implementations.
 *
 * A signature is its parameter kinds, receiver first, and how many are
 * required; a variadic function repeats its last kind. Kinds only constrain
 * literals: `string` must be quoted text, `int` a whole number above zero;
 * `any` takes anything. Every function is callable as `@fn(x, …)` and as a
 * method, `@x.fn(…)`, where x is the first parameter.
 */
final class Functions
{
    /** @var array<string, array{params: list<string>, required: int, variadic?: bool}> */
    public const SIGNATURES = [
        // Text (v1).
        'format'   => ['params' => ['any', 'string'], 'required' => 2],
        'upper'    => ['params' => ['any'], 'required' => 1],
        'lower'    => ['params' => ['any'], 'required' => 1],
        'default'  => ['params' => ['any', 'string'], 'required' => 2],
        'truncate' => ['params' => ['any', 'int'], 'required' => 2],
        // Logic.
        'if'       => ['params' => ['any', 'any', 'any'], 'required' => 2],
        'coalesce' => ['params' => ['any'], 'required' => 1, 'variadic' => true],
        'empty'    => ['params' => ['any'], 'required' => 1],
    ];

    /**
     * Whether a call is well formed: 'wrong_arguments' or null. $args are
     * parse-tree nodes (receiver included); only literals are kind-checked.
     *
     * @param array{params: list<string>, required: int, variadic?: bool} $signature
     * @param list<array<string, mixed>> $args
     */
    public static function check(array $signature, array $args): ?string
    {
        $params = $signature['params'];
        $count  = count($args);
        $max    = ($signature['variadic'] ?? false) ? PHP_INT_MAX : count($params);
        if ($count < $signature['required'] || $count > $max) {
            return 'wrong_arguments';
        }

        foreach ($args as $k => $arg) {
            $kind = $params[min($k, count($params) - 1)];
            $type = $arg['type'] ?? '';
            if ($kind === 'string' && in_array($type, ['number', 'bool'], true)) {
                return 'wrong_arguments';
            }
            if ($kind === 'int' && ($type === 'text' || $type === 'bool' || ($type === 'number' && (!is_int($arg['value']) || $arg['value'] < 1)))) {
                return 'wrong_arguments';
            }
        }

        return null;
    }

    /**
     * A function applied to evaluated arguments (receiver first). `if` and
     * `coalesce` are eager here; the evaluator runs them lazily in formulas.
     *
     * @param list<mixed> $args
     * @throws EvaluationError
     */
    public static function apply(string $fn, array $args): mixed
    {
        $value = $args[0] ?? '';

        return match ($fn) {
            'format'   => $value,
            'upper'    => mb_strtoupper(Value::text($value), 'UTF-8'),
            'lower'    => mb_strtolower(Value::text($value), 'UTF-8'),
            'default'  => Value::text($value) === '' ? ($args[1] ?? '') : $value,
            'truncate' => self::truncate(Value::text($value), $args[1] ?? 0),
            'if'       => Value::truthy($value) ? ($args[1] ?? '') : ($args[2] ?? ''),
            'coalesce' => self::coalesce($args),
            'empty'    => Value::isEmpty($value),
            default    => throw new EvaluationError('unknown_function'),
        };
    }

    private static function truncate(string $value, mixed $length): string
    {
        $length = Value::isNumeric($length) ? (int) Value::toNumber($length) : 0;
        if ($length < 1) {
            throw new EvaluationError('wrong_arguments');
        }

        return mb_strlen($value, 'UTF-8') <= $length ? $value : rtrim(mb_substr($value, 0, $length, 'UTF-8')) . '…';
    }

    /**
     * @param list<mixed> $args
     */
    private static function coalesce(array $args): mixed
    {
        foreach ($args as $arg) {
            if (!Value::isEmpty($arg)) {
                return $arg;
            }
        }

        return '';
    }
}
