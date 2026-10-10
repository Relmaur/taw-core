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
     * The migrations this theme still needs (its kind's and "any").
     *
     * @return list<Migration>
     */
    public static function pending(string $themeDir): array
    {
        $kind = Application::isBlockTheme($themeDir) ? 'block' : 'classic';

        return array_values(array_filter(
            self::all(),
            fn (Migration $m) => in_array($m->themeKind(), [$kind, 'any'], true) && $m->pending($themeDir),
        ));
    }
}
