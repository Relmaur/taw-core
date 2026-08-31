<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration;

use TAW\Hub\Orchestration\Contracts\AuditStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * In-memory {@see AuditStore} — the fallback when the DB table can't be
 * created, and what the test suite runs against. Bounded so a long-lived
 * process can't grow without limit.
 */
final class ArrayAuditStore implements AuditStore
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function __construct(private int $maxRows = 500)
    {
    }

    public function append(array $row): void
    {
        $this->rows[] = $row;
        if (count($this->rows) > $this->maxRows) {
            array_shift($this->rows);
        }
    }

    public function recent(int $limit, int $sinceTs = 0): array
    {
        $rows = array_filter(
            $this->rows,
            static fn (array $r): bool => (int) ($r['ts'] ?? 0) >= $sinceTs,
        );

        return array_slice(array_reverse(array_values($rows)), 0, $limit);
    }
}
