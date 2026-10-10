<?php

declare(strict_types=1);

namespace TAW\Update;

use TAW\CLI\Application;

/**
 * Every migration, oldest first: taw/core's own, then any an extension adds
 * (the extension API will call add()). Runs the ones a theme still needs.
 */
final class Migrations
{
    /** @var list<Migration> */
    private static array $added = [];

    /** @return list<Migration> */
    public static function all(): array
    {
        return array_merge([
            new Migrations\SiteSkills(),
            new Migrations\AgentDocsToCore(),
            new Migrations\ConfigsToCore(),
        ], self::$added);
    }

    public static function add(Migration $migration): void
    {
        self::$added[] = $migration;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$added = [];
    }

    public static function find(string $id): ?Migration
    {
        foreach (self::all() as $migration) {
            if ($migration->id() === $id) {
                return $migration;
            }
        }

        return null;
    }

    /**
     * The migrations this theme still needs (its kind's and "any") and its
     * taw.json lets run.
     *
     * @return list<Migration>
     */
    public static function pending(string $themeDir): array
    {
        return self::split($themeDir)['pending'];
    }

    /**
     * The migrations this theme needs but its taw.json holds back (a
     * PolicyGated migration whose setting is off), with that setting.
     *
     * @return list<array{migration: Migration, setting: string}>
     */
    public static function held(string $themeDir): array
    {
        return self::split($themeDir)['held'];
    }

    /** @return array{pending: list<Migration>, held: list<array{migration: Migration, setting: string}>} */
    private static function split(string $themeDir): array
    {
        $kind = Application::isBlockTheme($themeDir) ? 'block' : 'classic';
        $policy = Policy::load($themeDir);
        $out = ['pending' => [], 'held' => []];
        foreach (self::all() as $m) {
            if (!in_array($m->themeKind(), [$kind, 'any'], true) || !$m->pending($themeDir)) {
                continue;
            }
            if ($m instanceof PolicyGated && !$policy->allows($m->policySetting())) {
                $out['held'][] = ['migration' => $m, 'setting' => $m->policySetting()];
            } else {
                $out['pending'][] = $m;
            }
        }

        return $out;
    }

    /** The line a person reads for a migration taw.json holds back. */
    public static function heldNote(Migration $m, string $setting): string
    {
        return $m->id() . ' is off in taw.json ("' . $setting . '": "off"), so it didn\'t run. To do it anyway: vendor/bin/taw upgrade --explain ' . $m->id() . ' (By hand).';
    }
}
