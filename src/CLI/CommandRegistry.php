<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;

/**
 * Commands added to `taw` from outside taw/core: an extension or a site.
 *
 * Registered as factories, so a command is only built for the theme the tool
 * runs in. The extension API (taw-platform plan, Phase 1) will call add()
 * from each extension's register(); a site can call it from a file its own
 * Composer autoload loads ("autoload": {"files": [...]}).
 *
 *   CommandRegistry::add(fn (string $themeDir) => new MyReportCommand($themeDir));
 *
 * Core commands keep their names: a registered command with a core
 * command's name is skipped.
 */
final class CommandRegistry
{
    /** @var list<callable(string): Command> */
    private static array $factories = [];

    /**
     * @param callable(string): Command $factory gets the theme folder, returns the command
     */
    public static function add(callable $factory): void
    {
        self::$factories[] = $factory;
    }

    /**
     * The registered commands, built for one theme folder.
     *
     * @return list<Command>
     */
    public static function commands(string $themeDir): array
    {
        $out = [];
        foreach (self::$factories as $factory) {
            $out[] = $factory($themeDir);
        }

        return $out;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$factories = [];
    }
}
