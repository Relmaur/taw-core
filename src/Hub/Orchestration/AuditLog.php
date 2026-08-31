<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration;

use TAW\Hub\Orchestration\Contracts\AuditStore;
use TAW\Hub\Security\Contracts\AuditSink;
use TAW\Hub\Security\HubIdentity;
use TAW\Hub\Security\InboundRequest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The persistent audit trail: every authorization decision (as an
 * {@see AuditSink}) plus every action dispatched through `/command`.
 *
 * Backed by an {@see AuditStore} so the write path is swappable and testable.
 * Never throws — a failed audit write must not affect the request.
 */
final class AuditLog implements AuditSink
{
    public function __construct(private AuditStore $store)
    {
    }

    public function rejected(InboundRequest $request, string $reason): void
    {
        $this->write('-', 'rejected', '', $reason, ['path' => $request->method . ' ' . $request->path]);
    }

    public function denied(InboundRequest $request, HubIdentity $identity, string $capability): void
    {
        $this->write($identity->keyId(), 'denied', '', "missing {$capability}", [
            'path' => $request->method . ' ' . $request->path,
        ]);
    }

    public function accepted(InboundRequest $request, HubIdentity $identity): void
    {
        $this->write($identity->keyId(), 'accepted', '', 'ok', [
            'path' => $request->method . ' ' . $request->path,
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function recordAction(HubIdentity $identity, string $action, array $args, ActionResult $result): void
    {
        $this->write(
            $identity->keyId(),
            'action',
            $action,
            $result->isOk() ? 'ok' : ('failed: ' . $result->error()),
            ['args' => array_keys($args), 'log' => $result->log()],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function query(int $limit = 100, int $sinceTs = 0): array
    {
        return $this->store->recent(max(1, min(1000, $limit)), max(0, $sinceTs));
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function write(string $actor, string $event, string $action, string $outcome, array $detail): void
    {
        $this->store->append([
            'ts'      => time(),
            'actor'   => $actor,
            'event'   => $event,
            'action'  => $action,
            'outcome' => $outcome,
            'detail'  => (string) json_encode($detail),
        ]);
    }
}
