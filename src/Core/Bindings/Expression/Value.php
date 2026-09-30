<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

// No ABSPATH guard: pure class (no WordPress calls), like Parser.

/**
 * Typed expression values (ADR-0015): text (string), numbers (int|float),
 * booleans and lists (list<mixed>). Conversions happen at the edges: text
 * that reads as a number is a number in arithmetic and comparisons, and any
 * value shown as text goes through text().
 */
final class Value
{
    /** How a value reads as text: lists joined with ", ", true as "1", false as "". */
    public static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value)   => $value ? '1' : '',
            is_int($value)    => (string) $value,
            is_float($value)  => self::number($value),
            is_array($value)  => implode(', ', array_filter(array_map(self::text(...), $value), static fn (string $t): bool => $t !== '')),
            default           => '',
        };
    }

    /** Empty: '', null, an empty list (0 and false are values). */
    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
    }

    /** Truthy as in conditions: false, '', '0', 0, 0.0 and [] are false. */
    public static function truthy(mixed $value): bool
    {
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' && $value !== '0';
        }

        return (bool) $value;
    }

    /**
     * A value as a list: a list as is, '' as none, text as its comma-separated
     * items ("a, b"), anything else as one item.
     *
     * @return list<mixed>
     */
    public static function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (self::isEmpty($value)) {
            return [];
        }
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
        }

        return [$value];
    }

    public static function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
    }

    /**
     * @throws EvaluationError not_a_number
     */
    public static function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        throw new EvaluationError('not_a_number');
    }

    /** A float as short text: 116.58, not 116.58000000000001; 2.0 as "2". */
    private static function number(float $value): string
    {
        if (!is_finite($value)) {
            return '';
        }
        $rounded = round($value, 10);
        if ($rounded == floor($rounded) && abs($rounded) < 1e15) {
            return (string) (int) $rounded;
        }

        return rtrim(rtrim(sprintf('%.10F', $rounded), '0'), '.');
    }
}
