<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Loop\Item;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The post a binding renders for: the block's `postId` context (set per item
 * by Query Loops, TAW Loops and the post editor), else the queried post.
 * Inside a TAW Loop (ADR-0014) it also carries the item, for `@row`/`@loop`.
 */
final class BindingContext
{
    public const ITEM = 'taw/loopItem';

    public function __construct(public readonly int $postId, public readonly ?Item $item = null)
    {
    }

    public static function fromBlock(object $block): self
    {
        $context = property_exists($block, 'context') && is_array($block->context) ? $block->context : [];
        $postId  = isset($context['postId']) && is_numeric($context['postId']) ? (int) $context['postId'] : 0;
        $item    = ($context[self::ITEM] ?? null) instanceof Item ? $context[self::ITEM] : null;

        if ($postId <= 0) {
            $queried = get_queried_object();
            $postId  = $queried instanceof \WP_Post ? (int) $queried->ID : 0;
        }

        return new self($postId, $item);
    }

    /** The context for one loop item: a post item's post, else this post. */
    public function forItem(Item $item): self
    {
        return new self($item->kind === Item::POST ? $item->postId : $this->postId, $item);
    }
}
