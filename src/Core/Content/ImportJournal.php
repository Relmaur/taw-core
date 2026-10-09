<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots (see Exporter).

/**
 * What an import changed, so `content:import --undo` can reverse exactly
 * that (fidelity phase 7).
 *
 * While an import runs, every record it's about to change is captured once
 * ({@see self::touch()}, from WordPress's own "about to change" hooks, see
 * {@see WpRecords::watch()}), and every record it creates is noted
 * ({@see self::created()}). When the run ends, each record is captured again
 * and only what differs is kept: per value, before and after.
 *
 * Undo puts a value back only while it still equals what the import left:
 * an edit made since is kept, and counted. A record the import created is
 * deleted when it's unchanged since; one it deleted is recreated.
 *
 * Pure apart from the {@see RecordStore} it's given, so the logic is unit-
 * tested with a fake store.
 */
final class ImportJournal
{
    /** @var array<string, array{kind: string, id: int|string, before: ?array<string, mixed>, objects: bool}> "kind:id" => first capture */
    private array $touched = [];

    /** @var array<string, array{kind: string, id: int|string}> "kind:id" => record the import created */
    private array $created = [];

    /** Re-entrancy guard: capturing reads, but never while capturing. */
    private bool $capturing = false;

    public function __construct(private readonly RecordStore $store)
    {
    }

    /**
     * About to change a record: capture it, the first time only. A term
     * about to be deleted is captured with the posts it's assigned to.
     */
    public function touch(string $kind, int|string $id, bool $objects = false): void
    {
        $key = $kind . ':' . $id;
        if ($this->capturing || isset($this->created[$key]) || (isset($this->touched[$key]) && (!$objects || $this->touched[$key]['objects']))) {
            return;
        }
        $this->capturing = true;
        try {
            $this->touched[$key] = ['kind' => $kind, 'id' => $id, 'before' => $this->store->capture($kind, $id, $objects), 'objects' => $objects];
        } finally {
            $this->capturing = false;
        }
    }

    /** The import created this record: undo deletes it. */
    public function created(string $kind, int|string $id): void
    {
        $key = $kind . ':' . $id;
        unset($this->touched[$key]);
        $this->created[$key] = ['kind' => $kind, 'id' => $id];
    }

    /**
     * The journal: each record's change, from captures taken now.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $entries = [];
        $this->capturing = true;
        try {
            foreach ($this->touched as ['kind' => $kind, 'id' => $id, 'before' => $before]) {
                $after = $this->store->capture($kind, $id, false);
                if ($before === null) {
                    continue; // didn't exist and still doesn't, or a create noted separately
                }
                if ($after === null) {
                    $entries[] = ['kind' => $kind, 'id' => $id, 'action' => 'deleted', 'before' => $before];
                    continue;
                }
                $changes = self::diff($before, $after);
                if ($changes !== []) {
                    $entries[] = ['kind' => $kind, 'id' => $id, 'action' => 'updated', 'changes' => $changes];
                }
            }
            foreach ($this->created as ['kind' => $kind, 'id' => $id]) {
                $after = $this->store->capture($kind, $id, false);
                if ($after !== null) {
                    $entries[] = ['kind' => $kind, 'id' => $id, 'action' => 'created', 'after' => self::fingerprint($after)];
                }
            }
        } finally {
            $this->capturing = false;
        }

        return $entries;
    }

    /**
     * Reverse a journal's entries, newest first.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{restored: int, deleted: int, recreated: int, kept: list<string>}
     */
    public static function undo(array $entries, RecordStore $store): array
    {
        $result = ['restored' => 0, 'deleted' => 0, 'recreated' => 0, 'kept' => []];
        $isCreate = static fn (array $e): bool => ($e['action'] ?? '') === 'created';
        // Updates and deletes first, then what the import created, newest
        // first (a comment before its post).
        foreach (array_reverse(array_values(array_filter($entries, static fn (array $e): bool => !$isCreate($e)))) as $entry) {
            self::undoOne($entry, $store, $result);
        }
        foreach (array_reverse(array_values(array_filter($entries, $isCreate))) as $entry) {
            self::undoOne($entry, $store, $result);
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                                                           $entry
     * @param array{restored: int, deleted: int, recreated: int, kept: list<string>} $result
     */
    private static function undoOne(array $entry, RecordStore $store, array &$result): void
    {
        $kind = (string) ($entry['kind'] ?? '');
        $id = $entry['id'] ?? 0;
        $label = $kind . ':' . $id;
        $current = $store->capture($kind, $id, false);

        switch ($entry['action'] ?? '') {
            case 'created':
                if ($current === null) {
                    return; // already gone
                }
                if (self::fingerprint($current) !== ($entry['after'] ?? '')) {
                    $result['kept'][] = "{$label} (created by the import, edited since)";
                    return;
                }
                $store->delete($kind, $id);
                $result['deleted']++;
                return;

            case 'deleted':
                if ($current !== null) {
                    return; // back already
                }
                if ($store->recreate($kind, is_array($entry['before'] ?? null) ? $entry['before'] : [])) {
                    $result['recreated']++;
                }
                return;

            case 'updated':
                if ($current === null) {
                    $result['kept'][] = "{$label} (deleted since)";
                    return;
                }
                $flat = self::flatten($current);
                $restore = [];
                foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $path => $change) {
                    $now = $flat[$path] ?? null;
                    if (self::same($now, $change['after'] ?? null)) {
                        $restore[(string) $path] = $change['before'] ?? null;
                    } elseif (!self::same($now, $change['before'] ?? null)) {
                        $result['kept'][] = "{$label} {$path} (edited since)";
                    }
                }
                if ($restore !== []) {
                    $store->restore($kind, $id, $restore);
                    $result['restored'] += count($restore);
                }
                return;
        }
    }

    /**
     * Per value path (`fields.post_title`, `meta._taw_hero`,
     * `terms.category`), what changed: before and after; `null` = absent.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $a = self::flatten($before);
        $b = self::flatten($after);
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $path) {
            if (!self::same($a[$path] ?? null, $b[$path] ?? null)) {
                $out[$path] = ['before' => $a[$path] ?? null, 'after' => $b[$path] ?? null];
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * A capture as `section.key => value` (`objects` isn't compared).
     *
     * @param array<string, mixed> $capture
     * @return array<string, mixed>
     */
    public static function flatten(array $capture): array
    {
        $flat = [];
        foreach ($capture as $section => $values) {
            if ($section === 'objects' || !is_array($values)) {
                continue;
            }
            foreach ($values as $key => $value) {
                $flat[$section . '.' . $key] = $value;
            }
        }

        return $flat;
    }

    /** @param array<string, mixed> $capture */
    private static function fingerprint(array $capture): string
    {
        $flat = self::flatten($capture);
        ksort($flat);

        return sha1((string) json_encode($flat));
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return json_encode(self::sorted($a)) === json_encode(self::sorted($b));
        }
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sorted($v);
            }
        }

        return $value;
    }
}
