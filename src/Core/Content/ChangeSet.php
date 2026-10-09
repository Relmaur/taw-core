<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots, and the
// guard's `exit` silently kills the command (v1.25.1 fix). They are
// pure class definitions with no include-time side effects — like
// TAW\Helpers\Framework and TAW\CLI\WpLoader, which omit it too.

/**
 * Diffs two content snapshots (`Exporter` output) into a change-set — the
 * `{taw_changeset, operations: [...]}` shape {@see Importer} also accepts
 * directly. This is what `bin/taw content:diff <a.json> <b.json>` emits.
 *
 * Records are matched the same way the importer matches them: posts by
 * `(type, slug)`, options by key, terms by `(taxonomy, slug)` — never by
 * numeric ID. Pure; no WordPress calls.
 */
final class ChangeSet
{
    /**
     * @param array<string, mixed> $base   The "from" snapshot.
     * @param array<string, mixed> $target The "to" snapshot.
     * @return array{taw_changeset: array{schema: string, base: mixed, target: mixed}, operations: list<array<string, mixed>>}
     */
    public static function between(array $base, array $target): array
    {
        $operations = [];

        // A section only one side has in full (a scoped export, an opt-in
        // section left out) yields no deletes: what's missing wasn't seen.
        $complete = static fn (string $section): bool => self::complete($base, $section) && self::complete($target, $section);

        array_push($operations, ...self::diffPosts($base['posts'] ?? [], $target['posts'] ?? [], $complete('posts')));
        array_push($operations, ...self::diffOptions($base['options'] ?? [], $target['options'] ?? [], $complete('options')));
        array_push($operations, ...self::diffTerms($base['terms'] ?? [], $target['terms'] ?? [], $complete('terms')));
        array_push($operations, ...self::diffUsers($base['users'] ?? [], $target['users'] ?? [], $complete('users')));
        array_push($operations, ...self::diffComments($base['comments'] ?? [], $target['comments'] ?? [], $complete('comments')));
        array_push($operations, ...self::diffMenus($base['menus'] ?? [], $target['menus'] ?? [], $complete('menus')));
        array_push($operations, ...self::diffKeyed('theme_mod', $base['theme_mods'] ?? [], $target['theme_mods'] ?? []));

        $changeSet = [
            'taw_changeset' => [
                'schema' => (string) (($target['meta']['schema'] ?? $base['meta']['schema'] ?? Exporter::SCHEMA_VERSION)),
                'base'   => $base['meta']['source'] ?? null,
                'target' => $target['meta']['source'] ?? null,
            ],
        ];
        // What the import needs to land these operations on another site
        // (1.6): the source (URLs rewrite), `refs` (IDs map), and the media
        // the operations reference (files sideload, attachment IDs map).
        if (is_array($target['meta'] ?? null)) {
            $changeSet['meta'] = $target['meta'];
        }
        if (is_array($target['refs'] ?? null)) {
            $changeSet['refs'] = $target['refs'];
        }
        $media = self::referencedMedia(is_array($target['media'] ?? null) ? $target['media'] : [], $operations);
        if ($media !== []) {
            $changeSet['media'] = $media;
        }
        $changeSet['operations'] = $operations;

        return $changeSet;
    }

    /**
     * Whether $snapshot has $section in full: present, and not narrowed
     * by its export scope (1.6 `meta.scope`).
     *
     * @param array<string, mixed> $snapshot
     */
    private static function complete(array $snapshot, string $section): bool
    {
        if (!array_key_exists($section, $snapshot)) {
            return false;
        }
        $scope = is_array($snapshot['meta']['scope'] ?? null) ? $snapshot['meta']['scope'] : [];

        return match ($section) {
            'posts'   => ($scope['posts'] ?? 'all') !== 'partial',
            'terms'   => ($scope['terms'] ?? 'all') !== 'used',
            'options', 'menus', 'theme_mods' => ($scope['options'] ?? true) !== false,
            default   => true,
        };
    }

    /**
     * The media entries the operations reference: by attachment ID (in
     * fields, option values and block attributes, `wp-image-N`), by a
     * featured image's filename, or by URL (a file's URL, its size variants
     * and the original of a `-scaled` upload). Pure, so `content:diff`
     * needs no WordPress; an ID that merely looks like one carries an
     * extra entry, which only costs a lookup.
     *
     * @param list<mixed>                $media
     * @param list<array<string, mixed>> $operations
     * @return list<array<string, mixed>>
     */
    public static function referencedMedia(array $media, array $operations): array
    {
        $ids = [];
        $strings = [];
        foreach ($operations as $op) {
            if (($op['op'] ?? '') === 'delete') {
                continue;
            }
            $record = is_array($op['post'] ?? null) ? $op['post'] : (is_array($op['fields'] ?? null) ? $op['fields'] : []);
            self::scan($record, $ids, $strings, false);
        }
        $text = implode("\n", $strings);
        if (preg_match_all('/"(?:id|mediaId)":(\d+)|wp-image-(\d+)|data-id=["\'](\d+)/', $text, $m)) {
            foreach ([$m[1], $m[2], $m[3]] as $group) {
                foreach ($group as $id) {
                    if ($id !== '') {
                        $ids[(int) $id] = true;
                    }
                }
            }
        }
        if (preg_match_all('/"ids":\[([\d,]+)\]/', $text, $m)) {
            foreach ($m[1] as $list) {
                foreach (explode(',', $list) as $id) {
                    $ids[(int) $id] = true;
                }
            }
        }
        $names = array_flip($strings);

        $out = [];
        foreach ($media as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (int) ($entry['id'] ?? 0);
            $filename = (string) ($entry['filename'] ?? $entry['ref'] ?? '');
            $url = (string) ($entry['url'] ?? '');
            if (($id > 0 && isset($ids[$id])) || ($filename !== '' && isset($names[$filename])) || self::urlIn($url, $text)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Collect a record's strings, and its integers under `fields` (and an
     * option's value): the places an attachment ID is stored.
     *
     * @param array<int, true> $ids
     * @param list<string>     $strings
     */
    private static function scan(mixed $value, array &$ids, array &$strings, bool $inFields): void
    {
        if (is_string($value)) {
            $strings[] = $value;
            if ($inFields && ctype_digit($value)) {
                $ids[(int) $value] = true;
            }
            return;
        }
        if (is_int($value)) {
            if ($inFields) {
                $ids[$value] = true;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            self::scan($item, $ids, $strings, $inFields || $key === 'fields' || $key === 'value');
        }
    }

    /** Whether $text links to the file at $url, a size variant of it, or its unscaled original. */
    private static function urlIn(string $url, string $text): bool
    {
        if ($url === '' || !preg_match('#^(.*/[^/]+?)\.([A-Za-z0-9]{1,5})$#', $url, $m)) {
            return $url !== '' && str_contains($text, $url);
        }
        $base = (string) preg_replace('#-scaled$#', '', $m[1]);
        foreach ([$base, str_replace('/', '\\/', $base)] as $form) {
            if (preg_match('#' . preg_quote($form, '#') . '(-scaled|-\d+x\d+)?\.' . preg_quote($m[2], '#') . '(?![\w-])#', $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $base
     * @param mixed $target
     * @return list<array<string, mixed>>
     */
    private static function diffPosts(mixed $base, mixed $target, bool $deletes = true): array
    {
        $base = is_array($base) ? $base : [];
        $target = is_array($target) ? $target : [];
        // Hierarchical posts key on their path (1.5), when both sides have
        // paths: a 1.4 snapshot against a 1.5 one would otherwise turn every
        // page into a delete and a create.
        $hasPaths = static fn (array $posts): bool => $posts === [] || array_filter($posts, static fn ($p): bool => is_array($p) && !empty($p['path'])) !== [];
        $usePaths = $hasPaths($base) && $hasPaths($target);
        $keyer = static fn (array $p): string => ($p['type'] ?? '') . '|' . ($usePaths && !empty($p['path'])
            ? $p['path']
            : (($p['slug'] ?? '') !== '' ? $p['slug'] : ($p['match_key'] ?? '')));
        $baseMap = self::indexBy($base, $keyer);
        $targetMap = self::indexBy($target, $keyer);

        $ops = [];

        foreach ($targetMap as $key => $record) {
            $t = Importer::postTarget($record);
            if (!isset($baseMap[$key])) {
                $ops[] = ['op' => 'create', 'target' => $t, 'post' => $record];
                continue;
            }
            if (self::normalize($baseMap[$key]) !== self::normalize($record)) {
                $ops[] = ['op' => 'update', 'target' => $t, 'post' => $record];
            }
        }

        foreach ($deletes ? $baseMap : [] as $key => $record) {
            if (!isset($targetMap[$key])) {
                $ops[] = [
                    'op'     => 'delete',
                    'target' => Importer::postTarget($record),
                ];
            }
        }

        return $ops;
    }

    /**
     * @param mixed $base
     * @param mixed $target
     * @return list<array<string, mixed>>
     */
    private static function diffUsers(mixed $base, mixed $target, bool $deletes = true): array
    {
        $keyer = static fn (array $u): string => (string) ($u['login'] ?? '');
        $baseMap = self::indexBy(is_array($base) ? $base : [], $keyer);
        $targetMap = self::indexBy(is_array($target) ? $target : [], $keyer);
        $ops = [];

        foreach ($targetMap as $key => $record) {
            if ($key === '') {
                continue;
            }
            $t = ['kind' => 'user', 'key' => $key];
            if (!isset($baseMap[$key])) {
                $ops[] = ['op' => 'create', 'target' => $t, 'fields' => $record];
            } elseif (self::normalize($baseMap[$key]) !== self::normalize($record)) {
                $ops[] = ['op' => 'update', 'target' => $t, 'fields' => $record];
            }
        }
        foreach ($deletes ? $baseMap : [] as $key => $record) {
            if ($key !== '' && !isset($targetMap[$key])) {
                $ops[] = ['op' => 'delete', 'target' => ['kind' => 'user', 'key' => $key]];
            }
        }
        return $ops;
    }

    /**
     * @param mixed $base
     * @param mixed $target
     * @return list<array<string, mixed>>
     */
    private static function diffComments(mixed $base, mixed $target, bool $deletes = true): array
    {
        $keyer = static fn (array $c): string => sha1(implode('|', [
            (string) ($c['post_ref'] ?? ''),
            (string) ($c['author_email'] ?? ''),
            (string) ($c['date_gmt'] ?? ''),
            (string) ($c['content'] ?? ''),
        ]));
        $baseMap = self::indexBy(is_array($base) ? $base : [], $keyer);
        $targetMap = self::indexBy(is_array($target) ? $target : [], $keyer);
        $ops = [];

        foreach ($targetMap as $key => $record) {
            if (!isset($baseMap[$key])) {
                $ops[] = ['op' => 'create', 'target' => ['kind' => 'comment', 'key' => $key], 'fields' => $record];
            }
        }
        foreach ($deletes ? $baseMap : [] as $key => $record) {
            if (!isset($targetMap[$key])) {
                $ops[] = ['op' => 'delete', 'target' => ['kind' => 'comment', 'key' => $key], 'fields' => $record];
            }
        }
        return $ops;
    }

    /**
     * Menus by slug (1.7); a menu is one record, its items included.
     *
     * @return list<array<string, mixed>>
     */
    private static function diffMenus(mixed $base, mixed $target, bool $deletes = true): array
    {
        $keyer = static fn (array $m): string => (string) ($m['slug'] ?? '');
        $baseMap = self::indexBy(is_array($base) ? $base : [], $keyer);
        $targetMap = self::indexBy(is_array($target) ? $target : [], $keyer);
        $ops = [];
        foreach ($targetMap as $key => $record) {
            if ($key === '') {
                continue;
            }
            $t = ['kind' => 'menu', 'key' => $key];
            if (!isset($baseMap[$key])) {
                $ops[] = ['op' => 'create', 'target' => $t, 'fields' => $record];
            } elseif (self::normalize($baseMap[$key]) !== self::normalize($record)) {
                $ops[] = ['op' => 'update', 'target' => $t, 'fields' => $record];
            }
        }
        foreach ($deletes ? $baseMap : [] as $key => $record) {
            if ($key !== '' && !isset($targetMap[$key])) {
                $ops[] = ['op' => 'delete', 'target' => ['kind' => 'menu', 'key' => $key]];
            }
        }
        return $ops;
    }

    /**
     * A `key => value` section (theme mods, 1.7) as operations of $kind;
     * a key the target lacks isn't deleted (a theme mod is reset, not removed).
     *
     * @return list<array<string, mixed>>
     */
    private static function diffKeyed(string $kind, mixed $base, mixed $target): array
    {
        $base = is_array($base) ? $base : [];
        $ops = [];
        foreach (is_array($target) ? $target : [] as $key => $value) {
            if (!array_key_exists($key, $base) || self::normalize($base[$key]) !== self::normalize($value)) {
                $ops[] = ['op' => 'update', 'target' => ['kind' => $kind, 'key' => (string) $key], 'fields' => ['value' => $value]];
            }
        }
        return $ops;
    }

    /**
     * @param mixed $base
     * @param mixed $target
     * @return list<array<string, mixed>>
     */
    private static function diffOptions(mixed $base, mixed $target, bool $deletes = true): array
    {
        $base = is_array($base) ? $base : [];
        $target = is_array($target) ? $target : [];
        $ops = [];

        foreach ($target as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $ops[] = ['op' => 'create', 'target' => ['kind' => 'option', 'key' => $key], 'fields' => ['value' => $value]];
            } elseif (self::normalize($base[$key]) !== self::normalize($value)) {
                $ops[] = ['op' => 'update', 'target' => ['kind' => 'option', 'key' => $key], 'fields' => ['value' => $value]];
            }
        }

        foreach ($deletes ? $base : [] as $key => $value) {
            if (!array_key_exists($key, $target)) {
                $ops[] = ['op' => 'delete', 'target' => ['kind' => 'option', 'key' => $key]];
            }
        }

        return $ops;
    }

    /**
     * @param mixed $base
     * @param mixed $target
     * @return list<array<string, mixed>>
     */
    private static function diffTerms(mixed $base, mixed $target, bool $deletes = true): array
    {
        $flatten = static function (mixed $terms): array {
            $flat = [];
            foreach ((is_array($terms) ? $terms : []) as $taxonomy => $rows) {
                foreach ((is_array($rows) ? $rows : []) as $row) {
                    if (is_array($row) && isset($row['slug'])) {
                        $flat[$taxonomy . '|' . $row['slug']] = ['taxonomy' => $taxonomy, 'row' => $row];
                    }
                }
            }
            return $flat;
        };

        $baseMap = $flatten($base);
        $targetMap = $flatten($target);
        $ops = [];

        foreach ($targetMap as $key => $entry) {
            $t = ['kind' => 'term', 'type' => $entry['taxonomy'], 'slug' => $entry['row']['slug']];
            if (!isset($baseMap[$key])) {
                $ops[] = ['op' => 'create', 'target' => $t, 'fields' => $entry['row']];
            } elseif (self::normalize($baseMap[$key]['row']) !== self::normalize($entry['row'])) {
                $ops[] = ['op' => 'update', 'target' => $t, 'fields' => $entry['row']];
            }
        }

        foreach ($deletes ? $baseMap : [] as $key => $entry) {
            if (!isset($targetMap[$key])) {
                $ops[] = ['op' => 'delete', 'target' => ['kind' => 'term', 'type' => $entry['taxonomy'], 'slug' => $entry['row']['slug']]];
            }
        }

        return $ops;
    }

    /**
     * @param list<mixed>            $rows
     * @param callable(array<string,mixed>): string $keyer
     * @return array<string, array<string, mixed>>
     */
    private static function indexBy(array $rows, callable $keyer): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $map[$keyer($row)] = $row;
            }
        }
        return $map;
    }

    /**
     * Stable, order-insensitive comparison key for a value.
     */
    private static function normalize(mixed $value): string
    {
        if (is_array($value)) {
            $copy = $value;
            self::ksortRecursive($copy);
            return (string) json_encode($copy);
        }
        return (string) json_encode($value);
    }

    /**
     * @param array<mixed> $array
     */
    private static function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);
        if (array_keys($array) !== range(0, count($array) - 1)) {
            ksort($array);
        }
    }
}
