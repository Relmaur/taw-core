<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use TAW\Tests\TestCase;
use TAW\Update\ProcessShell;
use TAW\Update\Shell;
use TAW\Update\Updater;

/**
 * The update engine end to end on a real git repository (a bare repo stands in
 * for GitHub); Composer, the new vendor/bin/taw and gh are faked.
 */
final class UpdaterTest extends TestCase
{
    private string $dir;
    private string $remote;

    /** @var list<string> commands run, joined */
    public array $ran = [];

    /** @var array<string, array{code: int, out: string}> fake answers by command prefix */
    public array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/taw-updater-' . getmypid() . '-' . uniqid();
        $this->dir = $base . '/theme';
        $this->remote = $base . '/origin.git';
        @mkdir($this->dir . '/vendor/composer', 0777, true);
        @mkdir($this->dir . '/inc', 0777, true);
        exec('git init -q --bare ' . escapeshellarg($this->remote));
        file_put_contents($this->dir . '/inc/ok.php', "<?php\necho 'ok';\n");
        file_put_contents($this->dir . '/composer.json', json_encode(['name' => 'acme/theme', 'scripts' => []]));
        file_put_contents($this->dir . '/.gitignore', "vendor/\n");
        $this->installed('v1.90.0');
        $git = 'git -C ' . escapeshellarg($this->dir);
        exec("{$git} init -q -b main && {$git} config user.email t@t && {$git} config user.name t && {$git} add -A && {$git} commit -qm base && {$git} remote add origin " . escapeshellarg($this->remote));
        $this->ran = [];
        $this->answers = [
            'vendor/bin/taw sync' => ['code' => 0, 'out' => json_encode(['errors' => [], 'tier2' => []])],
            'vendor/bin/taw upgrade' => ['code' => 0, 'out' => json_encode(['applied' => ['1.91.0/agent-docs' => ['changed' => ['AGENTS.md'], 'manual' => []]], 'manual' => []])],
            'gh pr create' => ['code' => 0, 'out' => "Creating pull request\nhttps://github.com/acme/theme/pull/7\n"],
        ];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->dir)));
        parent::tearDown();
    }

    private function installed(string $version): void
    {
        file_put_contents($this->dir . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'taw/core', 'version' => $version]]]));
    }

    private function updater(): Updater
    {
        $test = $this;
        $real = new ProcessShell();
        $shell = new class ($test, $real) implements Shell {
            public function __construct(private UpdaterTest $t, private ProcessShell $real) {}
            public function run(array $command, string $cwd, array $env = []): array
            {
                $line = implode(' ', array_map(fn ($a) => str_replace($cwd . '/', '', $a), $command));
                $this->t->ran[] = $line;
                if ($command[0] === 'git' || in_array('-l', $command, true)) {
                    return $this->real->run($command, $cwd, $env); // real git, real php -l
                }
                foreach ($this->t->answers as $prefix => $answer) {
                    if (str_contains($line, $prefix)) {
                        return $answer;
                    }
                }
                if (str_starts_with($line, 'composer update taw/core') || str_starts_with($line, 'composer require')) {
                    $this->t->composerRan();
                }

                return ['code' => 0, 'out' => ''];
            }
        };

        return new Updater($this->dir, $shell, ['composer'], fn () => new \DateTimeImmutable('2026-10-09 12:00:00'), 'php');
    }

    /** The fake composer update: taw/core moves and the lock changes. */
    public function composerRan(): void
    {
        $this->installed('v1.91.0');
        file_put_contents($this->dir . '/composer.lock', '{"taw/core": "1.91.0"}');
        file_put_contents($this->dir . '/AGENTS.md', "# short taw:agent-doc file\n");
    }

    public function test_it_refuses_to_start_on_uncommitted_changes(): void
    {
        file_put_contents($this->dir . '/inc/wip.php', "<?php\n");
        $r = $this->updater()->run();
        $this->assertSame('refused', $r['status']);
        $this->assertStringContainsString('uncommitted changes (inc/wip.php)', $r['failure']['reason']);
        $this->assertNotContains('git checkout -b taw/update-20261009-120000', $this->ran);
        $this->assertStringContainsString('## What to do', (string) file_get_contents($this->dir . '/.taw/update-report.md'));
    }

    public function test_a_full_update_ends_in_a_pull_request(): void
    {
        $r = $this->updater()->run();

        $this->assertSame('updated', $r['status'], json_encode($r['failure']));
        $this->assertSame(['from' => 'v1.90.0', 'to' => 'v1.91.0'], $r['core']);
        $this->assertContains('composer update taw/core --with-dependencies --no-interaction', $this->ran);
        $this->assertSame(['1.91.0/agent-docs'], $r['migrations']);
        $this->assertSame(['AGENTS.md', 'composer.lock'], $r['changed']);
        $this->assertSame('https://github.com/acme/theme/pull/7', $r['delivered']['url']);
        $pushed = trim((string) shell_exec('git -C ' . escapeshellarg($this->remote) . ' branch --list'));
        $this->assertStringContainsString('taw/update-20261009-120000', $pushed, 'the branch reached the remote');
        $lint = array_values(array_filter($r['checks'], fn ($c) => $c['name'] === 'lint'))[0];
        $this->assertSame('pass', $lint['status']);
        $this->assertSame('skip', array_values(array_filter($r['checks'], fn ($c) => $c['name'] === 'test'))[0]['status'], 'no test script: skipped, never passed');

        $md = (string) file_get_contents($this->dir . '/.taw/update-report.md');
        $this->assertStringContainsString('# TAW update: theme (taw/core 1.90.0 → 1.91.0)', $md);
        $this->assertStringContainsString('Pull request opened for you to merge', $md);
        $this->assertStringContainsString('upgrade --explain 1.91.0/agent-docs', $md);
        $this->assertSame('', trim((string) shell_exec('git -C ' . escapeshellarg($this->dir) . ' status --porcelain -- . ":!.taw"')), 'everything committed but the report');
    }

    public function test_a_waiting_update_is_never_doubled(): void
    {
        $this->updater()->run(); // pushes taw/update-20261009-120000 and opens a PR
        exec('git -C ' . escapeshellarg($this->dir) . ' checkout -q main');
        $this->installed('v1.90.0');

        $r = $this->updater()->run();
        $this->assertSame('refused', $r['status']);
        $this->assertStringContainsString('An earlier update is still waiting on taw/update-20261009-120000', $r['failure']['reason']);
    }

    public function test_a_failed_check_stays_on_the_branch_with_the_guide(): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['name' => 'acme/theme', 'scripts' => ['test' => 'phpunit']]));
        exec('git -C ' . escapeshellarg($this->dir) . ' commit -qam scripts');
        $this->answers['composer run test'] = ['code' => 1, 'out' => "FAILURES!\nTests: 3, Failures: 1 (HeroTest::test_heading)"];

        $r = $this->updater()->run();

        $this->assertSame('failed', $r['status']);
        $this->assertSame('test', $r['failure']['step']);
        $this->assertNotContains('gh pr create', array_map(fn ($l) => substr($l, 0, 12), $this->ran), 'nothing is pushed');
        $this->assertSame('', trim((string) shell_exec('git -C ' . escapeshellarg($this->remote) . ' branch --list')), 'the remote has nothing');
        $md = (string) file_get_contents($this->dir . '/.taw/update-report.md');
        foreach (['## What failed: test', 'HeroTest::test_heading', '## Why it usually fails', '## Steps', '## Verify', '## Finish', '## Undo', 'git branch -D taw/update-20261009-120000', 'Fix with Claude'] as $part) {
            $this->assertStringContainsString($part, $md);
        }
    }

    public function test_nothing_to_update_leaves_no_branch(): void
    {
        $this->answers['composer update'] = ['code' => 0, 'out' => 'Nothing to modify in lock file'];
        $this->answers['vendor/bin/taw upgrade'] = ['code' => 0, 'out' => json_encode(['applied' => [], 'manual' => []])];

        $r = $this->updater()->run();

        $this->assertSame('up-to-date', $r['status']);
        $this->assertSame('main', trim((string) shell_exec('git -C ' . escapeshellarg($this->dir) . ' rev-parse --abbrev-ref HEAD')));
        $this->assertSame('', trim((string) shell_exec('git -C ' . escapeshellarg($this->dir) . ' branch --list "taw/*"')));
    }

    public function test_the_policy_sets_the_range(): void
    {
        file_put_contents($this->dir . '/taw.json', json_encode(['update' => ['core' => 'patch', 'deliver' => 'branch', 'checks' => []]]));
        exec('git -C ' . escapeshellarg($this->dir) . ' add -A && git -C ' . escapeshellarg($this->dir) . ' commit -qm policy');
        $r = $this->updater()->run();
        $this->assertContains('composer update taw/core --with-dependencies --no-interaction --with=taw/core:~1.90.0', $this->ran);
        $this->assertSame('branch', $r['delivered']['how']);

        $this->setUp();
        file_put_contents($this->dir . '/taw.json', json_encode(['update' => ['core' => 'pinned:1.90.0']]));
        exec('git -C ' . escapeshellarg($this->dir) . ' add -A && git -C ' . escapeshellarg($this->dir) . ' commit -qm pin');
        $this->updater()->run();
        $this->assertEmpty(array_filter($this->ran, fn ($l) => str_starts_with($l, 'composer update taw/core') || str_starts_with($l, 'composer require')), 'a pin that holds runs no Composer update');
    }
}
