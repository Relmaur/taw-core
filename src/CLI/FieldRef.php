<?php

declare(strict_types=1);

namespace TAW\CLI;

use TAW\Core\Metabox\Metabox;

/**
 * Resolves the field argument of `fields:get` / `fields:set` for one post
 * (ADR-0008): a qualified id (`hero.hero_heading`), a meta key
 * (`_taw_hero_heading`) or a bare id (`hero_heading`).
 *
 * The post type's own fields are searched first, so a bare id shared by
 * metaboxes on different post types resolves to the right one. A bare id
 * that still matches several fields (different prefixes on one post type)
 * is ambiguous. With no field for the post type, the bare registry is used,
 * as before.
 *
 * Call only after WordPress has booted.
 */
final class FieldRef
{
    /**
     * @return array{config: array<string, mixed>|null, ambiguous: list<string>}
     *   `config` has `meta_key` and `field_key` set; `ambiguous` lists the
     *   qualified ids to choose from when the reference matches several fields.
     */
    public static function resolve(int $postId, string $ref): array
    {
        $matches = Metabox::fieldMatches('post', (string) get_post_type($postId), $ref);

        if (count($matches) > 1) {
            return [
                'config'    => null,
                'ambiguous' => array_map(
                    static fn (array $config): string => $config['qualified_id'] . ' (' . $config['meta_key'] . ')',
                    $matches
                ),
            ];
        }

        if ($matches !== []) {
            return ['config' => $matches[0], 'ambiguous' => []];
        }

        $bare = Metabox::get_field_config($ref);
        if ($bare === null) {
            return ['config' => null, 'ambiguous' => []];
        }

        return ['config' => array_merge($bare, [
            'field_key' => $ref,
            'meta_key'  => (string) ($bare['prefix'] ?? '_taw_') . $ref,
        ]), 'ambiguous' => []];
    }
}
