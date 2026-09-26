<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The block attribute a value is for, reduced to what the value must be:
 *
 * - `text`: rich text (paragraph/heading/list-item content, button text,
 *   image caption). WordPress inserts it with wp_kses_post(), so it's HTML:
 *   plain field text must arrive escaped.
 * - `plain`: an HTML attribute holding text (image alt/title). The tag
 *   processor escapes it, so it arrives unescaped.
 * - `url`: an href/src.
 * - `id`: an attachment ID (image id).
 * - `target` / `rel`: a button's linkTarget / rel.
 * - `date`: post-date's datetime.
 * - `inline`: an inline dynamic tag (ADR-0011): plain, unescaped text that
 *   the caller escapes. Images and URLs give their URL, a post select its title.
 */
final class Target
{
    public const KINDS = ['text', 'plain', 'url', 'id', 'target', 'rel', 'date', 'inline'];

    private const KNOWN = [
        'core/paragraph'          => ['content' => 'text'],
        'core/heading'            => ['content' => 'text'],
        'core/list-item'          => ['content' => 'text'],
        'core/button'             => ['text' => 'text', 'url' => 'url', 'linkTarget' => 'target', 'rel' => 'rel'],
        'core/image'              => ['id' => 'id', 'url' => 'url', 'alt' => 'plain', 'title' => 'plain', 'caption' => 'text'],
        'core/post-date'          => ['datetime' => 'date'],
        'core/navigation-link'    => ['url' => 'url'],
        'core/navigation-submenu' => ['url' => 'url'],
    ];

    public function __construct(
        public readonly string $block,
        public readonly string $attribute,
        public readonly string $kind,
    ) {
    }

    /** The target of an inline dynamic tag. */
    public static function inline(): self
    {
        return new self('taw/tag', 'value', 'inline');
    }

    /**
     * @param array<string, mixed>|null $attributeSchema The block type's definition of the attribute, for blocks a
     *                                                   `block_bindings_supported_attributes` filter added.
     */
    public static function for(string $block, string $attribute, ?array $attributeSchema = null): self
    {
        $kind = self::KNOWN[$block][$attribute] ?? self::guess($attribute, $attributeSchema ?? []);

        return new self($block, $attribute, $kind);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function guess(string $attribute, array $schema): string
    {
        $source = $schema['source'] ?? null;
        if ($source === 'rich-text' || $source === 'html') {
            return 'text';
        }
        if (in_array($schema['attribute'] ?? null, ['href', 'src'], true) || in_array(strtolower($attribute), ['url', 'href', 'src'], true)) {
            return 'url';
        }
        if (in_array($schema['type'] ?? null, ['number', 'integer'], true)) {
            return 'id';
        }

        return 'plain';
    }
}
