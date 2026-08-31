<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by {@see ActionRegistry::get()} for a name that isn't in the
 * allow-list. The `/command` route maps this to a 422 — the request was
 * well-formed, the action just doesn't exist.
 */
final class UnknownActionException extends \RuntimeException
{
    public function __construct(private string $action)
    {
        parent::__construct("Unknown Hub action: {$action}");
    }

    public function action(): string
    {
        return $this->action;
    }
}
