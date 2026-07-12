<?php

declare(strict_types=1);

namespace TAW\CLI;

use Composer\InstalledVersions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
                Tier 2 changes are only ever reported (with a diff), never written here —
                review and apply them yourself, or via the update-theme skill.
                This command never touches taw/core itself — run
                `composer update taw/core` separately if it reports being behind.

                Examples:
                  <info>php bin/taw sync</info>              human-readable report, no changes made
                  <info>php bin/taw sync --json</info>        machine-readable JSON (for CI / scripts)
                  <info>php bin/taw sync --apply</info>       also writes Tier 1 changes to disk
                HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a formatted report')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write Tier 1 changes to disk (Tier 2 is always report-only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');
        $apply = (bool) $input->getOption('apply');

        $report = [
            'taw_core' => $this->checkTawCoreVersion(),
            'tier1' => [],
            'tier2' => [],
            'applied' => [],
            'errors' => [],
        ];

        $manifest = $this->loadManifest();
        if ($manifest === null) {
            $report['errors'][] = 'Could not load resources/update-manifest.json — this ships with taw/core, reinstall it if missing.';
        } else {
            $clone = $this->cloneCanonicalThemeRepo();

            if ($clone === null) {
                $report['errors'][] = 'Could not clone ' . self::THEME_REPO_URL . ' — check network access and that git is installed.';
            } else {
                try {
                    foreach ($manifest['tier1'] as $entry) {
                        $changed = $this->pathDiffers($entry, $clone);
                        $entry['changed'] = $changed;

                        if ($changed && $apply) {
                            $this->applyEntry($entry, $clone);
                            $report['applied'][] = $entry['path'];
                        }

                        $report['tier1'][] = $entry;
                    }

                    foreach ($manifest['tier2'] as $entry) {
                        $changed = $this->pathDiffers($entry, $clone);
                        $entry['changed'] = $changed;
                        $entry['diff'] = $changed ? $this->diffFile($entry, $clone) : null;
                        $report['tier2'][] = $entry;
                    }
                } finally {
                    $this->removeDirectory($clone);
                }
            }
        }

        $report['clean'] = empty($report['errors'])
            && $report['taw_core']['behind'] === false
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
     * @return array{tier1: array<int, array{path: string, type: string}>, tier2: array<int, array{path: string, type: string}>}|null
     */
    private function loadManifest(): ?array
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
            $changed = array_filter($report[$key], static fn(array $e): bool => !empty($e['changed']));

            if (empty($changed)) {
                $io->text('Up to date.');
                continue;
            }

            foreach ($changed as $entry) {
                $suffix = $applied && $key === 'tier1' ? ' (applied)' : '';
                $io->text('- ' . $entry['path'] . $suffix);
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
}
