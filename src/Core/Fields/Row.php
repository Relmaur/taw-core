<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One repeater row; its fields are typed by the repeater's sub-fields.
 */
final class Row
{
    /**
     * @param array<string, mixed>                $data      The stored row.
     * @param array<string, array<string, mixed>> $subFields Sub-field id → config.
     */
    public function __construct(
        private readonly array $data,
        private readonly array $subFields,
        private readonly int $index
    ) {
    }

    public function index(): int
    {
        return $this->index;
    }

    public function field(string $id): Value
    {
        $config = $this->subFields[$id] ?? null;

        if (($config['type'] ?? '') === 'group') {
            $data = $this->data;
            $subFields = [];
            foreach (is_array($config['fields'] ?? null) ? $config['fields'] : [] as $sub) {
                if (is_array($sub) && isset($sub['id'])) {
                    $subFields[(string) $sub['id']] = $sub;
                }
            }

            return new Value($config, '', static fn (string $sub): Value => new Value(
                $subFields[$sub] ?? null,
                $data[$id . '_' . $sub] ?? '',
                null,
                $id . '_' . $sub
            ), $id);
        }

        return new Value($config, $this->data[$id] ?? '', null, $id);
    }

    /**
     * The stored row.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * The row decoded field by field (checkbox as bool, image as int, …).
     *
     * @return array<string, mixed>
     */
    public function value(): array
    {
        $values = [];
        foreach (array_keys($this->data) as $key) {
            $values[(string) $key] = $this->field((string) $key)->value();
        }

        return $values;
    }
}
