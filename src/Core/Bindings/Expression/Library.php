<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Expression;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The expression functions that need WordPress (ADR-0015): dates in the site's
 * time zone and language, numbers and money in its locale, slugs and HTML
 * stripping. Functions::apply() hands them over.
 */
final class Library
{
    /** Currency code → [symbol, decimals]. Others show as "1,234.50 XYZ". */
    public const CURRENCIES = [
        'USD' => ['$', 2], 'MXN' => ['$', 2], 'CAD' => ['$', 2], 'AUD' => ['$', 2], 'EUR' => ['€', 2],
        'GBP' => ['£', 2], 'JPY' => ['¥', 0], 'CNY' => ['¥', 2], 'INR' => ['₹', 2], 'BRL' => ['R$', 2],
        'ARS' => ['$', 2], 'CLP' => ['$', 0], 'COP' => ['$', 0], 'PEN' => ['S/', 2], 'CHF' => ['CHF ', 2],
    ];

    /**
     * @param list<mixed> $args
     * @throws EvaluationError
     */
    public static function apply(string $fn, array $args): mixed
    {
        $value = $args[0] ?? '';
        if (Value::isEmpty($value) && $fn !== 'strip' && $fn !== 'slug') {
            return '';
        }

        return match ($fn) {
            'format'       => wp_date(Value::text($args[1] ?? ''), self::time($value)),
            'ago'          => self::isYear($value) ? self::yearsAgo((int) Value::toNumber($value)) : self::relative(self::time($value)),
            'until'        => ($t = self::time($value)) > self::now() ? human_time_diff(self::now(), $t) : '',
            'days_between' => Value::isEmpty($args[1] ?? '') ? '' : (int) floor((self::time($args[1]) - self::time($value)) / DAY_IN_SECONDS),
            'add_days'     => self::date(self::time($value))->modify(sprintf('%+d days', (int) Value::toNumber($args[1] ?? 0)))->format('Y-m-d'),
            'year'         => (int) wp_date('Y', self::time($value)),
            'month'        => wp_date('F', self::time($value)),
            'day'          => (int) wp_date('j', self::time($value)),
            'weekday'      => wp_date('l', self::time($value)),
            'number'       => number_format_i18n((float) Value::toNumber($value), self::decimals($value, $args[1] ?? null)),
            'currency'     => self::currency(Value::toNumber($value), Value::text($args[1] ?? '')),
            'percent'      => number_format_i18n((float) Value::toNumber($value) * 100, self::decimals(0, $args[1] ?? 0)) . '%',
            'strip'        => trim(wp_strip_all_tags(Value::text($value))),
            'slug'         => sanitize_title(Value::text($value)),
            default        => throw new EvaluationError('unknown_function'),
        };
    }

    /**
     * A date value as a timestamp: a year (1965), a timestamp, or a date the
     * site's time zone reads ("2026-09-30", "2026-09-30 14:00:00").
     *
     * @throws EvaluationError not_a_date
     */
    public static function time(mixed $value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1)) {
            $n = (int) Value::toNumber($value);
            return $n >= 1000 && $n <= 9999 ? self::date('@0')->setDate($n, 1, 1)->setTime(0, 0)->getTimestamp() : $n;
        }

        try {
            return self::date(Value::text($value))->getTimestamp();
        } catch (\Exception) {
            throw new EvaluationError('not_a_date');
        }
    }

    private static function now(): int
    {
        return current_datetime()->getTimestamp();
    }

    private static function date(string|int $value): \DateTimeImmutable
    {
        $date = is_int($value) ? new \DateTimeImmutable('@' . $value) : new \DateTimeImmutable($value, wp_timezone());

        return $date->setTimezone(wp_timezone());
    }

    /** A 4-digit number: a year, not a timestamp. */
    private static function isYear(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && preg_match('/^\s*\d{4}\s*$/', $value) === 1)) && (int) Value::toNumber($value) >= 1000;
    }

    /** A year counts calendar years: 1994 in 2026 is "32 years ago" (not 32.75, rounded). */
    private static function yearsAgo(int $year): string
    {
        $years = (int) wp_date('Y', self::now()) - $year;
        if ($years === 0) {
            return __('this year', 'taw-core');
        }

        return $years > 0
            /* translators: %s: a number of years */
            ? sprintf(_n('%s year ago', '%s years ago', $years, 'taw-core'), number_format_i18n($years))
            /* translators: %s: a number of years */
            : sprintf(_n('in %s year', 'in %s years', -$years, 'taw-core'), number_format_i18n(-$years));
    }

    /** "3 days ago", or "in 2 weeks" for a future date. */
    private static function relative(int $time): string
    {
        $now = self::now();

        return $time <= $now
            /* translators: %s: a time span, e.g. "3 days" */
            ? sprintf(__('%s ago', 'taw-core'), human_time_diff($time, $now))
            /* translators: %s: a time span, e.g. "2 weeks" */
            : sprintf(__('in %s', 'taw-core'), human_time_diff($now, $time));
    }

    /**
     * Decimals: as asked, else none for whole numbers and 2 otherwise.
     *
     * @throws EvaluationError
     */
    private static function decimals(mixed $value, mixed $asked): int
    {
        if (!Value::isEmpty($asked)) {
            return max(0, min(10, (int) Value::toNumber($asked)));
        }
        $n = Value::toNumber($value);

        return (float) $n == floor((float) $n) ? 0 : 2;
    }

    private static function currency(int|float $amount, string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            $code = (string) apply_filters('taw_expression_currency', 'USD');
        }
        [$symbol, $decimals] = self::CURRENCIES[$code] ?? [null, 2];
        $number = number_format_i18n(abs((float) $amount), $decimals);
        $sign   = $amount < 0 ? '-' : '';

        return $symbol === null ? "{$sign}{$number} {$code}" : "{$sign}{$symbol}{$number}";
    }
}
