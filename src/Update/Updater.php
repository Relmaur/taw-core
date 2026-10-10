<?php

declare(strict_types=1);

namespace TAW\Update;

use TAW\CLI\Application;

/**
 * The whole update of one theme, as its taw.json says, with no questions
 * (umbrella plan docs/plans/taw-platform.md § 5): on a new branch, update
 * taw/core, apply the framework files, run the migrations, run the checks,
 * commit, then deliver (a branch, a pull request, or one that merges itself
 * when CI passes). `bin/taw update`, the weekly CI job and taw-fleet's
 * "Update this site" all run this.
 *
 * Every step after Composer runs through the *new* vendor/bin/taw in its own
 * process: this class was loaded from the taw/core being replaced.
 *
 * A failed step never goes further than a commit on the branch: nothing is
 * pushed. The result (Report::markdown) is the step-by-step guide for a
 * person and the prompt for "Fix with Claude" alike (umbrella ADR-0004).
 */
final class Updater
{
    public const REPORT = '.taw/update-report';

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $now;

    /**
     * @param list<string> $composer how to run Composer, e.g. ['composer'] or [php, composer.phar]
     * @param (\Closure(): \DateTimeImmutable)|null $now
     */
    public function __construct(
        private string $themeDir,
        private Shell $shell,
        private array $composer = ['composer'],
        ?\Closure $now = null,
        private string $php = PHP_BINARY,
    ) {
        $this->now = $now ?? static fn () => new \DateTimeImmutable();
    }

    /**
     * @return array<string, mixed> the result Report renders (status: updated | up-to-date | failed | refused)
     */
    public function run(bool $deliver = true): array
    {
        $policy = Policy::load($this->themeDir);
        $result = [
            'site' => basename($this->themeDir),
            'status' => 'refused',
            'policy' => $policy->toArray(),
            'steps' => [],
            'changed' => [],
            'migrations' => [],
            'manual' => [],
            'checks' => [],
            'failure' => null,
            'core' => ['from' => $this->installedCore(), 'to' => null],
        ];

        // Preflight: never start on top of someone's unfinished work.
        if (!$policy->valid()) {
            return $this->refuse($result, Policy::FILE . ' has problems, so nothing was changed: ' . implode('; ', $policy->errors()) . '. Fix it (vendor/bin/taw policy lists them), commit, and run the update again.');
        }
        if ($this->git(['rev-parse', '--is-inside-work-tree'])['code'] !== 0) {
            return $this->refuse($result, 'The theme folder isn\'t a git repository, so an update can\'t be put on its own branch. Run git init, commit, then update.');
        }
        if ($this->dirty() !== []) {
            return $this->refuse($result, 'The theme has uncommitted changes (' . implode(', ', array_slice($this->dirty(), 0, 5)) . '), so nothing was changed. Commit or stash them (git status shows them), then run the update again.');
        }
        if (($waiting = $this->waitingUpdate()) !== null) {
            return $this->refuse($result, "An earlier update is still waiting on {$waiting} (not merged yet), so a second one wasn't started. Merge its pull request (M in taw-fleet, or on GitHub), or drop it: git branch -D {$waiting} and git push origin --delete {$waiting}. Then run the update again.");
        }
        $base = trim($this->git(['rev-parse', '--abbrev-ref', 'HEAD'])['out']);
        if ($base === '' || $base === 'HEAD') {
            return $this->refuse($result, 'The theme isn\'t on a branch (a detached HEAD). Check out its main branch, then run the update again.');
        }
        $branch = 'taw/update-' . ($this->now)()->format('Ymd-His');
        $result['base'] = $base;
        $result['branch'] = $branch;
        if (!$this->step($result, 'branch', ['git', 'checkout', '-b', $branch])) {
            return $this->fail($result, 'branch');
        }

        // 1. taw/core, within the policy's range. The theme's own post-update hook
        // is held back: the migrations run below, through the new taw/core.
        $core = $this->coreCommand($policy, (string) $result['core']['from']);
        if ($core !== null && !$this->step($result, 'composer', array_merge($this->composer, $core), ['TAW_NO_UPGRADE' => '1'])) {
            return $this->fail($result, 'composer');
        }
        $result['core']['to'] = $this->installedCore();

        // 2. Framework files (classic themes: the scaffold's Tier 1, and the
        // composer.json/package.json additions when the policy allows).
        $classic = !Application::isBlockTheme($this->themeDir);
        if ($classic && $policy->scaffold()) {
            $sync = ['sync', '--apply', '--json'];
            if ($policy->manifests()) {
                $sync[] = '--apply-manifests';
            }
            $out = $this->taw($result, 'sync', $sync);
            if ($out === null) {
                return $this->fail($result, 'sync');
            }
            $packages = self::addedPackages($out);
            if ($packages !== [] && !$this->step($result, 'composer', array_merge($this->composer, ['update', '--with-dependencies', '--no-interaction'], $packages), ['TAW_NO_UPGRADE' => '1'])) {
                return $this->fail($result, 'composer');
            }
        }

        // 3. Migrations, from the taw/core just installed.
        $upgrade = $this->taw($result, 'migration', ['upgrade', '--apply', '--json']);
        if ($upgrade === null) {
            return $this->fail($result, 'migration');
        }
        $result['migrations'] = array_keys(is_array($upgrade['applied'] ?? null) ? $upgrade['applied'] : []);
        $result['manual'] = is_array($upgrade['manual'] ?? null) ? array_values($upgrade['manual']) : [];

        // 4. Checks.
        $failed = null;
        foreach ($policy->checks() as $check) {
            $outcome = $this->check($check);
            $result['checks'][] = $outcome;
            if ($outcome['status'] === 'fail' && $failed === null) {
                $failed = $check;
            }
        }

        // 5. Commit what changed (the report files stay out of it).
        $this->git(['add', '-A', '--', '.', ':!' . self::REPORT . '.md', ':!' . self::REPORT . '.json']);
        $result['changed'] = array_values(array_filter(explode("\n", trim($this->git(['diff', '--cached', '--name-only'])['out']))));
        if ($result['changed'] === []) {
            $this->git(['checkout', $base]);
            $this->git(['branch', '-D', $branch]);
            $result['status'] = 'up-to-date';
            unset($result['branch']);

            return $this->write($result);
        }
        $this->git(['commit', '-m', self::commitMessage($result), '--no-verify']);

        if ($failed !== null) {
            return $this->fail($result, $failed);
        }
        $result['status'] = 'updated';
        $result['delivered'] = $deliver ? $this->deliver($result, $policy->deliver()) : ['how' => 'branch', 'note' => 'Not delivered (--no-deliver): the update is committed on ' . $branch . '.'];

        return $this->write($result);
    }

    /**
     * The Composer command for taw/core's range, or null when a pin already holds.
     *
     * @return list<string>|null
     */
    private function coreCommand(Policy $policy, string $installed): ?array
    {
        if (($pin = $policy->pinnedVersion()) !== null) {
            return ltrim($installed, 'v') === $pin ? null : ['require', 'taw/core:' . $pin, '--update-with-dependencies', '--no-interaction'];
        }
        $command = ['update', 'taw/core', '--with-dependencies', '--no-interaction'];
        if ($policy->core() === 'patch' && $installed !== '') {
            $command[] = '--with=taw/core:' . $policy->coreConstraint($installed);
        }

        return $command;
    }

    /**
     * One check, run here. A check that can't run here (no front-end
     * dependencies installed, the WordPress smoke test) is skipped with the
     * reason, never counted as passed.
     *
     * @return array{name: string, status: string, command: string, out: string, reason?: string}
     */
    private function check(string $check): array
    {
        $scripts = self::scripts($this->themeDir . '/composer.json');
        $npm = self::scripts($this->themeDir . '/package.json');
        $skip = fn (string $reason, string $command = '') => ['name' => $check, 'status' => 'skip', 'command' => $command, 'out' => '', 'reason' => $reason];

        switch ($check) {
            case 'lint':
                foreach ($this->phpFiles() as $file) {
                    $r = $this->shell->run([$this->php, '-l', $file], $this->themeDir);
                    if ($r['code'] !== 0) {
                        return ['name' => 'lint', 'status' => 'fail', 'command' => 'php -l ' . $file, 'out' => self::tail($r['out'])];
                    }
                }

                return ['name' => 'lint', 'status' => 'pass', 'command' => 'php -l (every theme file)', 'out' => ''];
            case 'phpstan':
            case 'test':
                if (!isset($scripts[$check])) {
                    return $skip('composer.json has no "' . $check . '" script');
                }
                $command = array_merge($this->composer, ['run', $check]);
                break;
            case 'build':
                $script = isset($npm['check']) ? 'check' : (isset($npm['build']) ? 'build' : null);
                if ($script === null) {
                    return $skip('package.json has no check or build script');
                }
                if (!is_dir($this->themeDir . '/node_modules')) {
                    return $skip('node_modules isn\'t installed here: run npm ci, then npm run ' . $script . ' (CI runs it on the pull request)', 'npm run ' . $script);
                }
                $command = ['npm', 'run', $script];
                break;
            case 'smoke':
                return $skip('needs WordPress running: the pull request\'s CI runs it (theme-ci smoke job)');
            default:
                return $skip('unknown check');
        }
        $r = $this->shell->run($command, $this->themeDir);

        return ['name' => $check, 'status' => $r['code'] === 0 ? 'pass' : 'fail', 'command' => implode(' ', $command), 'out' => $r['code'] === 0 ? '' : self::tail($r['out'])];
    }

    /**
     * Pushes the branch and opens the pull request (and asks GitHub to merge it
     * when CI passes, for pr+merge). Falls back to "on the branch" with the
     * steps when there's no remote or no gh.
     *
     * @param array<string, mixed> $result
     * @return array{how: string, url?: string, note: string}
     */
    private function deliver(array $result, string $how): array
    {
        $branch = (string) $result['branch'];
        if ($how === 'branch') {
            return ['how' => 'branch', 'note' => 'Committed on ' . $branch . ' (taw.json: deliver = branch). Push it and open a pull request when you\'re ready.'];
        }
        if ($this->git(['push', '-u', 'origin', $branch])['code'] !== 0) {
            return ['how' => 'branch', 'note' => 'Committed on ' . $branch . ', but it couldn\'t be pushed (no "origin" remote, or no access). Push it yourself: git push -u origin ' . $branch . ', then open a pull request.'];
        }
        $this->write($result + ['status' => 'updated']); // the PR body
        $pr = $this->shell->run(['gh', 'pr', 'create', '--base', (string) $result['base'], '--head', $branch, '--title', self::title($result), '--body-file', $this->themeDir . '/' . self::REPORT . '.md'], $this->themeDir);
        if ($pr['code'] !== 0) {
            return ['how' => 'branch', 'note' => 'Pushed ' . $branch . ', but the pull request couldn\'t be opened (the GitHub CLI, gh, is missing or not logged in). Open it on GitHub; the description is in ' . self::REPORT . '.md.'];
        }
        $url = trim((string) strrchr(trim($pr['out']), "\n") ?: trim($pr['out']));
        if ($how === 'pr+merge') {
            $merge = $this->shell->run(['gh', 'pr', 'merge', $url, '--auto', '--squash'], $this->themeDir);
            if ($merge['code'] !== 0) {
                return ['how' => 'pr', 'url' => $url, 'note' => 'Pull request opened; it couldn\'t be set to merge by itself (auto-merge is off for this repository). Merge it when CI passes: M in taw-fleet, or gh pr merge ' . $url . '. Merging deploys production.'];
            }

            return ['how' => 'pr+merge', 'url' => $url, 'note' => 'Pull request opened; it merges by itself when CI passes, and merging deploys production.'];
        }

        return ['how' => 'pr', 'url' => $url, 'note' => 'Pull request opened for you to merge (M in taw-fleet, or on GitHub). Merging deploys production.'];
    }

    // --- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed> $result
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function step(array &$result, string $name, array $command, array $env = []): bool
    {
        $r = $this->shell->run($command, $this->themeDir, $env);
        $result['steps'][] = ['name' => $name, 'command' => implode(' ', $command), 'ok' => $r['code'] === 0, 'out' => $r['code'] === 0 ? '' : self::tail($r['out'])];

        return $r['code'] === 0;
    }

    /**
     * Runs vendor/bin/taw (the taw/core installed now) and reads its JSON.
     *
     * @param array<string, mixed> $result
     * @param list<string> $args
     * @return array<string, mixed>|null null when it failed
     */
    private function taw(array &$result, string $name, array $args): ?array
    {
        $command = array_merge([$this->php, $this->themeDir . '/vendor/bin/taw'], $args);
        $r = $this->shell->run($command, $this->themeDir);
        $json = json_decode(trim($r['out']), true);
        $ok = is_array($json) && ($name !== 'sync' || ($json['errors'] ?? []) === []);
        $result['steps'][] = ['name' => $name, 'command' => 'vendor/bin/taw ' . implode(' ', $args), 'ok' => $ok, 'out' => $ok ? '' : self::tail($r['out'])];

        return $ok ? $json : null;
    }

    /**
     * @param list<string> $args
     * @return array{code: int, out: string}
     */
    private function git(array $args): array
    {
        return $this->shell->run(array_merge(['git'], $args), $this->themeDir);
    }

    /**
     * An earlier update branch that hasn't landed: on the remote when there is
     * one (merged pull requests delete theirs), else locally among the
     * branches not merged into this one.
     */
    private function waitingUpdate(): ?string
    {
        $remote = $this->git(['ls-remote', '--heads', 'origin', 'taw/update-*']);
        if ($remote['code'] === 0) {
            return preg_match('#refs/heads/(taw/update-\S+)#', $remote['out'], $m) === 1 ? $m[1] : null;
        }
        $local = trim($this->git(['branch', '--list', 'taw/update-*', '--no-merged', 'HEAD', '--format=%(refname:short)'])['out']);

        return $local === '' ? null : strtok($local, "\n");
    }

    /** @return list<string> uncommitted paths, the update reports aside */
    private function dirty(): array
    {
        $lines = array_filter(explode("\n", $this->git(['status', '--porcelain'])['out']));

        return array_values(array_filter(
            array_map(fn (string $l) => trim(substr($l, 3)), $lines),
            fn (string $p) => !str_starts_with($p, '.taw/'),
        ));
    }

    private function installedCore(): string
    {
        $file = $this->themeDir . '/vendor/composer/installed.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        foreach ((is_array($data) ? ($data['packages'] ?? $data) : []) as $package) {
            if (is_array($package) && ($package['name'] ?? '') === 'taw/core') {
                return (string) ($package['version'] ?? '');
            }
        }

        return '';
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];
        $skip = ['vendor', 'node_modules', '.git', 'public', 'dist'];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->themeDir, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $f) => !($f->isDir() && in_array($f->getFilename(), $skip, true)),
        ));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $out[] = substr($file->getPathname(), strlen($this->themeDir) + 1);
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Package names sync's manifest additions/bumps touched in composer.json.
     *
     * @param array<string, mixed> $sync
     * @return list<string>
     */
    private static function addedPackages(array $sync): array
    {
        $names = [];
        foreach (is_array($sync['tier2'] ?? null) ? $sync['tier2'] : [] as $entry) {
            if (!is_array($entry) || ($entry['path'] ?? '') !== 'composer.json' || !is_array($entry['merge'] ?? null)) {
                continue;
            }
            foreach (['add', 'bump'] as $kind) {
                foreach (is_array($entry['merge'][$kind] ?? null) ? $entry['merge'][$kind] : [] as $label) {
                    if (is_string($label) && preg_match('#^require(?:-dev)?\.([a-z0-9_.-]+/[a-z0-9_.-]+)$#i', $label, $m) === 1) {
                        $names[] = $m[1];
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, mixed> */
    private static function scripts(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && is_array($data['scripts'] ?? null) ? $data['scripts'] : [];
    }

    private static function tail(string $out, int $lines = 40): string
    {
        $all = explode("\n", rtrim($out));

        return implode("\n", array_slice($all, -$lines));
    }

    /** @param array<string, mixed> $result */
    private static function title(array $result): string
    {
        $from = (string) ($result['core']['from'] ?? '');
        $to = (string) ($result['core']['to'] ?? '');

        return $from !== '' && $to !== '' && $from !== $to
            ? sprintf('Update taw/core %s → %s', ltrim($from, 'v'), ltrim($to, 'v'))
            : 'Update the TAW framework files';
    }

    /** @param array<string, mixed> $result */
    private static function commitMessage(array $result): string
    {
        $lines = [self::title($result), '', 'vendor/bin/taw update (taw.json policy): taw/core within its range, framework files, migrations, checks.'];
        foreach ($result['migrations'] as $id) {
            $lines[] = '- migration ' . $id;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function refuse(array $result, string $reason): array
    {
        $result['status'] = 'refused';
        $result['failure'] = ['step' => 'preflight', 'reason' => $reason];

        return $this->write($result);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function fail(array $result, string $step): array
    {
        $result['status'] = 'failed';
        $detail = null;
        foreach (array_merge($result['steps'], $result['checks']) as $s) {
            if (($s['name'] ?? '') === $step && (($s['ok'] ?? true) === false || ($s['status'] ?? '') === 'fail')) {
                $detail = $s;
            }
        }
        $result['failure'] = ['step' => $step, 'command' => $detail['command'] ?? '', 'out' => $detail['out'] ?? ''];

        return $this->write($result);
    }

    /**
     * Writes .taw/update-report.md (the guide, the PR body) and .json.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function write(array $result): array
    {
        $dir = $this->themeDir . '/' . dirname(self::REPORT);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->themeDir . '/' . self::REPORT . '.md', Report::markdown($result));
        file_put_contents($this->themeDir . '/' . self::REPORT . '.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return $result;
    }
}
