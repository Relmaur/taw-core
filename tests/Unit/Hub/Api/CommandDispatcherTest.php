<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Api;

use TAW\Hub\Api\CommandDispatcher;
use TAW\Hub\Orchestration\ActionRegistry;
use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\ArrayAuditStore;
use TAW\Hub\Orchestration\AuditLog;
use TAW\Hub\Orchestration\Contracts\Action;
use TAW\Hub\Security\HubIdentity;
use TAW\Tests\TestCase;

final class CommandDispatcherTest extends TestCase
{
    private ArrayAuditStore $store;

    private function action(string $name, string $capability, ActionResult $result): Action
    {
        return new class ($name, $capability, $result) implements Action {
            public function __construct(private string $n, private string $c, private ActionResult $r)
            {
            }
            public function name(): string
            {
                return $this->n;
            }
            public function capability(): string
            {
                return $this->c;
            }
            public function run(array $args): ActionResult
            {
                return $this->r;
            }
        };
    }

    private function dispatcher(Action ...$actions): CommandDispatcher
    {
        $this->store = new ArrayAuditStore();

        return new CommandDispatcher(new ActionRegistry($actions), new AuditLog($this->store));
    }

    public function test_an_unknown_action_is_422_and_not_audited(): void
    {
        $out = $this->dispatcher()->dispatch(new HubIdentity('hub-1', ['*']), 'nope', []);

        $this->assertSame(422, $out['status']);
        $this->assertSame('unknown_action', $out['body']['error']);
        $this->assertSame([], $this->store->recent(10));
    }

    public function test_a_capability_the_key_lacks_is_403_and_audited(): void
    {
        $dispatcher = $this->dispatcher(
            $this->action('deploy-assets', 'hub:deploy', ActionResult::ok()),
        );

        $out = $dispatcher->dispatch(new HubIdentity('hub-1', ['hub:read']), 'deploy-assets', []);

        $this->assertSame(403, $out['status']);
        $this->assertSame('hub:deploy', $out['body']['capability']);
        $this->assertSame('action', $this->store->recent(10)[0]['event']);
    }

    public function test_a_successful_action_is_200_with_its_payload_and_is_audited(): void
    {
        $dispatcher = $this->dispatcher(
            $this->action('flush-caches', 'hub:maintenance', ActionResult::ok(['flushed' => ['object']])),
        );

        $out = $dispatcher->dispatch(new HubIdentity('hub-1', ['hub:maintenance']), 'flush-caches', ['scopes' => ['object']]);

        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['body']['ok']);
        $this->assertSame(['object'], $out['body']['data']['flushed']);
        $this->assertStringContainsString('ok', $this->store->recent(10)[0]['outcome']);
    }

    public function test_a_failing_action_is_422_and_records_the_error(): void
    {
        $dispatcher = $this->dispatcher(
            $this->action('sync-blocks', 'hub:config', ActionResult::failed('version conflict')),
        );

        $out = $dispatcher->dispatch(new HubIdentity('hub-1', ['hub:config']), 'sync-blocks', []);

        $this->assertSame(422, $out['status']);
        $this->assertFalse($out['body']['ok']);
        $this->assertStringContainsString('version conflict', $this->store->recent(10)[0]['outcome']);
    }

    public function test_a_wildcard_key_can_run_any_action(): void
    {
        $dispatcher = $this->dispatcher(
            $this->action('deploy-assets', 'hub:deploy', ActionResult::ok()),
        );

        $out = $dispatcher->dispatch(new HubIdentity('break-glass', ['*']), 'deploy-assets', []);

        $this->assertSame(200, $out['status']);
    }
}
