<?php

declare(strict_types=1);

namespace TAW\Hub\Api;

use TAW\Hub\Orchestration\ActionRegistry;
use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\AuditLog;
use TAW\Hub\Orchestration\UnknownActionException;
use TAW\Hub\Security\HubIdentity;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves an action name against the {@see ActionRegistry}, re-checks the
 * caller's capability against what that action declares, runs it, and records
 * the outcome to the {@see AuditLog}. WordPress-free — the `/command` route is
 * a thin wrapper over this.
 *
 * The capability was already checked once by {@see \TAW\Hub\Security\HubAuthMiddleware}
 * for the route's own `hub:*` requirement; this second check binds the grant
 * to the *specific* action, so a `hub:read` key can't reach a `hub:deploy`
 * action even if it somehow passed the route guard.
 */
final class CommandDispatcher
{
    public function __construct(
        private ActionRegistry $registry,
        private AuditLog $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status: int, body: array<string, mixed>}
     */
    public function dispatch(HubIdentity $identity, string $actionName, array $args): array
    {
        try {
            $action = $this->registry->get($actionName);
        } catch (UnknownActionException $e) {
            return ['status' => 422, 'body' => ['error' => 'unknown_action', 'action' => $e->action()]];
        }

        if (!$identity->can($action->capability())) {
            $this->audit->recordAction($identity, $actionName, $args, ActionResult::failed('forbidden'));

            return ['status' => 403, 'body' => ['error' => 'forbidden', 'capability' => $action->capability()]];
        }

        $result = $action->run($args);
        $this->audit->recordAction($identity, $actionName, $args, $result);

        return [
            'status' => $result->isOk() ? 200 : 422,
            'body'   => ['action' => $actionName, ...$result->toArray()],
        ];
    }
}
