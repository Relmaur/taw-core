<?php

declare(strict_types=1);

namespace TAW\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

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

        array_push($operations, ...self::diffPosts($base['posts'] ?? [], $target['posts'] ?? []));
        array_push($operations, ...self::diffOptions($base['options'] ?? [], $target['options'] ?? []));
        array_push($operations, ...self::diffTerms($base['terms'] ?? [], $target['terms'] ?? []));

        return [
            'taw_changeset' => [
                'schema' => (string) (($target['meta']['schema'] ?? $base['meta']['schema'] ?? Exporter::SCHEMA_VERSION)),
                'base'   => $base['meta']['source'] ?? null,
                'target' => $target['meta']['source'] ?? null,
            ],
            'operations' => $operations,
        ];
    }

    /**
     * @param mixed $base
     * @param mixed $target
     * @return list<array<string, mixed>>
     */
    private static function diffPosts(mixed $base, mixed $target): array
    {
        $baseMap = self::indexBy(is_array($base) ? $base : [], static fn (array $p): string => ($p['type'] ?? '') . '|' . ($p['slug'] ?? ''));
        $targetMap = self::indexBy(is_array($target) ? $target : [], static fn (array $p): string => ($p['type'] ?? '') . '|' . ($p['slug'] ?? ''));

        $ops = [];

        foreach ($targetMap as $key => $record) {
            $t = ['kind' => 'post', 'type' => $record['type'] ?? null, 'slug' => $record['slug'] ?? null];
            if (!isset($baseMap[$key])) {
                $ops[] = ['op' => 'create', 'target' => $t, 'post' => $record];
                continue;
            }
            if (self::normalize($baseMap[$key]) !== self::normalize($record)) {
                $ops[] = ['op' => 'update', 'target' => $t, 'post' => $record];
            }
        }

        foreach ($baseMap as $key => $record) {
            if (!isset($targetMap[$key])) {
                $ops[] = [
                    'op'     => 'delete',
                    'target' => ['kind' => 'post', 'type' => $record['type'] ?? null, 'slug' => $record['slug'] ?? null],
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
    private static function diffOptions(mixed $base, mixed $target): array
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

        foreach ($base as $key => $value) {
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
    private static function diffTerms(mixed $base, mixed $target): array
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

        foreach ($baseMap as $key => $entry) {
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
