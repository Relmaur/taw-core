<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Orchestration;

use TAW\Hub\Orchestration\ActionRegistry;
use TAW\Hub\Orchestration\ActionResult;
use TAW\Hub\Orchestration\Contracts\Action;
use TAW\Hub\Orchestration\UnknownActionException;
use TAW\Tests\TestCase;

final class ActionRegistryTest extends TestCase
{
    private function action(string $name, string $capability = 'hub:read'): Action
    {
        return new class ($name, $capability) implements Action {
            public function __construct(private string $n, private string $c)
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
                return ActionResult::ok(['ran' => $this->n]);
            }
        };
    }

    public function test_get_returns_the_registered_action(): void
    {
        $registry = new ActionRegistry([$this->action('flush-caches', 'hub:maintenance')]);

        $this->assertTrue($registry->has('flush-caches'));
        $this->assertSame('hub:maintenance', $registry->get('flush-caches')->capability());
    }

    public function test_get_throws_for_an_unregistered_name(): void
    {
        $registry = new ActionRegistry([$this->action('a')]);

        $this->expectException(UnknownActionException::class);
        $registry->get('does-not-exist');
    }

    public function test_duplicate_names_are_rejected_at_construction(): void
    {
        $this->expectException(\LogicException::class);
        new ActionRegistry([$this->action('dup'), $this->action('dup')]);
    }

    public function test_describe_lists_names_and_capabilities_sorted(): void
    {
        $registry = new ActionRegistry([
            $this->action('sync-blocks', 'hub:config'),
            $this->action('flush-caches', 'hub:maintenance'),
        ]);

        $this->assertSame([
            ['name' => 'flush-caches', 'capability' => 'hub:maintenance'],
            ['name' => 'sync-blocks', 'capability' => 'hub:config'],
        ], $registry->describe());
    }

    public function test_the_standard_registry_has_no_arbitrary_exec_action(): void
    {
        $names = array_column(\TAW\Hub\HubServices::registry()->describe(), 'name');

        $this->assertContains('flush-caches', $names);
        $this->assertContains('deploy-assets', $names);
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/\b(exec|eval|shell|run-?command|cli)\b/i', $name);
        }
    }
}
