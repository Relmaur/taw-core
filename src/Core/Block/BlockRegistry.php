<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Core\Block\MetaBlock;


if (!defined('ABSPATH')) {
    exit;
}

class BlockRegistry
{
    /** @var array<string, MetaBlock> */
    private static array $blocks = [];

    /** @var string[] IDs of blocks queued for the current page */
    private static array $queued = [];

    /**
     * Register a MetaBlock instance (called by BlockLoader).
     *
     * @param MetaBlock $block a Metablock instance
     */
    public static function register(MetaBlock $block): void
    {
        self::$blocks[$block->getId()] = $block;
    }

    /**
     * Get a specific block by id
     *
     * @param string $id The id of the block to retrieve
     */
    public static function get(string $id): ?MetaBlock
    {
        return self::$blocks[$id] ?? null;
    }

    /**
     * Get every registered block, keyed by id.
     *
     * @return array<string, MetaBlock>
     */
    public static function getAll(): array
    {
        return self::$blocks;
    }

    /**
     * Queue one or more blocks for the current page.
     * Call BEFORE get_header() so assets land in <head>.
     *
     * @param string $ids The ids of the blocks on the page that need their assets enqueued on <head> (to prevent FAUC)
     */
    public static function queue(string ...$ids): void
    {
        foreach ($ids as $id) {
            if (isset(self::$blocks[$id]) && !in_array($id, self::$queued, true)) {
                self::$queued[] = $id;
            }
        }
    }

    /**
     * Return the IDs of all blocks queued for the current page.
     */
    public static function getQueued(): array
    {
        return self::$queued;
    }

    /**
     * Enqueue assets for all queued blocks.
     * Hooked to wp_enqueue_scripts in functions.php
     */
    public static function enqueueQueuedAssets(): void
    {
        foreach (self::$queued as $id) {
            $block = self::get($id);
            if ($block) {
                $block->enqueueAssets();
            }
        }
    }

    /**
     * Render a block by ID.
     * Also calls enqueueAssets() as a safety fallback (footer)
     */
    public static function render(string $id, ?int $postId = null): void
    {
        $block = self::get($id);
        if (!$block) {
            return;
        }

        $block->enqueueAssets();
        $block->render($postId);
    }
}
