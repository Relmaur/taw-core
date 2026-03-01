<?php

declare(strict_types=1);

namespace TAW\Core;

use TAW\Core\MetaBlock;

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
