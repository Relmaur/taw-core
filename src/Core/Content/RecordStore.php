<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots (see Exporter).

/**
 * Reads and writes whole records for {@see ImportJournal}: a capture is
 * `['fields' => [...], 'meta' => [key => list of values], 'terms' => [...]]`
 * (sections by kind), `null` when the record doesn't exist.
 * {@see WpRecords} is the WordPress one.
 */
interface RecordStore
{
    /** @return array<string, mixed>|null */
    public function capture(string $kind, int|string $id, bool $objects): ?array;

    /** @param array<string, mixed> $values `section.key => value`; `null` removes a meta key */
    public function restore(string $kind, int|string $id, array $values): void;

    public function delete(string $kind, int|string $id): void;

    /**
     * Put a deleted record back; false when one with its natural key
     * (a term's taxonomy and slug, a post's type and slug) is here already.
     *
     * @param array<string, mixed> $capture
     */
    public function recreate(string $kind, array $capture): bool;
}
