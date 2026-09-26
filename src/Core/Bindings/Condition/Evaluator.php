<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Condition;

// No ABSPATH guard: pure class (no WordPress calls), like Expression\Parser.

/**
 * Evaluates a condition (ADR-0013). Values come from a reader, so this class
 * stays pure: the WordPress side (Bindings\Conditions) reads tokens through
 * the expression resolvers, tests pass a map.
 *
 *   $reader(string $token, bool $asDate): string
 *
 * With $asDate the reader returns dates in a parseable form (the token's own
 * `format()` wins). Text comparisons ignore case and surrounding spaces.
 * Anything that can't be compared (a word where a number is needed, an
 * unparseable date) is false.
 */
final class Evaluator
{
    /** Relative dates a comparison accepts: "-30 days", "+1 week". */
    private const RELATIVE = '/^[+-]\s*\d{1,4}\s*(minute|hour|day|week|month|year)s?$/i';

    /** Text that reads as "no": is_false, and not is_true. */
    private const FALSY = ['', '0', 'false', 'no', 'off'];

    /** @var \Closure(string, bool): string */
    private \Closure $reader;

    /**
     * @param callable(string, bool): string $reader
     */
    public function __construct(callable $reader, private readonly \DateTimeZone $timezone, private readonly \DateTimeImmutable $now)
    {
        $this->reader = \Closure::fromCallable($reader);
    }

    /**
     * Whether a condition holds, as `{shown, errors}`. An invalid condition
     * is never shown.
     *
     * @return array{shown: bool, errors: list<array{code: string, path: string}>}
     */
    public function check(mixed $condition): array
    {
        $normalized = Condition::normalize($condition);
        if ($normalized['condition'] === null) {
            return ['shown' => false, 'errors' => $normalized['errors']];
        }

        return ['shown' => $this->group($normalized['condition']), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $group A normalized group.
     */
    private function group(array $group): bool
    {
        $any = $group['match'] === 'any';
        foreach ($group['rules'] as $rule) {
            $holds = isset($rule['rules']) ? $this->group($rule) : $this->rule($rule);
            if ($any && $holds) {
                return true;
            }
            if (!$any && !$holds) {
                return false;
            }
        }

        // Every rule held (all), or none did (any). A group with no rules holds.
        return !$any || $group['rules'] === [];
    }

    /**
     * @param array<string, mixed> $rule A normalized rule.
     */
    private function rule(array $rule): bool
    {
        $op     = (string) $rule['op'];
        $asDate = in_array($op, Condition::DATE_OPERATORS, true);
        $left   = ($this->reader)((string) $rule['value'], $asDate);
        $to     = $rule['to'] ?? null;
        $right  = is_string($to) ? $this->operand($to, $asDate) : '';
        $list   = is_array($to) ? array_map(fn (string $item): string => $this->operand($item, $asDate), $to) : [];

        return match ($op) {
            'empty'         => trim($left) === '',
            'not_empty'     => trim($left) !== '',
            'is_true'       => !self::falsy($left),
            'is_false'      => self::falsy($left),
            'equals'        => self::text($left) === self::text($right),
            'not_equals'    => self::text($left) !== self::text($right),
            'contains'      => self::text($right) !== '' && str_contains(self::text($left), self::text($right)),
            'not_contains'  => self::text($right) === '' || !str_contains(self::text($left), self::text($right)),
            'starts_with'   => self::text($right) !== '' && str_starts_with(self::text($left), self::text($right)),
            'ends_with'     => self::text($right) !== '' && str_ends_with(self::text($left), self::text($right)),
            'has'           => in_array(self::text($right), self::items($left), true),
            'has_not'       => !in_array(self::text($right), self::items($left), true),
            'in'            => in_array(self::text($left), array_map([self::class, 'text'], $list), true),
            'not_in'        => !in_array(self::text($left), array_map([self::class, 'text'], $list), true),
            'gt', 'gte', 'lt', 'lte' => self::compareNumbers($op, self::number($left), self::number($right)),
            'between'       => self::betweenNumbers(self::number($left), $list),
            'before', 'after' => $this->compareDates($op, $this->date($left), $this->date($right)),
            'on'            => $this->day($left) !== null && $this->day($left) === $this->day($right),
            'between_dates' => $this->betweenDays($this->day($left), $list),
            default         => false,
        };
    }

    /** An operand's text: another value's (a token), else itself (`@@` → `@`). */
    private function operand(string $operand, bool $asDate): string
    {
        if (Condition::isToken($operand)) {
            return ($this->reader)(trim($operand), $asDate);
        }

        return str_starts_with($operand, '@@') ? substr($operand, 1) : $operand;
    }

    private static function text(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
    }

    private static function falsy(string $value): bool
    {
        return in_array(self::text($value), self::FALSY, true);
    }

    /**
     * A multi-value field's items ("fiction, poetry" → ["fiction", "poetry"]).
     *
     * @return list<string>
     */
    private static function items(string $value): array
    {
        return array_values(array_filter(array_map([self::class, 'text'], explode(',', $value)), static fn (string $s): bool => $s !== ''));
    }

    private static function number(string $value): ?float
    {
        $value = trim($value);

        return is_numeric($value) ? (float) $value : null;
    }

    private static function compareNumbers(string $op, ?float $left, ?float $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return match ($op) {
            'gt'    => $left > $right,
            'gte'   => $left >= $right,
            'lt'    => $left < $right,
            default => $left <= $right,
        };
    }

    /**
     * @param list<string> $pair
     */
    private static function betweenNumbers(?float $value, array $pair): bool
    {
        $low  = self::number($pair[0] ?? '');
        $high = self::number($pair[1] ?? '');

        return $value !== null && $low !== null && $high !== null && $value >= min($low, $high) && $value <= max($low, $high);
    }

    private function compareDates(string $op, ?\DateTimeImmutable $left, ?\DateTimeImmutable $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }

        return $op === 'before' ? $left < $right : $left > $right;
    }

    /**
     * @param list<string> $pair
     */
    private function betweenDays(?string $day, array $pair): bool
    {
        $from = $this->day($pair[0] ?? '');
        $to   = $this->day($pair[1] ?? '');
        if ($day === null || $from === null || $to === null) {
            return false;
        }

        return $day >= min($from, $to) && $day <= max($from, $to);
    }

    /** A value's day (Y-m-d) in the site's time zone, for `on` and `between_dates`. */
    private function day(string $value): ?string
    {
        return $this->date($value)?->setTimezone($this->timezone)->format('Y-m-d');
    }

    /**
     * A date in the site's time zone: `now`, `today`, `tomorrow`,
     * `yesterday`, a relative date ("-30 days"), or anything PHP reads as an
     * absolute date. Null when it isn't one.
     */
    private function date(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        $word  = strtolower($value);
        $today = $this->now->setTimezone($this->timezone)->setTime(0, 0);

        if ($value === '') {
            return null;
        }
        if (in_array($word, ['now', 'today', 'tomorrow', 'yesterday'], true)) {
            return match ($word) {
                'now'       => $this->now,
                'today'     => $today,
                'tomorrow'  => $today->modify('+1 day'),
                default     => $today->modify('-1 day'),
            };
        }
        if (preg_match(self::RELATIVE, $value) === 1) {
            $relative = $this->now->modify((string) preg_replace('/^([+-])\s*/', '$1', $value));

            return $relative === false ? null : $relative;
        }
        // Only absolute dates from here: they start with a digit ("2025-01-01", "2025-01-01 10:00").
        if (preg_match('/^\d/', $value) !== 1) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, $this->timezone);
        } catch (\Exception) {
            return null;
        }
    }
}
