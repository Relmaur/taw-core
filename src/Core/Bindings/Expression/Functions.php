<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

use TAW\Core\Log\Logger;

// No ABSPATH guard: pure class (WordPress is only called when a function runs), like Parser.

/**
 * The expression functions (ADR-0015): their signatures, which both parsers
 * check (resources/data-panel/src/bindings/expression.ts mirrors SIGNATURES),
 * the pure implementations, and the site's own functions from the
 * `taw_expression_functions` filter.
 *
 * A signature is its parameter kinds, receiver first, and how many are
 * required; a variadic function repeats its last kind. Kinds:
 * - `any`: anything;
 * - `string`: a literal must be quoted text;
 * - `int`: a literal must be a whole number above zero;
 * - `list`: a value is read as a list (a repeater's rows, checked options, related posts…);
 * - `date`: a value is read as a date (Y-m-d H:i:s), whatever its display format.
 * Every function is callable as `@fn(x, …)` and as a method, `@x.fn(…)`.
 */
final class Functions
{
    public const KINDS = ['any', 'string', 'int', 'list', 'date'];

    /** Kinds a custom function may declare → the kind the parsers check. */
    public const CUSTOM_KINDS = ['any' => 'any', 'text' => 'any', 'number' => 'any', 'int' => 'int', 'list' => 'list', 'date' => 'date'];

    /** @var array<string, array{params: list<string>, required: int, variadic?: bool}> */
    public const SIGNATURES = [
        // Text.
        'format'       => ['params' => ['date', 'string'], 'required' => 2],
        'upper'        => ['params' => ['any'], 'required' => 1],
        'lower'        => ['params' => ['any'], 'required' => 1],
        'capitalize'   => ['params' => ['any'], 'required' => 1],
        'default'      => ['params' => ['any', 'string'], 'required' => 2],
        'truncate'     => ['params' => ['any', 'int'], 'required' => 2],
        'words'        => ['params' => ['any', 'int'], 'required' => 2],
        'word_count'   => ['params' => ['any'], 'required' => 1],
        'replace'      => ['params' => ['any', 'any', 'any'], 'required' => 3],
        'strip'        => ['params' => ['any'], 'required' => 1],
        'slug'         => ['params' => ['any'], 'required' => 1],
        'urlencode'    => ['params' => ['any'], 'required' => 1],
        'trim'         => ['params' => ['any'], 'required' => 1],
        'plural'       => ['params' => ['any', 'any', 'any'], 'required' => 3],
        'concat'       => ['params' => ['any'], 'required' => 1, 'variadic' => true],
        // Logic.
        'if'           => ['params' => ['any', 'any', 'any'], 'required' => 2],
        'coalesce'     => ['params' => ['any'], 'required' => 1, 'variadic' => true],
        'empty'        => ['params' => ['any'], 'required' => 1],
        // Numbers.
        'round'        => ['params' => ['any', 'any'], 'required' => 1],
        'floor'        => ['params' => ['any'], 'required' => 1],
        'ceil'         => ['params' => ['any'], 'required' => 1],
        'abs'          => ['params' => ['any'], 'required' => 1],
        'min'          => ['params' => ['list'], 'required' => 1, 'variadic' => true],
        'max'          => ['params' => ['list'], 'required' => 1, 'variadic' => true],
        'number'       => ['params' => ['any', 'any'], 'required' => 1],
        'currency'     => ['params' => ['any', 'string'], 'required' => 1],
        'percent'      => ['params' => ['any', 'any'], 'required' => 1],
        // Dates.
        'ago'          => ['params' => ['date'], 'required' => 1],
        'until'        => ['params' => ['date'], 'required' => 1],
        'days_between' => ['params' => ['date', 'date'], 'required' => 2],
        'add_days'     => ['params' => ['date', 'any'], 'required' => 2],
        'year'         => ['params' => ['date'], 'required' => 1],
        'month'        => ['params' => ['date'], 'required' => 1],
        'day'          => ['params' => ['date'], 'required' => 1],
        'weekday'      => ['params' => ['date'], 'required' => 1],
        // Lists.
        'terms'        => ['params' => ['string'], 'required' => 1],
        'column'       => ['params' => ['list', 'any'], 'required' => 2],
        'count'        => ['params' => ['list'], 'required' => 1],
        'join'         => ['params' => ['list', 'any'], 'required' => 1],
        'first'        => ['params' => ['list'], 'required' => 1],
        'last'         => ['params' => ['list'], 'required' => 1],
        'sort'         => ['params' => ['list'], 'required' => 1],
        'reverse'      => ['params' => ['list'], 'required' => 1],
        'contains'     => ['params' => ['list', 'any'], 'required' => 2],
        'sum'          => ['params' => ['list'], 'required' => 1],
        'avg'          => ['params' => ['list'], 'required' => 1],
    ];

    /** @var array<string, array{signature: array{params: list<string>, required: int, variadic?: bool}, kinds: list<string>, callback: callable, label: string, description: string}>|null */
    private static ?array $custom = null;

    /**
     * Every known function: the built-ins, then the site's own.
     *
     * @return array<string, array{params: list<string>, required: int, variadic?: bool}>
     */
    public static function all(): array
    {
        $all = self::SIGNATURES;
        foreach (self::custom() as $name => $function) {
            $all[$name] = $function['signature'];
        }

        return $all;
    }

    /**
     * The site's own functions, for the editor (no callbacks).
     *
     * @return array<string, array{params: list<string>, required: int, variadic?: bool, label: string, description: string}>
     */
    public static function forEditor(): array
    {
        $out = [];
        foreach (self::custom() as $name => $function) {
            $out[$name] = $function['signature'] + ['label' => $function['label'], 'description' => $function['description']];
        }

        return $out;
    }

    public static function isCustom(string $fn): bool
    {
        return isset(self::custom()[$fn]);
    }

    /** Forget the site's functions (tests; the filter is read once per request). */
    public static function reset(): void
    {
        self::$custom = null;
    }

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
            $kind = self::kindAt($signature, $k);
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
     * The kind of the parameter at $k.
     *
     * @param array{params: list<string>, required: int, variadic?: bool} $signature
     */
    public static function kindAt(array $signature, int $k): string
    {
        $params = $signature['params'];

        return $params[min($k, count($params) - 1)] ?? 'any';
    }

    /**
     * A function applied to evaluated arguments (receiver first). `if` and
     * `coalesce` are eager here; the evaluator runs them lazily in formulas.
     * `terms` needs the post, so the evaluator runs it.
     *
     * @param list<mixed> $args
     * @throws EvaluationError
     */
    public static function apply(string $fn, array $args): mixed
    {
        if (self::isCustom($fn)) {
            return self::callCustom($fn, $args);
        }
        if (!isset(self::SIGNATURES[$fn])) {
            throw new EvaluationError('unknown_function');
        }

        $value = $args[0] ?? '';
        $text  = static fn (int $k): string => Value::text($args[$k] ?? '');

        return match ($fn) {
            // Text.
            'upper'      => mb_strtoupper($text(0), 'UTF-8'),
            'lower'      => mb_strtolower($text(0), 'UTF-8'),
            'capitalize' => ($t = $text(0)) === '' ? '' : mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8'),
            'default'    => Value::text($value) === '' ? ($args[1] ?? '') : $value,
            'truncate'   => self::truncate($text(0), $args[1] ?? 0),
            'words'      => self::words($text(0), $args[1] ?? 0),
            'word_count' => count(self::split($text(0))),
            'replace'    => $text(1) === '' ? $text(0) : str_replace($text(1), $text(2), $text(0)),
            'urlencode'  => rawurlencode($text(0)),
            'trim'       => trim($text(0)),
            'plural'     => self::plural($value, $text(1), $text(2)),
            'concat'     => implode('', array_map(Value::text(...), $args)),
            // Logic.
            'if'         => Value::truthy($value) ? ($args[1] ?? '') : ($args[2] ?? ''),
            'coalesce'   => self::coalesce($args),
            'empty'      => Value::isEmpty($value),
            // Numbers.
            'round'      => Value::isEmpty($value) ? '' : round(Value::toNumber($value), self::digits($args[1] ?? 0)),
            'floor'      => Value::isEmpty($value) ? '' : floor(Value::toNumber($value)),
            'ceil'       => Value::isEmpty($value) ? '' : ceil(Value::toNumber($value)),
            'abs'        => Value::isEmpty($value) ? '' : abs(Value::toNumber($value)),
            'min', 'max' => self::extreme($fn, $args),
            // Lists.
            'column'     => array_map(static fn ($row): mixed => is_array($row) ? ($row[Value::text($args[1] ?? '')] ?? '') : '', Value::toList($value)),
            'count'      => count(Value::toList($value)),
            'join'       => implode(count($args) > 1 ? $text(1) : ', ', array_map(Value::text(...), Value::toList($value))),
            'first'      => Value::toList($value)[0] ?? '',
            'last'       => ($list = Value::toList($value)) === [] ? '' : $list[count($list) - 1],
            'sort'       => self::sorted(Value::toList($value)),
            'reverse'    => array_reverse(Value::toList($value)),
            'contains'   => in_array(mb_strtolower(trim($text(1)), 'UTF-8'), array_map(static fn ($item): string => mb_strtolower(trim(Value::text($item)), 'UTF-8'), Value::toList($value)), true),
            'sum'        => array_sum(array_map(Value::toNumber(...), self::present(Value::toList($value)))),
            'avg'        => ($items = self::present(Value::toList($value))) === [] ? '' : array_sum(array_map(Value::toNumber(...), $items)) / count($items),
            // Dates, money and anything else that needs WordPress.
            default      => Library::apply($fn, $args),
        };
    }

    /**
     * The site's own functions, read once from `taw_expression_functions`:
     *
     *   $functions['with_tax'] = [
     *       'args'     => ['number'],                        // kinds: any, text, number, int, list, date
     *       'callback' => fn (float $price): float => $price * 1.16,
     *       'label'    => 'Price with tax', 'description' => 'Adds 16% VAT.',
     *   ];
     *
     * Optional: `required` (default: every argument), `variadic`. A name must be
     * an identifier and can't replace a built-in; invalid entries are skipped
     * with `_doing_it_wrong()`.
     *
     * @return array<string, array{signature: array{params: list<string>, required: int, variadic?: bool}, kinds: list<string>, callback: callable, label: string, description: string}>
     */
    private static function custom(): array
    {
        if (self::$custom !== null) {
            return self::$custom;
        }
        if (!function_exists('apply_filters')) {
            return [];
        }

        self::$custom = [];
        $declared = apply_filters('taw_expression_functions', []);
        foreach (is_array($declared) ? $declared : [] as $name => $function) {
            $problem = self::invalid($name, $function);
            if ($problem !== null) {
                if (function_exists('_doing_it_wrong')) {
                    _doing_it_wrong('taw_expression_functions', esc_html(sprintf('%s: %s', (string) $name, $problem)), '1.71.0');
                }
                continue;
            }
            $callback = is_array($function) ? $function['callback'] : null;
            if (!is_callable($callback) || !is_string($name)) {
                continue;
            }
            $kinds  = array_values(array_filter((array) ($function['args'] ?? []), 'is_string'));
            $params = array_map(static fn (string $kind): string => self::CUSTOM_KINDS[$kind] ?? 'any', $kinds);
            $signature = ['params' => $params === [] ? ['any'] : $params, 'required' => (int) ($function['required'] ?? count($kinds))];
            if (($function['variadic'] ?? false) === true) {
                $signature['variadic'] = true;
            }
            self::$custom[$name] = [
                'signature'   => $signature,
                'kinds'       => $kinds,
                'callback'    => $callback,
                'label'       => (string) ($function['label'] ?? $name),
                'description' => (string) ($function['description'] ?? ''),
            ];
        }

        return self::$custom;
    }

    private static function invalid(mixed $name, mixed $function): ?string
    {
        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            return 'the name must be an identifier';
        }
        if (isset(self::SIGNATURES[$name]) || in_array($name, ['true', 'false', 'and', 'or', 'not'], true)) {
            return 'a built-in function or keyword has this name';
        }
        if (!is_array($function) || !is_callable($function['callback'] ?? null)) {
            return 'the callback must be callable';
        }
        $args = $function['args'] ?? [];
        if (!is_array($args) || !array_is_list($args) || array_diff($args, array_keys(self::CUSTOM_KINDS)) !== []) {
            return 'args must be a list of any, text, number, int, list or date';
        }
        $required = $function['required'] ?? count($args);
        if (!is_int($required) || $required < 0 || $required > max(1, count($args))) {
            return 'required must be between 0 and the number of args';
        }

        return null;
    }

    /**
     * A site function, its arguments converted to their declared kinds. A
     * callback that throws gives an empty value and logs expression.function_failed.
     *
     * @param list<mixed> $args
     * @throws EvaluationError
     */
    private static function callCustom(string $fn, array $args): mixed
    {
        $function = self::custom()[$fn];
        $kinds    = $function['kinds'];
        $values   = [];
        foreach ($args as $k => $arg) {
            $kind = $kinds[min($k, max(0, count($kinds) - 1))] ?? 'any';
            $values[] = match ($kind) {
                'number' => Value::isEmpty($arg) ? 0 : Value::toNumber($arg),
                'int'    => Value::isEmpty($arg) ? 0 : (int) Value::toNumber($arg),
                'text'   => Value::text($arg),
                'list'   => Value::toList($arg),
                default  => $arg,
            };
        }

        try {
            $result = ($function['callback'])(...$values);
        } catch (\Throwable $e) {
            if (class_exists(Logger::class) && function_exists('do_action')) {
                Logger::warning('expression.function_failed', sprintf('The expression function %s() failed: %s', $fn, $e->getMessage()), ['function' => $fn]);
            }
            return '';
        }

        return is_scalar($result) || is_array($result) || $result === null ? ($result ?? '') : (is_object($result) && method_exists($result, '__toString') ? (string) $result : '');
    }

    private static function truncate(string $value, mixed $length): string
    {
        $length = self::positive($length);

        return mb_strlen($value, 'UTF-8') <= $length ? $value : rtrim(mb_substr($value, 0, $length, 'UTF-8')) . '…';
    }

    private static function words(string $value, mixed $count): string
    {
        $count = self::positive($count);
        $words = self::split($value);

        return count($words) <= $count ? trim($value) : implode(' ', array_slice($words, 0, $count)) . '…';
    }

    /** @return list<string> */
    private static function split(string $value): array
    {
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /** @throws EvaluationError */
    private static function positive(mixed $value): int
    {
        $n = Value::isNumeric($value) ? (int) Value::toNumber($value) : 0;
        if ($n < 1) {
            throw new EvaluationError('wrong_arguments');
        }

        return $n;
    }

    /** @throws EvaluationError */
    private static function digits(mixed $value): int
    {
        return Value::isEmpty($value) ? 0 : max(0, min(10, (int) Value::toNumber($value)));
    }

    /** "1 award", "3 awards": the number, then the word that fits it. */
    private static function plural(mixed $count, string $one, string $many): string
    {
        if (Value::isEmpty($count)) {
            return '';
        }
        $n = Value::toNumber($count);

        return Value::text($n) . ' ' . ($n == 1 ? $one : $many);
    }

    /**
     * @param list<mixed> $args
     * @throws EvaluationError
     */
    private static function extreme(string $fn, array $args): mixed
    {
        $items = [];
        foreach ($args as $arg) {
            array_push($items, ...self::present(Value::toList($arg)));
        }
        if ($items === []) {
            return '';
        }
        $numbers = array_map(Value::toNumber(...), $items);

        return $fn === 'min' ? min($numbers) : max($numbers);
    }

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    private static function present(array $items): array
    {
        return array_values(array_filter($items, static fn ($item): bool => !Value::isEmpty($item)));
    }

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    private static function sorted(array $items): array
    {
        usort($items, static fn ($a, $b): int => Value::isNumeric($a) && Value::isNumeric($b)
            ? Value::toNumber($a) <=> Value::toNumber($b)
            : strnatcasecmp(Value::text($a), Value::text($b)));

        return $items;
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
