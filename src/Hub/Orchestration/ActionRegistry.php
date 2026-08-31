<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration;

use TAW\Hub\Orchestration\Contracts\Action;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The allow-list of everything the Hub can invoke. Both `/command` and the
 * `wp taw …` commands resolve through here — there is no path to anything
 * not registered in this object.
 */
final class ActionRegistry
{
    /** @var array<string, Action> */
    private array $actions = [];

    /**
     * @param iterable<Action> $actions
     */
    public function __construct(iterable $actions)
    {
        foreach ($actions as $action) {
            $name = $action->name();
            if (isset($this->actions[$name])) {
                throw new \LogicException("Duplicate Hub action registered: {$name}");
            }
            $this->actions[$name] = $action;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    /**
     * @throws UnknownActionException
     */
    public function get(string $name): Action
    {
        return $this->actions[$name] ?? throw new UnknownActionException($name);
    }

    /**
     * @return list<array{name: string, capability: string}>
     */
    public function describe(): array
    {
        $out = [];
        foreach ($this->actions as $action) {
            $out[] = ['name' => $action->name(), 'capability' => $action->capability()];
        }

        usort($out, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $out;
    }
}
