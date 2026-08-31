<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration\Contracts;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Append-only persistence for {@see \TAW\Hub\Orchestration\AuditLog}. Rows are
 * flat maps (see AuditLog for the shape); implementations must not throw on a
 * write failure — a lost audit line must never change a request's outcome.
 */
interface AuditStore
{
    /**
     * @param array{ts: int, actor: string, event: string, action: string, outcome: string, detail: string} $row
     */
    public function append(array $row): void;

    /**
     * Most recent rows first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit, int $sinceTs = 0): array;
}
