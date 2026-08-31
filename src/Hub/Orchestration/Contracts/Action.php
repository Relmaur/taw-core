<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration\Contracts;

use TAW\Hub\Orchestration\ActionResult;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One named, typed operation the Hub (or a `wp taw …` command) can invoke.
 *
 * The entire remote-execution surface is this fixed set — there is no
 * "run arbitrary command" path. Every action declares the capability its
 * caller must hold; {@see \TAW\Hub\Orchestration\ActionRegistry} is the
 * allow-list.
 */
interface Action
{
    /** Stable slug, e.g. `flush-caches`. */
    public function name(): string;

    /** Capability the caller must hold, e.g. `hub:maintenance`. */
    public function capability(): string;

    /**
     * @param array<string, mixed> $args
     */
    public function run(array $args): ActionResult;
}
