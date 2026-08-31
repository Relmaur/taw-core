<?php

declare(strict_types=1);

namespace TAW\Hub\Telemetry;

use TAW\Core\Block\BlockRegistry;
use TAW\Core\Block\MetaBlock;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What TAW blocks this site has registered, plus a stable hash of the set so
 * the Hub can tell at a glance whether a site's block registry matches the
 * baseline it expects (blocks are theme-owned and discovered from the
 * theme's `/Blocks` directory — there's no per-block version).
 */
final class BlockInventory
{
    /**
     * @param array<string, MetaBlock>|null $blocks Defaults to the live registry.
     * @return array{count: int, hash: string, blocks: list<array{id: string, variation: string}>}
     */
    public static function collect(?array $blocks = null): array
    {
        $blocks ??= BlockRegistry::getAll();

        $list = [];
        foreach ($blocks as $id => $block) {
            $list[] = ['id' => (string) $id, 'variation' => $block->getVariation()];
        }

        usort($list, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        $ids = array_map(static fn (array $b): string => $b['id'], $list);

        return [
            'count'  => count($list),
            'hash'   => hash('sha256', implode("\n", $ids)),
            'blocks' => $list,
        ];
    }
}
