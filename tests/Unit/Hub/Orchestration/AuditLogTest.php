<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Orchestration;

use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\ArrayAuditStore;
use TAW\Hub\Orchestration\AuditLog;
use TAW\Hub\Security\HubIdentity;
use TAW\Hub\Security\InboundRequest;
use TAW\Tests\TestCase;

final class AuditLogTest extends TestCase
{
    private ArrayAuditStore $store;
    private AuditLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ArrayAuditStore();
        $this->log = new AuditLog($this->store);
    }

    private function request(): InboundRequest
    {
        return new InboundRequest('POST', '/taw-hub/v1/command', '{}', []);
    }

    public function test_it_records_auth_decisions_as_the_audit_sink(): void
    {
        $this->log->rejected($this->request(), 'bad_signature');
        $this->log->accepted($this->request(), new HubIdentity('hub-1', ['*']));

        $rows = $this->store->recent(10);
        $this->assertSame('accepted', $rows[0]['event']);
        $this->assertSame('rejected', $rows[1]['event']);
        $this->assertSame('-', $rows[1]['actor']);
    }

    public function test_it_records_actions_with_outcome_and_arg_keys(): void
    {
        $this->log->recordAction(
            new HubIdentity('hub-1', ['hub:config']),
            'sync-blocks',
            ['config' => [], 'mode' => 'merge'],
            ActionResult::failed('version conflict'),
        );

        $row = $this->store->recent(1)[0];
        $this->assertSame('action', $row['event']);
        $this->assertSame('sync-blocks', $row['action']);
        $this->assertStringContainsString('version conflict', $row['outcome']);
        $this->assertStringContainsString('config', $row['detail']);
    }

    public function test_query_filters_by_since_and_clamps_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->append(['ts' => 100 + $i, 'actor' => '-', 'event' => 'x', 'action' => '', 'outcome' => '', 'detail' => '{}']);
        }

        $this->assertCount(2, $this->log->query(100, 103));
        $this->assertLessThanOrEqual(1000, count($this->log->query(99999)));
    }
}
