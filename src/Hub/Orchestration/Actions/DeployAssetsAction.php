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
 * Stages (and by default applies) a Vite build archive via
 * {@see DeploymentTransaction}.
 *
 *   args.archive_path   local path to the .zip (the REST route saves the
 *                       upload to a temp file first; the CLI passes a path)
 *   args.apply          bool, default true — stage only when false
 *
 * The {@see DeploymentTransaction} is built by an injected factory so build-
 * directory resolution stays out of the action.
 */
final class DeployAssetsAction implements Action
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
        return 'deploy-assets';
    }

    public function capability(): string
    {
        return 'hub:deploy';
    }

    public function run(array $args): ActionResult
    {
        $path = is_string($args['archive_path'] ?? null) ? $args['archive_path'] : '';
        if ($path === '' || !is_file($path)) {
            return ActionResult::failed('archive_path is missing or not a file');
        }

        $apply = ($args['apply'] ?? true) !== false;
        $tx    = ($this->transactionFactory)();

        try {
            $staged = $tx->stage($path);

            if (!$apply) {
                return ActionResult::ok(['staged' => $staged], ['staged ' . $staged['deployment_id']]);
            }

            $applied = $tx->apply($staged['deployment_id']);

            return ActionResult::ok(
                ['staged' => $staged, 'applied' => $applied],
                ['staged ' . $staged['deployment_id'], 'applied — rollback ' . (string) $applied['rollback_id']],
            );
        } catch (PayloadException $e) {
            return ActionResult::failed($e->reason(), ['phase' => 'deploy']);
        }
    }
}
