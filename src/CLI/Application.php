<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use TAW\Helpers\Framework;

/**
 * The `taw` command-line tool, as taw/core ships it (`vendor/bin/taw`).
 *
 * Until v1.90 every theme carried its own `bin/taw` listing ~28 commands,
 * synced from the scaffold with `rsync --delete`: a site couldn't add a
 * command, and a new command needed a scaffold release. Now the list lives
 * here, updates with `composer update taw/core`, and anything else (an
 * extension, a site) adds commands through CommandRegistry.
 *
 * Classic themes (taw-theme) get every command; block themes (taw-gutenberg:
 * theme.json + templates/) get the ones that work without the classic
 * scaffold — the same split their own bin/taw files had.
 */
final class Application
{
    public const NAME = 'TAW CLI';

    /**
     * The tool for one theme folder, every command registered.
     */
    public static function create(string $themeDir): ConsoleApplication
    {
        $app = new ConsoleApplication(self::NAME, self::version());
        foreach (self::coreCommands($themeDir) as $command) {
            $app->add($command);
        }
        foreach (CommandRegistry::commands($themeDir) as $command) {
            if (!$app->has((string) $command->getName())) { // a core command's name stays core's
                $app->add($command);
            }
        }

        return $app;
    }

    /**
     * Runs the tool: the entry point of `vendor/bin/taw` and the themes' bin/taw.
     */
    public static function main(string $themeDir): int
    {
        Framework::setThemeRoot($themeDir);
        $app = self::create($themeDir);
        $app->setAutoExit(false);

        return $app->run();
    }

    /**
     * Whether the folder is a block (FSE) theme: theme.json and templates/,
     * as WordPress itself decides.
     */
    public static function isBlockTheme(string $themeDir): bool
    {
        return is_file($themeDir . '/theme.json') && is_dir($themeDir . '/templates');
    }

    /**
     * taw/core's own commands for this theme.
     *
     * @return list<Command>
     */
    public static function coreCommands(string $themeDir): array
    {
        $everywhere = [
            new SchemaValidateCommand($themeDir),
            new SkillsSyncCommand($themeDir),
        ];
        if (self::isBlockTheme($themeDir)) {
            return $everywhere;
        }

        return array_merge([
            new MakeBlockCommand($themeDir),
            new ExportBlockCommand($themeDir),
            new ImportBlockCommand($themeDir),
            new InspectCommand($themeDir),
            new FieldsGetCommand($themeDir),
            new FieldsSetCommand($themeDir),
            new SyncCommand($themeDir),
            new ExportStaticCommand($themeDir),
            new SeoExtractCommand($themeDir),
            new SeoInjectCommand($themeDir),
            new WpCliCommand($themeDir),
            new IconsSyncCommand(),
            new LogTailCommand($themeDir),
            new ContentExportCommand($themeDir),
            new ContentImportCommand($themeDir),
            new ContentDiffCommand($themeDir),
            new ContentDefaultsCommand($themeDir),
            new ContentReindexCommand($themeDir),
            new ContentReindexKbCommand($themeDir),
            new RagUsageCommand($themeDir),
            new CorpusInstallCommand($themeDir),
            new CorpusExportCommand(),
            new CatechismInstallCommand($themeDir),
            new CatechismExportCommand(),
            new CanonLawInstallCommand($themeDir),
            new CanonLawExportCommand(),
        ], $everywhere);
    }

    /**
     * Where the theme is, for `vendor/bin/taw`: $TAW_THEME_DIR, else the
     * project that installed taw/core (the folder holding vendor/).
     *
     * @param string|null $autoloadPath Composer's autoload.php, as its bin proxy passes it
     */
    public static function themeDir(?string $autoloadPath, string $cwd): string
    {
        $env = getenv('TAW_THEME_DIR');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        if ($autoloadPath !== null && $autoloadPath !== '') {
            return dirname($autoloadPath, 2); // <theme>/vendor/autoload.php
        }
        // Run from taw/core's own checkout or an unusual layout: the nearest
        // folder up from here with a vendor/autoload.php.
        for ($dir = $cwd; $dir !== dirname($dir); $dir = dirname($dir)) {
            if (is_file($dir . '/vendor/autoload.php')) {
                return $dir;
            }
        }

        return $cwd;
    }

    private static function version(): string
    {
        if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('taw/core')) {
            return (string) \Composer\InstalledVersions::getPrettyVersion('taw/core');
        }

        return 'dev';
    }
}
