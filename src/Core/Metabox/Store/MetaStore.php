<?php

declare(strict_types=1);

namespace TAW\Core\Metabox\Store;

// No ABSPATH guard: pure definitions with no include-time side effects.

/**
 * Where a metabox reads and writes its field values (ADR-0008 decision 4):
 * post, term or user meta. The metabox engine only talks to a store, so one
 * renderer, sanitizer and validator serve every object type.
 */
interface MetaStore
{
    /** 'post', 'term' or 'user' — the object type `register_meta()` uses. */
    public function objectType(): string;

    /** The stored value (single), '' when missing. */
    public function get(int $objectId, string $key): mixed;

    /** Store an already sanitized and slashed value. */
    public function set(int $objectId, string $key, mixed $value): void;

    public function delete(int $objectId, string $key): void;
}
