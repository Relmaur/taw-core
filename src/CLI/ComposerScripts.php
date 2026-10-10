<?php

declare(strict_types=1);

namespace TAW\CLI;

use TAW\Update\Migrations;

/**
 * Composer hooks, so `composer update` is the whole update (umbrella plan
 * docs/plans/taw-platform.md § 5.0). A TAW theme's composer.json lists:
 *
 *   "scripts": { "post-update-cmd": ["TAW\\CLI\\ComposerScripts::postUpdate"] }
 *
 * After every `composer update` it runs the migrations the theme still needs
 * (the same as `vendor/bin/taw upgrade --apply`): new site skills, docs and
 * configs from taw/core, whatever a release changes in a theme, as far as the
 * theme's taw.json allows (a held-back migration gets one line). Not after
 * `composer install`, which must reproduce the lock without changing files.
 *
 * Never breaks the update: a migration that fails is reported with the
 * command to run by hand. Turn it off with TAW_NO_UPGRADE=1, or
 * "extra": {"taw": {"upgrade": false}} in composer.json.
 *
 * Runs inside Composer's process, with the theme's autoloader loaded; the
 * event is Composer\Script\Event (not type-hinted: taw/core doesn't depend on
 * composer/composer).
 */
final class ComposerScripts
{
    /**
     * @param object $event Composer\Script\Event
     */
    public static function postUpdate(object $event): void
    {
        $io = method_exists($event, 'getIO') ? $event->getIO() : null;
        $say = static function (string $line) use ($io): void {
            if ($io !== null && method_exists($io, 'write')) {
                $io->write($line);
            } else {
                fwrite(STDOUT, $line . "\n");
            }
        };

        $themeDir = self::themeDir($event);
        if (getenv('TAW_NO_UPGRADE') === '1' || self::disabled($event)) {
            $say('<comment>TAW: migrations skipped (TAW_NO_UPGRADE or extra.taw.upgrade = false); run vendor/bin/taw upgrade --apply yourself.</comment>');

            return;
        }

        foreach (Migrations::held($themeDir) as $h) {
            $say('<comment>TAW:</comment> ' . Migrations::heldNote($h['migration'], $h['setting']));
        }
        $pending = Migrations::pending($themeDir);
        if ($pending === []) {
            return; // quiet when there's nothing to do
        }
        $say('<info>TAW: running ' . count($pending) . ' migration(s) for this theme (vendor/bin/taw upgrade --explain <id> says what each does):</info>');
        foreach ($pending as $migration) {
            try {
                $result = $migration->run($themeDir);
            } catch (\Throwable $e) {
                $say('  <error>✗ ' . $migration->id() . '</error> failed: ' . $e->getMessage());
                $say('    By hand: vendor/bin/taw upgrade --explain ' . $migration->id());

                continue;
            }
            $say('  ✓ ' . $migration->id() . '  ' . $migration->title() . ($result->changed !== [] ? ': ' . implode(', ', $result->changed) : ''));
            foreach ($result->manual as $step) {
                $say('    <comment>For you:</comment> ' . $step);
            }
        }
        $say('TAW: review the changes (git diff) and commit them with composer.lock.');
    }

    /** The theme: the folder holding vendor/ (Composer runs scripts from it). */
    private static function themeDir(object $event): string
    {
        if (method_exists($event, 'getComposer')) {
            $config = $event->getComposer()->getConfig();
            $vendor = $config->get('vendor-dir');
            if (is_string($vendor) && $vendor !== '') {
                return dirname($vendor);
            }
        }

        return (string) getcwd();
    }

    private static function disabled(object $event): bool
    {
        if (!method_exists($event, 'getComposer')) {
            return false;
        }
        $extra = $event->getComposer()->getPackage()->getExtra();

        return is_array($extra) && (($extra['taw']['upgrade'] ?? true) === false);
    }
}
