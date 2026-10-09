<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots (see Exporter).

/**
 * {@see RecordStore} over WordPress: posts (attachments included), terms,
 * options, users and comments, with all their meta.
 *
 * Left out of captures, as WordPress or the site changes them on its own:
 * `post_modified`, comment and term counts, `_edit_lock`/`_edit_last`,
 * user `session_tokens`, and transient and cron options.
 */
final class WpRecords implements RecordStore
{
    private const POST_FIELDS = ['post_type', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent',
        'menu_order', 'post_date', 'post_date_gmt', 'post_author', 'post_password', 'comment_status', 'ping_status', 'post_mime_type'];

    private const COMMENT_FIELDS = ['comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content',
        'comment_date', 'comment_date_gmt', 'comment_approved', 'comment_type', 'comment_parent', 'user_id'];

    private const USER_FIELDS = ['user_email', 'user_url', 'user_nicename', 'display_name', 'user_pass'];

    private const SKIP_META = ['_edit_lock', '_edit_last', 'session_tokens'];

    /**
     * Note in $journal everything about to change, and everything created,
     * until the returned callable is called.
     *
     * @return \Closure(): void
     */
    public static function watch(ImportJournal $journal): \Closure
    {
        $post = static function ($id) use ($journal): void {
            if ((int) $id > 0) {
                $journal->touch('post', (int) $id);
            }
        };
        $meta = static function (string $kind) use ($journal): \Closure {
            return static function ($check, $id) use ($journal, $kind) {
                if ((int) $id > 0) {
                    $journal->touch($kind, (int) $id);
                }
                return $check;
            };
        };
        $option = static function ($option) use ($journal): void {
            if (is_string($option) && !self::volatileOption($option)) {
                $journal->touch('option', $option);
            }
        };

        $hooks = [
            // Posts and attachments.
            ['action', 'pre_post_update', $post, 1],
            ['action', 'wp_trash_post', $post, 1],
            ['action', 'before_delete_post', $post, 1],
            ['action', 'add_term_relationship', $post, 1],
            ['action', 'delete_term_relationships', $post, 1],
            ['action', 'wp_insert_post', static function ($id, $p = null, $update = false) use ($journal): void {
                if (!$update) {
                    $journal->created('post', (int) $id);
                }
            }, 3],
            ['action', 'add_attachment', static fn ($id) => $journal->created('post', (int) $id), 1],
            // Terms.
            ['action', 'edit_terms', static fn ($id) => $journal->touch('term', (int) $id), 1],
            ['action', 'pre_delete_term', static fn ($id) => $journal->touch('term', (int) $id, true), 1],
            ['action', 'created_term', static fn ($id) => $journal->created('term', (int) $id), 1],
            // Options.
            ['filter', 'pre_update_option', static function ($value, $name) use ($option) {
                $option($name);
                return $value;
            }, 2],
            ['action', 'add_option', $option, 1],
            ['action', 'delete_option', $option, 1],
            // Users.
            ['filter', 'wp_pre_insert_user_data', static function ($data, $update = false, $id = null) use ($journal) {
                if ($update && (int) $id > 0) {
                    $journal->touch('user', (int) $id);
                }
                return $data;
            }, 3],
            ['action', 'user_register', static fn ($id) => $journal->created('user', (int) $id), 1],
            // Comments.
            ['action', 'wp_insert_comment', static fn ($id) => $journal->created('comment', (int) $id), 1],
            ['filter', 'wp_update_comment_data', static function ($data, $comment = []) use ($journal) {
                if (is_array($comment) && (int) ($comment['comment_ID'] ?? 0) > 0) {
                    $journal->touch('comment', (int) $comment['comment_ID']);
                }
                return $data;
            }, 2],
            ['action', 'delete_comment', static fn ($id) => $journal->touch('comment', (int) $id), 1],
            ['action', 'trash_comment', static fn ($id) => $journal->touch('comment', (int) $id), 1],
        ];
        foreach (['post', 'term', 'user', 'comment'] as $kind) {
            foreach (['add', 'update', 'delete'] as $verb) {
                $hooks[] = ['filter', "{$verb}_{$kind}_metadata", $meta($kind), 2];
            }
        }

        foreach ($hooks as [, $hook, $callback, $args]) {
            add_filter($hook, $callback, 1, $args);
        }

        return static function () use ($hooks): void {
            foreach ($hooks as [, $hook, $callback]) {
                remove_filter($hook, $callback, 1);
            }
        };
    }

    public static function volatileOption(string $name): bool
    {
        return $name === 'cron' || str_starts_with($name, '_transient') || str_starts_with($name, '_site_transient');
    }

    public function capture(string $kind, int|string $id, bool $objects): ?array
    {
        switch ($kind) {
            case 'post':
                $post = get_post((int) $id);
                if (!$post instanceof \WP_Post) {
                    return null;
                }
                $terms = [];
                foreach (get_object_taxonomies((string) $post->post_type) as $taxonomy) {
                    $ids = wp_get_object_terms((int) $id, $taxonomy, ['fields' => 'ids']);
                    if (is_array($ids)) {
                        $ids = array_map('intval', $ids);
                        sort($ids);
                        $terms[$taxonomy] = $ids;
                    }
                }
                return ['fields' => self::fields($post, self::POST_FIELDS), 'meta' => self::meta(get_post_meta((int) $id)), 'terms' => $terms];

            case 'term':
                $term = get_term((int) $id);
                if (!$term instanceof \WP_Term) {
                    return null;
                }
                $capture = ['fields' => ['taxonomy' => $term->taxonomy, 'name' => $term->name, 'slug' => $term->slug,
                    'description' => $term->description, 'parent' => (int) $term->parent], 'meta' => self::meta(get_term_meta((int) $id))];
                if ($objects) {
                    $found = get_objects_in_term((int) $id, $term->taxonomy);
                    $capture['objects'] = is_array($found) ? array_map('intval', $found) : [];
                }
                return $capture;

            case 'option':
                $missing = new \stdClass();
                $value = get_option((string) $id, $missing);
                return ['fields' => ['exists' => $value !== $missing, 'value' => $value === $missing ? null : $value]];

            case 'user':
                $user = get_userdata((int) $id);
                if (!$user instanceof \WP_User) {
                    return null;
                }
                return ['fields' => self::fields($user->data, self::USER_FIELDS), 'meta' => self::meta(get_user_meta((int) $id))];

            case 'comment':
                $comment = get_comment((int) $id);
                if (!$comment instanceof \WP_Comment) {
                    return null;
                }
                return ['fields' => self::fields($comment, self::COMMENT_FIELDS), 'meta' => self::meta(get_comment_meta((int) $id))];
        }

        return null;
    }

    public function restore(string $kind, int|string $id, array $values): void
    {
        $sections = [];
        foreach ($values as $path => $value) {
            [$section, $key] = explode('.', (string) $path, 2) + [1 => ''];
            $sections[$section][$key] = $value;
        }
        $fields = $sections['fields'] ?? [];

        switch ($kind) {
            case 'post':
                if ($fields !== []) {
                    $args = ['ID' => (int) $id] + $fields;
                    if (isset($fields['post_date']) || isset($fields['post_date_gmt'])) {
                        $args['edit_date'] = true;
                    }
                    wp_update_post(wp_slash($args));
                }
                foreach ($sections['terms'] ?? [] as $taxonomy => $ids) {
                    wp_set_object_terms((int) $id, array_map('intval', (array) $ids), (string) $taxonomy);
                }
                break;
            case 'term':
                $term = get_term((int) $id);
                if ($fields !== [] && $term instanceof \WP_Term) {
                    wp_update_term((int) $id, $term->taxonomy, wp_slash(array_intersect_key($fields, array_flip(['name', 'slug', 'description', 'parent']))));
                }
                break;
            case 'option':
                if (array_key_exists('exists', $fields) && !$fields['exists']) {
                    delete_option((string) $id);
                } elseif (array_key_exists('value', $fields)) {
                    update_option((string) $id, $fields['value']);
                }
                break;
            case 'user':
                if (array_key_exists('user_pass', $fields)) {
                    global $wpdb;
                    $wpdb->update($wpdb->users, ['user_pass' => (string) $fields['user_pass']], ['ID' => (int) $id]);
                    clean_user_cache((int) $id);
                    unset($fields['user_pass']);
                }
                if ($fields !== []) {
                    wp_update_user(['ID' => (int) $id] + $fields);
                }
                break;
            case 'comment':
                if ($fields !== []) {
                    wp_update_comment(['comment_ID' => (int) $id] + $fields);
                }
                break;
        }

        foreach ($sections['meta'] ?? [] as $key => $list) {
            self::writeMeta($kind, (int) $id, (string) $key, $list);
        }
    }

    public function delete(string $kind, int|string $id): void
    {
        switch ($kind) {
            case 'post':
                get_post_type((int) $id) === 'attachment' ? wp_delete_attachment((int) $id, true) : wp_delete_post((int) $id, true);
                break;
            case 'term':
                $term = get_term((int) $id);
                if ($term instanceof \WP_Term) {
                    wp_delete_term((int) $id, $term->taxonomy);
                }
                break;
            case 'option':
                delete_option((string) $id);
                break;
            case 'user':
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user((int) $id);
                break;
            case 'comment':
                wp_delete_comment((int) $id, true);
                break;
        }
    }

    public function recreate(string $kind, array $capture): bool
    {
        $fields = is_array($capture['fields'] ?? null) ? $capture['fields'] : [];
        $slug = (string) ($fields['slug'] ?? $fields['post_name'] ?? '');
        if ($kind === 'term' && $slug !== '' && get_term_by('slug', $slug, (string) ($fields['taxonomy'] ?? '')) instanceof \WP_Term) {
            return false;
        }
        if ($kind === 'post' && $slug !== '' && get_posts(['post_type' => (string) ($fields['post_type'] ?? 'post'), 'name' => $slug,
            'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'suppress_filters' => false]) !== []) {
            return false;
        }
        $id = 0;
        switch ($kind) {
            case 'post':
                $id = (int) wp_insert_post(wp_slash($fields), true);
                foreach (is_array($capture['terms'] ?? null) ? $capture['terms'] : [] as $taxonomy => $ids) {
                    wp_set_object_terms($id, array_map('intval', (array) $ids), (string) $taxonomy);
                }
                break;
            case 'term':
                $made = wp_insert_term((string) ($fields['name'] ?? ''), (string) ($fields['taxonomy'] ?? ''), wp_slash([
                    'slug' => (string) ($fields['slug'] ?? ''), 'description' => (string) ($fields['description'] ?? ''), 'parent' => (int) ($fields['parent'] ?? 0),
                ]));
                $id = is_array($made) ? (int) $made['term_id'] : 0;
                foreach ($id > 0 && is_array($capture['objects'] ?? null) ? $capture['objects'] : [] as $object) {
                    wp_add_object_terms((int) $object, $id, (string) $fields['taxonomy']);
                }
                break;
            case 'comment':
                $id = (int) wp_insert_comment(wp_slash($fields));
                break;
        }
        if ($id <= 0) {
            return false;
        }
        foreach (is_array($capture['meta'] ?? null) ? $capture['meta'] : [] as $key => $list) {
            self::writeMeta($kind, $id, (string) $key, $list);
        }

        return true;
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private static function fields(object $record, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $record->{$key} ?? null;
        }

        return $out;
    }

    /**
     * All meta, each key's values unserialized (as WordPress hands them to
     * the code that wrote them).
     *
     * @return array<string, list<mixed>>
     */
    private static function meta(mixed $all): array
    {
        $out = [];
        foreach (is_array($all) ? $all : [] as $key => $values) {
            if (!is_string($key) || in_array($key, self::SKIP_META, true)) {
                continue;
            }
            $out[$key] = array_map('maybe_unserialize', array_values((array) $values));
        }
        ksort($out);

        return $out;
    }

    /** One meta key back to $list (its values in order), or removed when null. */
    private static function writeMeta(string $kind, int $id, string $key, mixed $list): void
    {
        delete_metadata($kind, $id, $key);
        foreach (is_array($list) ? $list : [] as $value) {
            add_metadata($kind, $id, $key, wp_slash($value));
        }
    }
}
