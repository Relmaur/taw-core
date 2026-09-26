<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Bindings\Condition\Evaluator as ConditionEvaluator;
use TAW\Core\Bindings\Expression\Evaluator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Conditions (ADR-0013) against real values: tokens are read through the
 * expression resolvers (same names, functions and privacy as expressions),
 * dates in the site's time zone.
 */
final class Conditions
{
    /** How a date token reads when a date operator compares it. */
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @return array{shown: bool, errors: list<array{code: string, path: string}>}
     */
    public static function check(mixed $condition, BindingContext $context): array
    {
        $reader = static fn (string $token, bool $asDate): string => Evaluator::tokenValue($token, $context, $asDate ? self::DATE_FORMAT : null);

        return (new ConditionEvaluator($reader, wp_timezone(), current_datetime()))->check($condition);
    }

    public static function shown(mixed $condition, BindingContext $context): bool
    {
        return self::check($condition, $context)['shown'];
    }

    /** The `else` text of a chip or binding, or null for nothing. */
    public static function otherwise(array $args): ?string
    {
        $else = $args['else'] ?? null;

        return is_string($else) && trim($else) !== '' ? $else : null;
    }
}
