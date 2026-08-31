<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration\Actions;

use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\Contracts\Action;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Flushes caches after a remote sync. `args.scopes` picks which of
 * `object` / `opcache` / `rewrites` to clear (default: all). Fires the
 * `taw_hub_caches_flushed` action so a site or hosting plugin can hook its
 * own page-cache purge.
 */
final class FlushCachesAction implements Action
{
    private const SCOPES = ['object', 'opcache', 'rewrites'];

    public function name(): string
    {
        return 'flush-caches';
    }

    public function capability(): string
    {
        return 'hub:maintenance';
    }

    public function run(array $args): ActionResult
    {
        $requested = $args['scopes'] ?? null;
        $scopes = is_array($requested)
            ? array_values(array_intersect(self::SCOPES, array_filter($requested, 'is_string')))
            : self::SCOPES;
        if ($scopes === []) {
            $scopes = self::SCOPES;
        }

        $done = [];

        if (in_array('object', $scopes, true)) {
            wp_cache_flush();
            $done[] = 'object';
        }

        if (in_array('opcache', $scopes, true) && function_exists('opcache_reset')) {
            opcache_reset();
            $done[] = 'opcache';
        }

        if (in_array('rewrites', $scopes, true)) {
            flush_rewrite_rules(false);
            $done[] = 'rewrites';
        }

        do_action('taw_hub_caches_flushed', $done);

        return ActionResult::ok(['flushed' => $done], array_map(static fn (string $s): string => "flushed {$s}", $done));
    }
}
