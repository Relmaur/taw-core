<?php

declare(strict_types=1);

namespace TAW\Core\Metabox\Store;

// No ABSPATH guard: pure definitions with no include-time side effects.

/** {@see MetaStore} over term meta. */
final class TermMetaStore implements MetaStore
{
    public function objectType(): string
    {
        return 'term';
    }

    public function get(int $objectId, string $key): mixed
    {
        return get_term_meta($objectId, $key, true);
    }

    public function set(int $objectId, string $key, mixed $value): void
    {
        update_term_meta($objectId, $key, $value);
    }

    public function delete(int $objectId, string $key): void
    {
        delete_term_meta($objectId, $key);
    }
}
