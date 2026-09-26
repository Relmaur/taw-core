<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whole-block conditions (ADR-0013): a block whose `metadata.tawShowIf`
 * doesn't hold renders nothing. It reads the block's own context, so inside
 * a Query Loop each item decides for itself.
 *
 * Display only: the block is still in the post content.
 */
final class BlockVisibility
{
    public const KEY = 'tawShowIf';

    public static function register(): void
    {
        add_filter('render_block', [self::class, 'renderBlock'], 9, 3);
    }

    /**
     * render_block (before InlineTags, so a hidden block's tags aren't resolved).
     *
     * @param array<string, mixed> $parsed
     */
    public static function renderBlock(string $html, array $parsed = [], ?object $block = null): string
    {
        $metadata = $parsed['attrs']['metadata'] ?? null;
        if (!is_array($metadata) || !array_key_exists(self::KEY, $metadata)) {
            return $html;
        }

        $context = BindingContext::fromBlock($block ?? new \stdClass());

        return Conditions::shown($metadata[self::KEY], $context) ? $html : '';
    }
}
