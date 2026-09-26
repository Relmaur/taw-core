<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Block-name helpers for the content layer: glob matching and collecting the
 * block names in parsed post content. Pure, so the server-side allow-list
 * check can be tested without WordPress.
 */
final class Blocks
{
    /**
     * Name given to raw HTML outside any block comment (parse_blocks() returns
     * it with a null blockName). The editor shows it as the Classic block.
     */
    public const FREEFORM = 'core/freeform';

    public const HTML = 'core/html';

    /**
     * The Block Bindings source an allowBound block must use (Bindings::SOURCE,
     * repeated here so this class stays loadable before WordPress boots).
     */
    public const BOUND_SOURCE = 'taw/field';

    /**
     * Whether $name matches any glob: "*" (every block), "core/*" (a
     * namespace) or an exact name.
     *
     * @param list<string> $globs
     */
    public static function matches(string $name, array $globs): bool
    {
        foreach ($globs as $glob) {
            if ($glob === '*' || $glob === $name) {
                return true;
            }
            if (str_ends_with($glob, '/*') && str_starts_with($name, substr($glob, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a block may be inserted under a content rule's allow list and
     * the features layer's Custom HTML setting.
     *
     * @param list<string>|null $allow null = every block.
     */
    public static function isAllowed(string $name, ?array $allow, bool $customHtml): bool
    {
        if (!$customHtml && $name === self::HTML) {
            return false;
        }

        return $allow === null || self::matches($name, $allow);
    }

    /**
     * Whether a block the allow list refuses may still be added bound to a
     * TAW field (a content rule's allowBound). Custom HTML can't be bound,
     * so the features layer's setting still wins.
     *
     * @param list<string> $allowBound
     */
    public static function isAllowedBound(string $name, array $allowBound, bool $customHtml): bool
    {
        return ($customHtml || $name !== self::HTML) && self::matches($name, $allowBound);
    }

    /**
     * Whether a parsed block is bound to a TAW field: one of its bindings
     * uses BOUND_SOURCE with a field name.
     *
     * @param array<string, mixed> $block A parse_blocks() entry.
     */
    public static function isBound(array $block): bool
    {
        $bindings = $block['attrs']['metadata']['bindings'] ?? null;
        if (!is_array($bindings)) {
            return false;
        }

        foreach ($bindings as $binding) {
            if (is_array($binding)
                && ($binding['source'] ?? null) === self::BOUND_SOURCE
                && is_string($binding['args']['field'] ?? null)
                && trim($binding['args']['field']) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many blocks of each name aren't bound to a TAW field, inner blocks
     * included.
     *
     * @param array<int, array<string, mixed>> $blocks parse_blocks() output.
     * @return array<string, int>
     */
    public static function unboundCounts(array $blocks): array
    {
        $counts = [];

        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if (is_string($name) && $name !== '' && !self::isBound($block)) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }

            $inner = $block['innerBlocks'] ?? [];
            if (is_array($inner) && $inner !== []) {
                foreach (self::unboundCounts($inner) as $innerName => $count) {
                    $counts[$innerName] = ($counts[$innerName] ?? 0) + $count;
                }
            }
        }

        return $counts;
    }

    /**
     * Every distinct block name in parse_blocks() output, inner blocks
     * included. Non-blank HTML outside a block counts as core/freeform, so
     * it can't slip past an allow list.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return list<string>
     */
    public static function namesIn(array $blocks): array
    {
        $names = [];

        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            } elseif (is_string($block['innerHTML'] ?? null) && trim($block['innerHTML']) !== '') {
                $names[self::FREEFORM] = true;
            }

            $inner = $block['innerBlocks'] ?? [];
            if (is_array($inner) && $inner !== []) {
                foreach (self::namesIn($inner) as $innerName) {
                    $names[$innerName] = true;
                }
            }
        }

        return array_keys($names);
    }
}
