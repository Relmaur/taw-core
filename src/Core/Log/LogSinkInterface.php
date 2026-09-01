<?php

declare(strict_types=1);

namespace TAW\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A destination for log entries. {@see Logger} writes the same entry to
 * every registered sink — swap or extend via the `taw_core_log_sinks`
 * filter (see {@see Logger::defaultSinks()}).
 */
interface LogSinkInterface
{
    /**
     * @param array{ts: string, level: string, code: string, message: string, context: array<string, mixed>, request_id: string} $entry
     */
    public function write(array $entry): void;
}
