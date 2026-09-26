<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The post a binding renders for: the block's `postId` context (set per item
 * by Query Loops, and by the post editor), else the queried post.
 */
final class BindingContext
{
    public function __construct(public readonly int $postId)
    {
    }

    public static function fromBlock(object $block): self
    {
        $context = property_exists($block, 'context') && is_array($block->context) ? $block->context : [];
        $postId  = isset($context['postId']) && is_numeric($context['postId']) ? (int) $context['postId'] : 0;

        if ($postId <= 0) {
            $queried = get_queried_object();
            $postId  = $queried instanceof \WP_Post ? (int) $queried->ID : 0;
        }

        return new self($postId);
    }
}
