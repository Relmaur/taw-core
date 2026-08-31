<?php

declare(strict_types=1);

namespace TAW\Hub\Telemetry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The combined telemetry payload — environment + block inventory + asset
 * inventory in one call, for the Hub's `/telemetry/full` route.
 */
final class TelemetrySnapshot
{
    /**
     * @return array{environment: array<string, mixed>, blocks: array<string, mixed>, assets: array<string, mixed>}
     */
    public static function collect(): array
    {
        return [
            'environment' => EnvironmentReport::collect(),
            'blocks'      => BlockInventory::collect(),
            'assets'      => AssetInventory::collect(),
        ];
    }
}
