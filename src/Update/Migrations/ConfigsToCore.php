<?php

declare(strict_types=1);

namespace TAW\Update\Migrations;

use TAW\Update\ConfigFiles;
use TAW\Update\MigrationResult;

/**
 * taw/core 1.91.0: a classic theme's unedited vite.config.js / phpstan.neon
 * become short files that load taw/core's base (ConfigFiles). Edited ones are
 * left for a person, with the steps.
 */
final class ConfigsToCore implements \TAW\Update\Migration
{
    public function id(): string
    {
        return '1.91.0/configs';
    }

    public function title(): string
    {
        return "Load vite.config.js and phpstan.neon from taw/core's base";
    }

    public function themeKind(): string
    {
        return 'classic';
    }

    public function explain(): string
    {
        return <<<'TXT'
            What: replaces vite.config.js and phpstan.neon with short files that load taw/core's base
            (classicTheme() in vendor/taw/core/resources/vite/taw-vite.mjs, vendor/taw/core/resources/phpstan/
            classic.neon) and keep room for the site's own settings. Only a file identical to a version the
            scaffold once shipped is replaced; a file the site edited is left alone and listed below.

            Why: the build and analysis settings then update with composer update taw/core.

            By hand: vendor/bin/taw configs:sync --apply, then npm run build and composer run phpstan; commit.
            For an edited file: copy vendor/taw/core/resources/configs/classic/<file> over it and move the
            site's own settings into it (Vite: the object passed to mergeConfig; PHPStan: under parameters).

            Undo: git checkout <the commit before> -- vite.config.js phpstan.neon
            TXT;
    }

    /** Pending while there's a copy to convert or an edited file a person hasn't moved over yet. */
    public function pending(string $themeDir): bool
    {
        $plan = (new ConfigFiles($themeDir))->plan();

        return $plan['convert'] !== [] || $plan['custom'] !== [];
    }

    public function run(string $themeDir): MigrationResult
    {
        $configs = new ConfigFiles($themeDir);
        $plan = $configs->plan();
        $manual = [];
        foreach ($plan['custom'] as $path) {
            $manual[] = $path . ' has this site\'s own changes, so it was left as is. ' . ConfigFiles::BY_HAND[$path];
        }

        return new MigrationResult($configs->apply($plan), $manual);
    }
}
