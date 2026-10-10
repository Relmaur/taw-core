<?php

declare(strict_types=1);

namespace TAW\CLI;

use Composer\InstalledVersions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Update\AgentDocs;
use TAW\Update\ConfigFiles;

/**
 * Detects (and optionally applies) drift between this project and the
 * canonical taw-theme scaffold + the installed taw/core version.
 *
 * This is the scriptable core of the `update-theme` Claude Code skill —
 * both the skill and this command read the same manifest
 * (resources/update-manifest.json) so the Tier 1/Tier 2/never-touched
 * lists only exist in one place. The skill is for an interactive agent
 * session; this command is for CI (php bin/taw sync --json --apply) and
 * for a human running it directly.
 *
 * Deliberately does NOT boot WordPress — the checks here (composer
 * version, git diff against a fresh clone) don't need it, and CI runners
 * won't have a WP+DB environment available anyway.
 *
 * Tier 1 is safe to auto-apply (nothing client-specific has ever lived in
 * those paths). Tier 2 is only ever reported, never written — a human
 * always reviews those diffs before they're applied, whether that's an
 * interactive agent session or a person reading a CI-opened pull request.
 * The one exception is opt-in: the structured manifests (composer.json,
 * package.json) also get rule-based suggestions (`merge`, ManifestMerge),
 * and --apply-manifests writes just those additions and bumps.
 */
class SyncCommand extends Command
{
    private const CORE_REPO_API = 'https://api.github.com/repos/Relmaur/taw-core/tags';
    private const THEME_REPO_URL = 'https://github.com/Relmaur/taw-theme.git';

    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('sync')
            ->setDescription('Check (and optionally apply) drift between this project and the canonical taw-theme scaffold + taw/core version')
            ->setHelp(<<<'HELP'
                Checks two independent things:
                  1. Is the installed taw/core version behind the latest tag on GitHub?
                  2. Does this project's Tier 1 (always-sync) or Tier 2 (review-before-sync)
                     taw-theme files differ from the canonical repo?

                Tier 1 changes are safe to auto-apply — pass --apply to write them.
                Tier 2 changes are reported (with a diff), never written by --apply —
                review and apply them yourself, or via the update-theme skill.
                composer.json and package.json also get rule-based suggestions
                (`merge` in the JSON): keys and repositories the scaffold has and this
                project lacks, and dependency constraints the scaffold raised. Nothing
                is ever removed. --apply-manifests writes those suggestions only.
                This command never touches taw/core itself — run
                `composer update taw/core` separately if it reports being behind.

                Examples:
                  <info>php bin/taw sync</info>              human-readable report, no changes made
                  <info>php bin/taw sync --json</info>        machine-readable JSON (for CI / scripts)
                  <info>php bin/taw sync --apply</info>       also writes Tier 1 changes to disk
                  <info>php bin/taw sync --apply-manifests</info>  also writes the composer.json/package.json suggestions
                HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a formatted report')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write Tier 1 changes to disk (Tier 2 is report-only)')
            ->addOption('apply-manifests', null, InputOption::VALUE_NONE, 'Write the suggested composer.json/package.json additions and bumps (never removals)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');
        $apply = (bool) $input->getOption('apply');
        $applyManifests = (bool) $input->getOption('apply-manifests');

        $report = [
            'taw_core' => $this->checkTawCoreVersion(),
            'tier1' => [],
            'tier2' => [],
            'applied' => [],
            'errors' => [],
        ];

        $pendingFiles = false; // converted configs or agent docs still to apply
        $manifest = $this->loadManifest();
        if ($manifest === null) {
            $report['errors'][] = 'Could not load resources/update-manifest.json — this ships with taw/core, reinstall it if missing.';
        } else {
            $clone = $this->cloneCanonicalThemeRepo();

            if ($clone === null) {
                $report['errors'][] = 'Could not clone ' . self::THEME_REPO_URL . ' — check network access and that git is installed.';
            } else {
                try {
                    $skillsCfg = $this->skillsReconcileConfig($manifest);

                    foreach ($manifest['tier1'] as $entry) {
                        if ($entry['type'] === 'skills-dir') {
                            $core = self::coreSkills($entry['path']);
                            $plan = $this->planSkillsReconcile($entry, $clone, $skillsCfg, $core);
                            $entry['reconcile'] = $plan;
                            $entry['changed'] = $plan['overwrite'] !== [] || $plan['delete'] !== [];

                            if ($entry['changed'] && $apply) {
                                $this->applySkillsReconcile($entry, $clone, $plan, $core);
                                $report['applied'][] = $entry['path'];
                            }

                            $report['tier1'][] = $entry;
                            continue;
                        }

                        $changed = $this->pathDiffers($entry, $clone);
                        $entry['changed'] = $changed;

                        if ($changed && $apply) {
                            $this->applyEntry($entry, $clone);
                            $report['applied'][] = $entry['path'];
                        }

                        $report['tier1'][] = $entry;
                    }

                    // The agent docs and vite.config.js/phpstan.neon: reported here,
                    // written by their migrations (`upgrade`, which follows taw.json)
                    // or by docs:sync / configs:sync, never by sync itself.
                    $docPlan = (new AgentDocs($this->themeDir, 'classic'))->plan();
                    $report['agent_docs'] = $docPlan;
                    $configPlan = (new ConfigFiles($this->themeDir))->plan();
                    $report['configs'] = $configPlan + ['by_hand' => array_intersect_key(ConfigFiles::BY_HAND, array_flip($configPlan['custom']))];
                    $pendingFiles = $docPlan['convert'] !== [] || $docPlan['create'] !== [] || $configPlan['convert'] !== [];

                    $mergeRules = $manifest['manifestMerge'] ?? [];
                    foreach ($manifest['tier2'] as $entry) {
                        $changed = $this->pathDiffers($entry, $clone);
                        $entry['changed'] = $changed;
                        $entry['diff'] = $changed ? $this->diffFile($entry, $clone) : null;
                        $rules = $mergeRules[$entry['path']] ?? null;
                        if ($changed && is_array($rules)) {
                            $merge = $this->planManifestMerge($entry['path'], $clone, $rules);
                            if (is_string($merge)) {
                                $report['errors'][] = $merge;
                            } else {
                                $entry['merge'] = $merge;
                                if ($applyManifests && ($merge['add'] !== [] || $merge['bump'] !== [])) {
                                    $this->applyManifestMerge($entry['path'], $merge);
                                    $report['applied'][] = $entry['path'];
                                }
                            }
                        }
                        $report['tier2'][] = $entry;
                    }
                } finally {
                    $this->removeDirectory($clone);
                }
            }
        }

        $report['clean'] = empty($report['errors'])
            && $report['taw_core']['behind'] === false
            && !$pendingFiles
            && !$this->anyChanged($report['tier1'])
            && !$this->anyChanged($report['tier2']);

        if ($asJson) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($io, $report, $apply);
        }

        // A hard infrastructure failure (couldn't reach GitHub AND couldn't
        // clone) is a real failure. "Everything's clean" or "found drift"
        // are both a successful check — callers branch on the JSON, not
        // the exit code, for that distinction.
        $hadNetworkFailure = $report['taw_core']['error'] !== null && !empty($report['errors']);

        return $hadNetworkFailure ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array{installed: ?string, latest: ?string, behind: bool, error: ?string}
     */
    private function checkTawCoreVersion(): array
    {
        $installed = InstalledVersions::isInstalled('taw/core')
            ? InstalledVersions::getPrettyVersion('taw/core')
            : null;

        $latest = null;
        $error = null;

        $context = stream_context_create([
            'http' => [
                'header' => $this->githubRequestHeaders(),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::CORE_REPO_API, false, $context);

        if ($response === false) {
            $error = 'Could not reach the GitHub API to check the latest taw/core tag.';
        } else {
            $tags = json_decode($response, true);
            if (!is_array($tags)) {
                $error = 'Unexpected response from the GitHub tags API.';
            } else {
                $latest = $this->highestSemverTag($tags);
                if ($latest === null) {
                    $error = 'No usable version tags found on taw-core.';
                }
            }
        }

        $behind = false;
        if ($installed !== null && $latest !== null) {
            $behind = version_compare(ltrim($installed, 'v'), ltrim($latest, 'v'), '<');
        }

        return [
            'installed' => $installed,
            'latest' => $latest,
            'behind' => $behind,
            'error' => $error,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     */
    private function highestSemverTag(array $tags): ?string
    {
        $names = array_filter(array_map(
            static fn(array $tag): ?string => is_string($tag['name'] ?? null) ? $tag['name'] : null,
            $tags
        ));

        $names = array_filter($names, static fn(string $name): bool => (bool) preg_match('/^v?\d+\.\d+\.\d+$/', $name));

        if (empty($names)) {
            return null;
        }

        usort($names, static fn(string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $names[0];
    }

    private function githubRequestHeaders(): string
    {
        $headers = "User-Agent: taw-core-sync-command\r\nAccept: application/vnd.github+json\r\n";

        $token = getenv('GITHUB_TOKEN');
        if (is_string($token) && $token !== '') {
            $headers .= 'Authorization: Bearer ' . $token . "\r\n";
        }

        return $headers;
    }

    /**
     * @return array{
     *     tier1: array<int, array{path: string, type: string}>,
     *     tier2: array<int, array{path: string, type: string}>,
     *     skillsReconcile?: array<string, mixed>,
     *     manifestMerge?: array<string, array<string, mixed>>
     * }|null
     */
    public function loadManifest(): ?array
    {
        $path = dirname(__DIR__, 2) . '/resources/update-manifest.json';

        if (!file_exists($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function cloneCanonicalThemeRepo(): ?string
    {
        $dest = sys_get_temp_dir() . '/taw-sync-' . bin2hex(random_bytes(6));

        exec(
            'git clone --depth=1 --quiet ' . escapeshellarg(self::THEME_REPO_URL) . ' ' . escapeshellarg($dest) . ' 2>&1',
            $outputLines,
            $exitCode
        );

        return $exitCode === 0 && is_dir($dest) ? $dest : null;
    }

    /**
     * @param array{path: string, type: string} $entry
     */
    private function pathDiffers(array $entry, string $cloneDir): bool
    {
        $local = rtrim($this->themeDir, '/') . '/' . $entry['path'];
        $canonical = rtrim($cloneDir, '/') . '/' . $entry['path'];

        if ($entry['type'] === 'dir') {
            return $this->treeHash($local) !== $this->treeHash($canonical);
        }

        if (!file_exists($local)) {
            return file_exists($canonical);
        }

        if (!file_exists($canonical)) {
            return false;
        }

        return md5_file($local) !== md5_file($canonical);
    }

    /**
     * Order-independent content hash of a directory tree — same idea as
     * comparing "everything under this path", without needing rsync's
     * dry-run output parsed. Missing directories hash as an empty string,
     * so "doesn't exist locally yet" correctly compares as different from
     * "exists upstream".
     */
    private function treeHash(string $dir): string
    {
        if (!is_dir($dir)) {
            return '';
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($dir) + 1);
                $files[$relative] = md5_file($file->getPathname());
            }
        }

        ksort($files);

        return md5(json_encode($files) ?: '');
    }

    /**
     * @param array{path: string, type: string} $entry
     */
    private function applyEntry(array $entry, string $cloneDir): void
    {
        $local = rtrim($this->themeDir, '/') . '/' . $entry['path'];
        $canonical = rtrim($cloneDir, '/') . '/' . $entry['path'];

        if ($entry['type'] === 'dir') {
            @mkdir($local, 0755, true);
            exec('rsync -a --delete ' . escapeshellarg(rtrim($canonical, '/') . '/') . ' ' . escapeshellarg(rtrim($local, '/') . '/'));
            return;
        }

        @mkdir(dirname($local), 0755, true);
        copy($canonical, $local);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{ownerKey: string, frameworkValue: string, siteValue: string}
     */
    public function skillsReconcileConfig(array $manifest): array
    {
        $cfg = is_array($manifest['skillsReconcile'] ?? null) ? $manifest['skillsReconcile'] : [];

        return [
            'ownerKey' => is_string($cfg['ownerKey'] ?? null) ? $cfg['ownerKey'] : 'owner',
            'frameworkValue' => is_string($cfg['frameworkValue'] ?? null) ? $cfg['frameworkValue'] : 'taw',
            'siteValue' => is_string($cfg['siteValue'] ?? null) ? $cfg['siteValue'] : 'site',
        ];
    }

    /**
     * Reconcile a skills directory that is *shared* between framework-managed
     * and site-authored skills — which it has to be, because Claude Code and
     * the agents runtime only auto-discover skills directly under
     * `.claude/skills/` and `.agents/skills/`. A plain `rsync -a --delete`
     * (what a `type: dir` entry does) would wipe any skill the client wrote
     * for itself.
     *
     * Rules (see resources/update-manifest.json § skillsReconcile):
     *   - skill present in canonical  → overwrite in place (fresh copy each run),
     *     unless the client's copy says `owner: site`: a site skill that
     *     happens to share a new framework skill's name is kept and reported
     *     as a clash (before v1.59.2 it was silently replaced)
     *   - present only in client, `owner: site` → preserve untouched, report it
     *   - present only in client, `owner: taw`  → delete (retired framework skill)
     *   - present only in client, no `owner:`   → preserve, but warn (human call)
     *
     * The site skills taw/core itself ships (`resources/skills/<name>/`, the
     * one source for classic and block themes alike), by name → folder. Only
     * `.claude/skills/` gets them.
     *
     * @return array<string, string>
     */
    public static function coreSkills(string $path = '.claude/skills/'): array
    {
        if (rtrim($path, '/') !== '.claude/skills') {
            return [];
        }
        $root = dirname(__DIR__, 2) . '/resources/skills';
        $skills = [];
        foreach (is_dir($root) ? (scandir($root) ?: []) : [] as $name) {
            if ($name[0] !== '.' && is_file($root . '/' . $name . '/SKILL.md')) {
                $skills[$name] = $root . '/' . $name;
            }
        }
        ksort($skills);

        return $skills;
    }

    /**
     * Every canonical skill for one skills-dir entry, by name → folder: the
     * scaffold clone's, then taw/core's own (which win on a shared name).
     *
     * @param array<string, string> $core
     * @return array<string, string>
     */
    private function canonicalSkillDirs(array $entry, ?string $cloneDir, array $core): array
    {
        $dirs = [];
        if ($cloneDir !== null) {
            $canonical = rtrim($cloneDir, '/') . '/' . $entry['path'];
            foreach ($this->immediateSubdirs($canonical) as $name) {
                $dirs[$name] = rtrim($canonical, '/') . '/' . $name;
            }
        }

        return array_merge($dirs, $core);
    }

    /**
     * $cloneDir null = taw/core's skills only (`skills:sync`): skills it
     * doesn't ship are left alone, never deleted, preserved or warned about.
     *
     * @param array{path: string, type: string} $entry
     * @param array{ownerKey: string, frameworkValue: string, siteValue: string} $cfg
     * @param array<string, string> $core taw/core's skills (coreSkills())
     * @return array{overwrite: list<string>, delete: list<string>, preserve: list<string>, warn: list<string>, clash: list<string>}
     */
    public function planSkillsReconcile(array $entry, ?string $cloneDir, array $cfg, array $core = []): array
    {
        $local = rtrim($this->themeDir, '/') . '/' . $entry['path'];

        $canonicalDirs = $this->canonicalSkillDirs($entry, $cloneDir, $core);
        $canonicalSkills = array_keys($canonicalDirs);
        $localSkills = $cloneDir === null ? [] : $this->immediateSubdirs($local);

        $plan = ['overwrite' => [], 'delete' => [], 'preserve' => [], 'warn' => [], 'clash' => []];

        foreach ($canonicalSkills as $name) {
            $localSkill = $local . '/' . $name;
            $canonicalSkill = $canonicalDirs[$name];

            if (is_dir($localSkill) && $this->skillOwner($localSkill, $cfg['ownerKey']) === $cfg['siteValue']) {
                $plan['clash'][] = $name;
                continue;
            }

            if (!is_dir($localSkill) || $this->treeHash($localSkill) !== $this->treeHash($canonicalSkill)) {
                $plan['overwrite'][] = $name;
            }
        }

        foreach ($localSkills as $name) {
            if (in_array($name, $canonicalSkills, true)) {
                continue;
            }

            $owner = $this->skillOwner($local . '/' . $name, $cfg['ownerKey']);

            if ($owner === $cfg['siteValue']) {
                $plan['preserve'][] = $name;
            } elseif ($owner === $cfg['frameworkValue']) {
                $plan['delete'][] = $name;
            } else {
                $plan['warn'][] = $name;
            }
        }

        sort($plan['overwrite']);
        sort($plan['delete']);
        sort($plan['preserve']);
        sort($plan['warn']);
        sort($plan['clash']);

        return $plan;
    }

    /**
     * @param array{path: string, type: string} $entry
     * @param array{overwrite: list<string>, delete: list<string>, preserve: list<string>, warn: list<string>, clash: list<string>} $plan
     * @param array<string, string> $core taw/core's skills (coreSkills())
     */
    public function applySkillsReconcile(array $entry, ?string $cloneDir, array $plan, array $core = []): void
    {
        $local = rtrim($this->themeDir, '/') . '/' . $entry['path'];
        $canonicalDirs = $this->canonicalSkillDirs($entry, $cloneDir, $core);

        @mkdir($local, 0755, true);

        foreach ($plan['overwrite'] as $name) {
            $src = rtrim($canonicalDirs[$name], '/') . '/';
            $dest = rtrim($local . '/' . $name, '/') . '/';
            @mkdir($dest, 0755, true);
            // `--delete` is safe *within* one skill folder — a skill dir is
            // wholly framework-owned; only its parent is shared. `--checksum`:
            // an edit that keeps a file's size within the same second would
            // otherwise pass rsync's size+time check and be skipped.
            exec('rsync -a --checksum --delete ' . escapeshellarg($src) . ' ' . escapeshellarg($dest));
        }

        foreach ($plan['delete'] as $name) {
            exec('rm -rf ' . escapeshellarg($local . '/' . $name));
        }
    }

    /**
     * Immediate child directory names of $dir (no dotfiles, not recursive).
     *
     * @return list<string>
     */
    private function immediateSubdirs(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($dir . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * Read `<ownerKey>:` from a skill's SKILL.md YAML frontmatter. Returns null
     * when the file, the frontmatter block, or the key is missing/unreadable —
     * the caller treats null as "unknown owner".
     */
    private function skillOwner(string $skillDir, string $ownerKey): ?string
    {
        $file = $skillDir . '/SKILL.md';

        if (!is_file($file)) {
            return null;
        }

        $contents = (string) file_get_contents($file);

        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $contents, $m)) {
            return null;
        }

        if (!preg_match('/^' . preg_quote($ownerKey, '/') . '\s*:\s*("?)([A-Za-z0-9_-]+)\1\s*$/m', $m[1], $om)) {
            return null;
        }

        return $om[2];
    }

    /**
     * The rule-based suggestions for one structured manifest, or an error
     * message when a file isn't valid JSON.
     *
     * @param array<string, mixed> $rules
     * @return array<string, mixed>|string
     */
    private function planManifestMerge(string $path, string $cloneDir, array $rules): array|string
    {
        $local = $this->readJson(rtrim($this->themeDir, '/') . '/' . $path);
        $canonical = $this->readJson(rtrim($cloneDir, '/') . '/' . $path);
        if ($local === null || $canonical === null) {
            return $path . ' isn\'t valid JSON (here or in the scaffold); compare it by hand.';
        }

        /** @var array{sections?: list<string>, dependencySections?: list<string>, optional?: array<string, list<string>>} $rules */
        return ManifestMerge::plan($local, $canonical, $rules);
    }

    /**
     * Writes a plan's additions and bumps into the project's manifest,
     * keeping its indentation.
     *
     * @param array<string, mixed> $merge
     */
    private function applyManifestMerge(string $path, array $merge): void
    {
        $file = rtrim($this->themeDir, '/') . '/' . $path;
        $original = (string) file_get_contents($file);
        $local = $this->readJson($file) ?? [];
        /** @var array{add: list<array<string, mixed>>, bump: list<array<string, mixed>>} $merge */
        file_put_contents($file, ManifestMerge::encode(ManifestMerge::apply($local, $merge), $original));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{path: string, type: string} $entry
     */
    private function diffFile(array $entry, string $cloneDir): ?string
    {
        if ($entry['type'] !== 'file') {
            return null;
        }

        $local = rtrim($this->themeDir, '/') . '/' . $entry['path'];
        $canonical = rtrim($cloneDir, '/') . '/' . $entry['path'];

        $localArg = file_exists($local) ? escapeshellarg($local) : '/dev/null';
        $canonicalArg = file_exists($canonical) ? escapeshellarg($canonical) : '/dev/null';

        exec('diff -u ' . $localArg . ' ' . $canonicalArg, $lines);

        return implode("\n", $lines);
    }

    private function removeDirectory(string $dir): void
    {
        exec('rm -rf ' . escapeshellarg($dir));
    }

    /**
     * @param array<int, array{changed?: bool}> $entries
     */
    private function anyChanged(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (!empty($entry['changed'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderHuman(SymfonyStyle $io, array $report, bool $applied): void
    {
        $io->title('TAW Framework Sync Check');

        $core = $report['taw_core'];
        if ($core['error'] !== null) {
            $io->warning('taw/core version check failed: ' . $core['error']);
        } else {
            $io->definitionList(
                ['taw/core installed' => $core['installed'] ?? '(not installed via composer?)'],
                ['taw/core latest' => $core['latest'] ?? 'unknown'],
                ['Behind?' => $core['behind'] ? 'yes — run composer update taw/core' : 'no'],
            );
        }

        foreach (['tier1' => 'Tier 1 (auto-sync)', 'tier2' => 'Tier 2 (review before sync)'] as $key => $label) {
            $io->section($label);
            $printedSomething = false;

            foreach ($report[$key] as $entry) {
                if (isset($entry['reconcile'])) {
                    $printedSomething = $this->renderSkillsReconcile($io, $entry, $applied) || $printedSomething;
                    continue;
                }

                if (!empty($entry['changed'])) {
                    $suffix = $applied && $key === 'tier1' ? ' (applied)' : '';
                    $io->text('- ' . $entry['path'] . $suffix);
                    if (isset($entry['merge']) && is_array($entry['merge'])) {
                        $this->renderMerge($io, $entry['merge']);
                    }
                    $printedSomething = true;
                }
            }

            if (!$printedSomething) {
                $io->text('Up to date.');
            }
        }

        if (isset($report['configs'])) {
            $io->section('Configs (vite.config.js, phpstan.neon)');
            $c = $report['configs'];
            foreach ($c['convert'] as $path) {
                $io->text('- ' . $path . ': to load taw/core\'s base (vendor/bin/taw upgrade --apply, or configs:sync --apply)');
            }
            foreach ($c['custom'] as $path) {
                $io->text('- ' . $path . ': has this site\'s own changes, left as is. By hand: ' . $c['by_hand'][$path]);
            }
            if ($c['convert'] === [] && $c['custom'] === []) {
                $io->text('Up to date (they load taw/core\'s base).');
            }
        }

        if (isset($report['agent_docs'])) {
            $io->section('Agent docs (AGENTS.md, CLAUDE.md, …)');
            $d = $report['agent_docs'];
            foreach (array_merge($d['convert'], $d['create']) as $path) {
                $io->text('- ' . $path . ': to point to taw/core (vendor/bin/taw upgrade --apply, or docs:sync --apply)');
            }
            foreach ($d['custom'] as $path) {
                $io->text('- ' . $path . ': has this site\'s own changes, left as is. By hand: ' . AgentDocs::BY_HAND);
            }
            if ($d['convert'] === [] && $d['create'] === [] && $d['custom'] === []) {
                $io->text('Up to date (they point to taw/core).');
            }
        }

        if (!empty($report['errors'])) {
            foreach ($report['errors'] as $error) {
                $io->error($error);
            }
        }

        if ($report['clean']) {
            $io->success('Everything is up to date.');
        }
    }

    /**
     * The suggestions under a structured manifest's line.
     *
     * @param array<string, mixed> $merge
     */
    private function renderMerge(SymfonyStyle $io, array $merge): void
    {
        $lines = [];
        foreach (['add' => 'add', 'bump' => 'raise', 'review' => 'review'] as $bucket => $verb) {
            foreach ($merge[$bucket] ?? [] as $item) {
                $to = isset($item['to']) ? ' → ' . json_encode($item['to'], JSON_UNESCAPED_SLASHES) : '';
                $lines[] = '    ' . $verb . ' ' . $item['label'] . $to;
            }
        }
        $features = array_unique(array_map(static fn (array $i): string => (string) $i['feature'], $merge['optional'] ?? []));
        if ($features !== []) {
            $lines[] = '    optional, not suggested: ' . implode(', ', $features);
        }
        if (($merge['add'] ?? []) === [] && ($merge['bump'] ?? []) === [] && ($merge['review'] ?? []) === []) {
            $own = (int) ($merge['site_only'] ?? 0);
            $lines[] = '    nothing to apply: the rest is this project\'s own (' . $own . ($own === 1 ? ' key' : ' keys') . ', kept)';
        }
        $io->text($lines);
    }

    /**
     * Render one `skills-dir` entry's reconciliation outcome. Preserved
     * site-authored skills are always reported (even on an otherwise-clean
     * run), so it's visible that the sync left them alone on purpose.
     *
     * @param array<string, mixed> $entry
     */
    private function renderSkillsReconcile(SymfonyStyle $io, array $entry, bool $applied): bool
    {
        $reconcile = $entry['reconcile'];
        $path = (string) $entry['path'];

        if (!is_array($reconcile)) {
            return false;
        }

        $plan = [
            'overwrite' => is_array($reconcile['overwrite'] ?? null) ? $reconcile['overwrite'] : [],
            'delete' => is_array($reconcile['delete'] ?? null) ? $reconcile['delete'] : [],
            'preserve' => is_array($reconcile['preserve'] ?? null) ? $reconcile['preserve'] : [],
            'warn' => is_array($reconcile['warn'] ?? null) ? $reconcile['warn'] : [],
            'clash' => is_array($reconcile['clash'] ?? null) ? $reconcile['clash'] : [],
        ];
        $printed = false;

        if ($plan['overwrite'] !== []) {
            $io->text(sprintf(
                '- %s — %s %d framework skill(s): %s',
                $path,
                $applied ? 'refreshed' : 'will refresh',
                count($plan['overwrite']),
                implode(', ', $plan['overwrite'])
            ));
            $printed = true;
        }

        if ($plan['delete'] !== []) {
            $io->text(sprintf(
                '- %s — %s retired framework skill(s) [owner: taw, no longer in canonical]: %s',
                $path,
                $applied ? 'removed' : 'will remove',
                implode(', ', $plan['delete'])
            ));
            $printed = true;
        }

        if ($plan['preserve'] !== []) {
            $io->text(sprintf(
                '- %s — preserved site-authored skill(s) [owner: site]: %s',
                $path,
                implode(', ', $plan['preserve'])
            ));
            $printed = true;
        }

        if ($plan['warn'] !== []) {
            $io->warning(sprintf(
                "%s: skill(s) not in the canonical scaffold and with no `owner:` marker — left untouched, decide by hand: %s\n"
                . "Add `owner: site` to the SKILL.md frontmatter to keep it silently, or delete the folder if it's a retired framework skill.",
                $path,
                implode(', ', $plan['warn'])
            ));
            $printed = true;
        }

        if ($plan['clash'] !== []) {
            $io->warning(sprintf(
                "%s: site skill(s) [owner: site] share a name with a framework skill — kept yours, the framework copy was NOT installed: %s\n"
                . "Rename your skill's folder (and its `name:`) to get both, or delete it to take the framework one.",
                $path,
                implode(', ', $plan['clash'])
            ));
            $printed = true;
        }

        return $printed;
    }
}
