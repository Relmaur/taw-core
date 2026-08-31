<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration\Actions;

use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\Contracts\Action;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies a Hub-pushed TAW block configuration to the `taw_hub_block_config`
 * option.
 *
 *   args.config            object of { <blockId>: { …settings… } }
 *   args.mode              "merge" (default) | "replace"
 *   args.expected_version  optional int — reject if the stored version has
 *                          moved on (optimistic concurrency; the Hub runs
 *                          many sites asynchronously)
 *
 * This only writes the option — it doesn't re-register blocks. Blocks read
 * their config on the next request.
 */
final class SyncBlocksAction implements Action
{
    private const OPTION = 'taw_hub_block_config';

    public function name(): string
    {
        return 'sync-blocks';
    }

    public function capability(): string
    {
        return 'hub:config';
    }

    public function run(array $args): ActionResult
    {
        $config = $args['config'] ?? null;
        if (!is_array($config) || !$this->isBlockConfig($config)) {
            return ActionResult::failed('config must be an object of per-block objects');
        }

        $stored = get_option(self::OPTION, ['version' => 0, 'blocks' => []]);
        $storedVersion = is_array($stored) ? (int) ($stored['version'] ?? 0) : 0;
        $storedBlocks  = is_array($stored) && is_array($stored['blocks'] ?? null) ? $stored['blocks'] : [];

        if (isset($args['expected_version']) && (int) $args['expected_version'] !== $storedVersion) {
            return ActionResult::failed('version conflict', [
                'expected' => (int) $args['expected_version'],
                'actual'   => $storedVersion,
            ]);
        }

        $mode   = ($args['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
        $blocks = $mode === 'replace' ? $config : array_merge($storedBlocks, $config);

        $next = ['version' => $storedVersion + 1, 'blocks' => $blocks, 'updated_at' => time()];
        update_option(self::OPTION, $next, false);

        return ActionResult::ok([
            'applied_version' => $next['version'],
            'mode'            => $mode,
            'changed_keys'    => array_keys($config),
        ]);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private function isBlockConfig(array $config): bool
    {
        if ($config === []) {
            return false;
        }

        foreach ($config as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                return false;
            }
        }

        return true;
    }
}
