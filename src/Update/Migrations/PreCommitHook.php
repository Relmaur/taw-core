<?php

declare(strict_types=1);

namespace TAW\Update\Migrations;

use TAW\Update\MigrationResult;

/**
 * taw/core 1.94.0: the theme's git clone runs taw/core's pre-commit hook
 * (resources/git-hooks/pre-commit), which refuses a commit that would add a
 * database dump, a content snapshot or credentials. core.hooksPath points at
 * vendor/taw/core/resources/git-hooks, so the hook updates with taw/core.
 *
 * Git config is per clone and isn't committed: this runs in the clone where
 * the update runs (the owner's Mac), and changes no file. It never replaces
 * another hooks setup (a core.hooksPath of the site's own, or other hooks in
 * .git/hooks, which core.hooksPath would silently skip): those get a manual
 * step. The clone's own .git/hooks/pre-commit keeps running (the hook calls it).
 */
final class PreCommitHook implements \TAW\Update\Migration
{
    /** Where the hook lives, from the theme folder. */
    public const HOOKS_DIR = 'vendor/taw/core/resources/git-hooks';

    /** The check the hook runs, from the theme folder (for a pre-commit hook of the site's own). */
    public const CHECK = 'bash vendor/taw/core/resources/ci/check-committed-files.sh --staged';

    public function id(): string
    {
        return '1.94.0/pre-commit-hook';
    }

    public function title(): string
    {
        return "Refuse commits of dumps and credentials (git pre-commit hook from taw/core)";
    }

    public function themeKind(): string
    {
        return 'any';
    }

    public function explain(): string
    {
        return <<<'TXT'
            What: points this clone's git core.hooksPath at vendor/taw/core/resources/git-hooks. Its pre-commit
            hook refuses a commit that would add a database dump (*.sql, *.wpress), a content snapshot (.sync/),
            credentials (remote.env, .env, wp-config.php) or a private key: the same check as CI's
            committed-files job, before the commit exists. The clone's own .git/hooks/pre-commit still runs.
            Changes no file (git config is per clone). A clone with its own core.hooksPath, or with other hooks
            in .git/hooks, is left alone: add the check there instead.

            Why: a theme repository can be public, and CI only notices after the push has published it.

            By hand: git config core.hooksPath vendor/taw/core/resources/git-hooks (from the theme folder, when
            it is the repository's top), or call bash vendor/taw/core/resources/ci/check-committed-files.sh
            --staged from your own pre-commit hook.

            Undo: git config --unset core.hooksPath (CI's committed-files job still checks every push).
            TXT;
    }

    public function pending(string $themeDir): bool
    {
        $top = self::topLevel($themeDir);

        return $top !== null && is_file($themeDir . '/' . self::HOOKS_DIR . '/pre-commit')
            && self::git($top, ['config', '--get', 'core.hooksPath']) !== self::hooksPath($top, $themeDir);
    }

    public function run(string $themeDir): MigrationResult
    {
        $top = self::topLevel($themeDir);
        if ($top === null) {
            return new MigrationResult();
        }
        $wanted = self::hooksPath($top, $themeDir);
        $current = self::git($top, ['config', '--get', 'core.hooksPath']);
        if ($current !== null && $current !== '' && $current !== $wanted) {
            return new MigrationResult([], ["This clone's core.hooksPath is {$current}, the site's own hooks: taw/core's hook isn't installed. Add the check to that pre-commit hook, run from the theme folder: " . self::CHECK]);
        }
        $others = self::otherHooks($top);
        if ($others !== []) {
            return new MigrationResult([], ['.git/hooks has hooks of its own (' . implode(', ', $others) . '), which core.hooksPath would stop running: taw/core\'s hook isn\'t installed. Add the check to .git/hooks/pre-commit, run from the theme folder: ' . self::CHECK]);
        }
        self::git($top, ['config', 'core.hooksPath', $wanted]);

        return new MigrationResult();
    }

    /** The hooks folder as core.hooksPath takes it: relative to the repository top. */
    private static function hooksPath(string $top, string $themeDir): string
    {
        $theme = (string) realpath($themeDir);
        $relative = $theme === $top ? '' : ltrim(substr($theme, strlen($top)), '/') . '/';

        return $relative . self::HOOKS_DIR;
    }

    /** Hooks in the clone's .git/hooks other than the samples and a pre-commit (which the TAW hook calls). @return list<string> */
    private static function otherHooks(string $top): array
    {
        $dir = self::git($top, ['rev-parse', '--git-dir']);
        if ($dir === null) {
            return [];
        }
        $dir = str_starts_with($dir, '/') ? $dir : $top . '/' . $dir;
        $hooks = [];
        foreach (is_dir($dir . '/hooks') ? (scandir($dir . '/hooks') ?: []) : [] as $name) {
            if ($name[0] !== '.' && !str_ends_with($name, '.sample') && $name !== 'pre-commit' && is_file($dir . '/hooks/' . $name)) {
                $hooks[] = $name;
            }
        }

        return $hooks;
    }

    /** The repository's top folder, when the theme folder is inside one. */
    private static function topLevel(string $themeDir): ?string
    {
        $top = self::git($themeDir, ['rev-parse', '--show-toplevel']);

        return $top === null || $top === '' ? null : ((string) realpath($top) ?: $top);
    }

    /** @param list<string> $args */
    private static function git(string $dir, array $args): ?string
    {
        $command = 'git -C ' . escapeshellarg($dir) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
        exec($command, $out, $code);

        return $code === 0 ? trim(implode("\n", $out)) : null;
    }
}
