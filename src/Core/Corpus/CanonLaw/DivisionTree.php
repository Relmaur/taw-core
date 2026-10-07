<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

/**
 * Folds flat division rows into {@see CanonLawReaderInterface}'s tree and
 * breadcrumbs. Shared by both readers — unlike the Bible/Catechism
 * readers' deliberately duplicated row folding, the inputs here are
 * identical plain rows from either backend (no FTS/FULLTEXT difference
 * reaches this far), so a single pure implementation is the simpler one.
 *
 * Expected row keys: `id`, `parent_id` (null for a root), `kind`, `title`,
 * `order`, `canon_count` (direct), `canon_min`/`canon_max` (direct, null
 * when `canon_count` is 0).
 *
 * @phpstan-import-type Division from CanonLawReaderInterface
 * @phpstan-import-type Crumb from CanonLawReaderInterface
 */
final class DivisionTree
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Division>
     */
    public static function build(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $byParent[$parent][] = $row;
        }

        return self::children($byParent, 0);
    }

    /**
     * Ancestors of `$divisionId`, root first, excluding the division itself.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Crumb>
     */
    public static function breadcrumb(array $rows, int $divisionId): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $crumbs = [];
        $seen = [];
        $current = $byId[$divisionId]['parent_id'] ?? null;
        while ($current !== null && isset($byId[(int) $current]) && !isset($seen[(int) $current])) {
            $seen[(int) $current] = true;
            $row = $byId[(int) $current];
            array_unshift($crumbs, self::crumb($row));
            $current = $row['parent_id'];
        }

        return $crumbs;
    }

    /**
     * @param array<string, mixed> $row
     * @return Crumb
     */
    public static function crumb(array $row): array
    {
        return ['id' => (int) $row['id'], 'kind' => (string) $row['kind'], 'title' => (string) $row['title']];
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byParent
     * @return list<Division>
     */
    private static function children(array $byParent, int $parentId, int $depth = 0): array
    {
        // The source tree is six levels deep; the guard only stops a
        // corrupt parent cycle from recursing forever.
        if ($depth > 32) {
            return [];
        }

        $rows = $byParent[$parentId] ?? [];
        usort($rows, static fn (array $a, array $b): int => [(int) $a['order'], (int) $a['id']] <=> [(int) $b['order'], (int) $b['id']]);

        $nodes = [];
        foreach ($rows as $row) {
            $children = self::children($byParent, (int) $row['id'], $depth + 1);
            $count = (int) $row['canon_count'];

            $from = $count > 0 ? (int) $row['canon_min'] : PHP_INT_MAX;
            $to = $count > 0 ? (int) $row['canon_max'] : 0;
            foreach ($children as $child) {
                $from = min($from, $child['canon_from']);
                $to = max($to, $child['canon_to']);
            }

            if ($count === 0 && $children === []) {
                continue;
            }

            $nodes[] = [
                'id' => (int) $row['id'],
                'kind' => (string) $row['kind'],
                'title' => (string) $row['title'],
                'order' => (int) $row['order'],
                'canon_count' => $count,
                'canon_from' => $from,
                'canon_to' => $to,
                'children' => $children,
            ];
        }

        return $nodes;
    }
}
