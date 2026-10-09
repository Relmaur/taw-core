<?php

declare(strict_types=1);

namespace TAW\CLI;

/**
 * Rule-based merge suggestions for the structured Tier 2 manifests
 * (`composer.json`, `package.json`), so a sync never needs judgment to tell
 * the scaffold's real changes from a client's own dependencies.
 *
 * A raw `diff -u` against the scaffold shows every site-only dependency as a
 * removal. These are additive by nature (a client's `emailit`, `photoswipe`,
 * `taw/hub-companion`…), so the plan only ever looks at what the scaffold has
 * and the site doesn't, in the sections the rules list:
 *
 * - `add`: a key or repository the site lacks;
 * - `bump`: a dependency whose scaffold constraint has a higher lower bound;
 * - `review`: any other difference (a script, an autoload path, a constraint
 *   that can't be compared) — for a person to decide;
 * - `optional`: lines of an optional feature (Reactiph, the chatbot), never
 *   suggested on an existing site;
 * - `site_only`: how many keys only the site has; they're always kept.
 *
 * Nothing is ever removed, and sections outside the rules (`name`, `extra`…)
 * are never read. `apply()` writes only `add` and `bump`.
 *
 * Rules come from `update-manifest.json` § manifestMerge:
 * `{sections: [...], dependencySections: [...], optional: {feature: [label…]}}`.
 * Labels are dot paths (`require.reactiph/taw-bridge`, `minimum-stability`)
 * or `repositories[<url>]`.
 */
final class ManifestMerge
{
    /**
     * @param array<string, mixed> $local
     * @param array<string, mixed> $canonical
     * @param array{sections?: list<string>, dependencySections?: list<string>, optional?: array<string, list<string>>} $rules
     * @return array{add: list<array<string, mixed>>, bump: list<array<string, mixed>>, review: list<array<string, mixed>>, optional: list<array<string, mixed>>, site_only: int}
     */
    public static function plan(array $local, array $canonical, array $rules): array
    {
        $plan = ['add' => [], 'bump' => [], 'review' => [], 'optional' => [], 'site_only' => 0];
        $optional = [];
        foreach ($rules['optional'] ?? [] as $feature => $labels) {
            foreach ($labels as $label) {
                $optional[$label] = (string) $feature;
            }
        }
        $deps = $rules['dependencySections'] ?? [];

        foreach ($rules['sections'] ?? [] as $section) {
            if (!array_key_exists($section, $canonical)) {
                continue;
            }
            $has = array_key_exists($section, $local);
            if ($section === 'repositories') {
                self::repositories($has ? $local[$section] : [], $canonical[$section], $optional, $plan);
                continue;
            }
            self::compare([$section], $has, $has ? $local[$section] : null, $canonical[$section], in_array($section, $deps, true), $optional, $plan);
        }

        return $plan;
    }

    /**
     * The local data with the plan's `add` and `bump` items written in. New
     * keys go at the end of their section; existing keys keep their place.
     *
     * @param array<string, mixed> $local
     * @param array{add: list<array<string, mixed>>, bump: list<array<string, mixed>>} $plan
     * @return array<string, mixed>
     */
    public static function apply(array $local, array $plan): array
    {
        foreach (array_merge($plan['add'], $plan['bump']) as $item) {
            if (isset($item['repository'])) {
                $repos = isset($local['repositories']) && is_array($local['repositories']) ? $local['repositories'] : [];
                $repos[] = $item['repository'];
                $local['repositories'] = $repos;
                continue;
            }
            /** @var list<string> $path */
            $path = $item['path'];
            self::set($local, $path, $item['to']);
        }

        return $local;
    }

    /**
     * Encode data the way the original file was written: its indentation and
     * its final newline.
     *
     * @param array<string, mixed> $data
     */
    public static function encode(array $data, string $original): string
    {
        $indent = '    ';
        if (preg_match('/^\{\R([ \t]+)"/', $original, $m) === 1) {
            $indent = $m[1];
        }
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($indent !== '    ') {
            $json = (string) preg_replace_callback('/^((?:    )+)/m', static fn (array $m): string => str_repeat($indent, intdiv(strlen($m[1]), 4)), $json);
        }

        return $json . (str_ends_with($original, "\n") ? "\n" : '');
    }

    /**
     * @param list<string> $path
     * @param array<string, string> $optional label → feature
     * @param array{add: list<array<string, mixed>>, bump: list<array<string, mixed>>, review: list<array<string, mixed>>, optional: list<array<string, mixed>>, site_only: int} $plan
     */
    private static function compare(array $path, bool $has, mixed $local, mixed $canonical, bool $dependency, array $optional, array &$plan): void
    {
        $label = implode('.', $path);

        if (!$has) {
            self::put($plan, 'add', ['path' => $path, 'label' => $label, 'to' => $canonical], $optional);
            return;
        }
        if (is_array($canonical) && is_array($local) && self::isMap($canonical) && self::isMap($local)) {
            foreach ($canonical as $key => $value) {
                $key = (string) $key;
                self::compare([...$path, $key], array_key_exists($key, $local), $local[$key] ?? null, $value, $dependency, $optional, $plan);
            }
            foreach (array_keys($local) as $key) {
                if (!array_key_exists($key, $canonical)) {
                    $plan['site_only']++;
                }
            }
            return;
        }
        if ($local === $canonical) {
            return;
        }

        $item = ['path' => $path, 'label' => $label, 'from' => $local, 'to' => $canonical];
        if ($dependency && count($path) === 2 && is_string($local) && is_string($canonical)) {
            $order = self::compareConstraints($local, $canonical);
            if ($order === null) {
                self::put($plan, 'review', $item + ['reason' => 'constraints can\'t be compared'], $optional);
            } elseif ($order < 0) {
                self::put($plan, 'bump', $item, $optional);
            }
            // The site's constraint is equal or newer: nothing to suggest.
            return;
        }
        self::put($plan, 'review', $item, $optional);
    }

    /**
     * Repositories are a list: matched by URL (or by the whole entry when it
     * has none). Ones the site lacks are added.
     *
     * @param array<string, string> $optional
     * @param array{add: list<array<string, mixed>>, bump: list<array<string, mixed>>, review: list<array<string, mixed>>, optional: list<array<string, mixed>>, site_only: int} $plan
     */
    private static function repositories(mixed $local, mixed $canonical, array $optional, array &$plan): void
    {
        if (!is_array($canonical)) {
            return;
        }
        $local = is_array($local) ? $local : [];
        $key = static fn (mixed $repo): string => is_array($repo) && isset($repo['url']) && is_string($repo['url'])
            ? $repo['url'] : (string) json_encode($repo);
        $have = array_map($key, $local);
        foreach ($canonical as $repo) {
            $id = $key($repo);
            if (!in_array($id, $have, true)) {
                self::put($plan, 'add', ['repository' => $repo, 'label' => 'repositories[' . $id . ']'], $optional);
            }
        }
        $want = array_map($key, $canonical);
        foreach ($have as $id) {
            if (!in_array($id, $want, true)) {
                $plan['site_only']++;
            }
        }
    }

    /**
     * @param array{add: list<array<string, mixed>>, bump: list<array<string, mixed>>, review: list<array<string, mixed>>, optional: list<array<string, mixed>>, site_only: int} $plan
     * @param 'add'|'bump'|'review' $bucket
     * @param array<string, mixed> $item
     * @param array<string, string> $optional
     */
    private static function put(array &$plan, string $bucket, array $item, array $optional): void
    {
        $feature = $optional[(string) $item['label']] ?? null;
        if ($feature !== null) {
            $plan['optional'][] = $item + ['feature' => $feature];
            return;
        }
        $plan[$bucket][] = $item;
    }

    /**
     * Compares two version constraints by their first version number
     * (`^1.2`, `>=8.2`, `~2.0.1`, `5.4.4`): <0 when the scaffold's is
     * higher, 0 or >0 otherwise, null when either has no version (`*`,
     * `dev-main`).
     */
    private static function compareConstraints(string $local, string $canonical): ?int
    {
        $a = self::lowerBound($local);
        $b = self::lowerBound($canonical);
        if ($a === null || $b === null) {
            return null;
        }

        return $a <=> $b;
    }

    /**
     * @return list<int>|null
     */
    private static function lowerBound(string $constraint): ?array
    {
        if (preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $constraint, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0)];
    }

    private static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     */
    private static function set(array &$data, array $path, mixed $value): void
    {
        $key = array_shift($path);
        if ($key === null) {
            return;
        }
        if ($path === []) {
            $data[$key] = $value;
            return;
        }
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
        /** @var array<string, mixed> $child */
        $child = &$data[$key];
        self::set($child, $path, $value);
    }
}
