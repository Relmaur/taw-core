<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A repeater's rows: iterate them, count them, or take the first.
 *
 * @implements \IteratorAggregate<int, Row>
 */
final class Rows implements \IteratorAggregate, \Countable
{
    /**
     * @param list<array<string, mixed>>          $rows      The stored rows.
     * @param array<string, array<string, mixed>> $subFields Sub-field id → config.
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $subFields
    ) {
    }

    /**
     * @return list<Row>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->rows as $i => $row) {
            $out[] = new Row($row, $this->subFields, $i);
        }

        return $out;
    }

    public function first(): ?Row
    {
        return $this->all()[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * @return \ArrayIterator<int, Row>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    /**
     * Every row decoded field by field.
     *
     * @return list<array<string, mixed>>
     */
    public function value(): array
    {
        return array_map(static fn (Row $row): array => $row->value(), $this->all());
    }
}
