<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration\Actions;

use TAW\Hub\Assets\DeploymentTransaction;
use TAW\Hub\Assets\PayloadException;
use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\Contracts\Action;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reverts the most recent asset deployment. `args.rollback_id` is the id
 * {@see DeployAssetsAction} returned.
 */
final class RollbackAssetsAction implements Action
{
    /** @var callable(): DeploymentTransaction */
    private $transactionFactory;

    /**
     * @param callable(): DeploymentTransaction $transactionFactory
     */
    public function __construct(callable $transactionFactory)
    {
        $this->transactionFactory = $transactionFactory;
    }

    public function name(): string
    {
        return 'rollback-assets';
    }

    public function capability(): string
    {
        return 'hub:deploy';
    }

    public function run(array $args): ActionResult
    {
        $rollbackId = is_string($args['rollback_id'] ?? null) ? $args['rollback_id'] : '';
        if ($rollbackId === '') {
            return ActionResult::failed('rollback_id is required');
        }

        try {
            $result = ($this->transactionFactory)()->rollback($rollbackId);

            return ActionResult::ok($result, ['rolled back to ' . $rollbackId]);
        } catch (PayloadException $e) {
            return ActionResult::failed($e->reason());
        }
    }
}
