<?php

declare(strict_types=1);

namespace TAW\Core\DataPanel;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Where a fieldset shows in the block editor (ADR-0007 § 1), lowest first:
 *
 *   metabox (default) → site settings "fieldsetUi" → TAW_DATA_UI → the
 *   fieldset's own "ui".
 *
 * Pure: no WordPress calls.
 */
final class Ui
{
    public const PANEL = 'panel';

    public const METABOX = 'metabox';

    public const VALUES = [self::PANEL, self::METABOX];

    /**
     * @param mixed       $fieldsetUi A fieldset's own "ui" (null when it has none).
     * @param mixed       $constant   The TAW_DATA_UI value, or null when it isn't defined.
     * @param string|null $siteDefault The settings definition's "fieldsetUi".
     * @return array{ui: string, source: string, warning: ?string}
     */
    public static function resolve(mixed $fieldsetUi, mixed $constant, ?string $siteDefault): array
    {
        if (self::isValue($fieldsetUi)) {
            return ['ui' => $fieldsetUi, 'source' => 'fieldset', 'warning' => null];
        }

        $warning = null;
        if ($constant !== null) {
            if (self::isValue($constant)) {
                return ['ui' => $constant, 'source' => 'constant', 'warning' => null];
            }
            $warning = sprintf(
                'TAW_DATA_UI must be one of %s; ignoring %s.',
                implode(', ', self::VALUES),
                is_string($constant) ? '"' . $constant . '"' : gettype($constant)
            );
        }

        if (self::isValue($siteDefault)) {
            return ['ui' => $siteDefault, 'source' => 'settings', 'warning' => $warning];
        }

        return ['ui' => self::METABOX, 'source' => 'default', 'warning' => $warning];
    }

    /**
     * @phpstan-assert-if-true 'panel'|'metabox' $value
     */
    public static function isValue(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::VALUES, true);
    }
}
