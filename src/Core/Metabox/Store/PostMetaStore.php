<?php

declare(strict_types=1);

namespace TAW\Core\Metabox\Store;

// No ABSPATH guard: pure definitions with no include-time side effects.

/** {@see MetaStore} over post meta. */
final class PostMetaStore implements MetaStore
{
    public function objectType(): string
    {
        return 'post';
    }

    public function get(int $objectId, string $key): mixed
    {
        return get_post_meta($objectId, $key, true);
    }

    public function set(int $objectId, string $key, mixed $value): void
    {
        update_post_meta($objectId, $key, $value);
    }

    public function delete(int $objectId, string $key): void
    {
        delete_post_meta($objectId, $key);
    }
}
