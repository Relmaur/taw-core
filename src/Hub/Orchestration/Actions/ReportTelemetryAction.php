<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration\Actions;

use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\Contracts\Action;
use TAW\Hub\Telemetry\TelemetrySnapshot;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns the full telemetry snapshot. The CLI-parity twin of
 * `GET /telemetry/full`; read-only.
 */
final class ReportTelemetryAction implements Action
{
    public function name(): string
    {
        return 'report-telemetry';
    }

    public function capability(): string
    {
        return 'hub:read';
    }

    public function run(array $args): ActionResult
    {
        return ActionResult::ok(TelemetrySnapshot::collect());
    }
}
