<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TAW\Update\Migrations\PreCommitHook;

/**
 * Issue #192: the 1.94.0/pre-commit-hook migration points a theme clone's
 * core.hooksPath at taw/core's hook, and never replaces another hooks setup.
 * Real git in a temp folder; the hook file itself is a stand-in.
 */
final class PreCommitHookTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/taw-hook-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** A theme folder with taw/core's hook in vendor/, as a git repository (or inside one at $repo). */
    private function theme(string $sub = ''): string
    {
        $theme = $this->dir . ($sub === '' ? '' : '/' . $sub);
        @mkdir($theme . '/' . PreCommitHook::HOOKS_DIR, 0777, true);
        file_put_contents($theme . '/' . PreCommitHook::HOOKS_DIR . '/pre-commit', "#!/bin/sh\n");
        exec('git init -q ' . escapeshellarg($this->dir));

        return $theme;
    }

    private function hooksPath(): string
    {
        return trim((string) shell_exec('git -C ' . escapeshellarg($this->dir) . ' config --get core.hooksPath'));
    }

    public function test_it_installs_the_hook_once(): void
    {
        $theme = $this->theme();
        $migration = new PreCommitHook();

        $this->assertTrue($migration->pending($theme));
        $result = $migration->run($theme);

        $this->assertSame([], $result->manual);
        $this->assertSame(PreCommitHook::HOOKS_DIR, $this->hooksPath());
        $this->assertFalse($migration->pending($theme), 'running twice does nothing');
    }

    public function test_a_theme_inside_a_bigger_repository_gets_the_path_from_its_top(): void
    {
        $theme = $this->theme('wp-content/themes/site');

        (new PreCommitHook())->run($theme);

        $this->assertSame('wp-content/themes/site/' . PreCommitHook::HOOKS_DIR, $this->hooksPath());
    }

    public function test_the_sites_own_hooks_path_is_never_replaced(): void
    {
        $theme = $this->theme();
        exec('git -C ' . escapeshellarg($this->dir) . ' config core.hooksPath .husky');

        $result = (new PreCommitHook())->run($theme);

        $this->assertSame('.husky', $this->hooksPath());
        $this->assertStringContainsString(PreCommitHook::CHECK, $result->manual[0] ?? '');
    }

    public function test_other_hooks_in_the_clone_are_never_silenced(): void
    {
        $theme = $this->theme();
        file_put_contents($this->dir . '/.git/hooks/commit-msg', "#!/bin/sh\n");

        $result = (new PreCommitHook())->run($theme);

        $this->assertSame('', $this->hooksPath());
        $this->assertStringContainsString('commit-msg', $result->manual[0] ?? '');
    }

    public function test_the_clones_own_pre_commit_is_fine_the_hook_calls_it(): void
    {
        $theme = $this->theme();
        file_put_contents($this->dir . '/.git/hooks/pre-commit', "#!/bin/sh\n");

        $this->assertSame([], (new PreCommitHook())->run($theme)->manual);
        $this->assertSame(PreCommitHook::HOOKS_DIR, $this->hooksPath());
    }

    public function test_nothing_to_do_outside_git_or_without_the_hook(): void
    {
        mkdir($this->dir . '/plain');
        $this->assertFalse((new PreCommitHook())->pending($this->dir . '/plain'), 'not a repository');

        exec('git init -q ' . escapeshellarg($this->dir));
        $this->assertFalse((new PreCommitHook())->pending($this->dir), 'taw/core without the hook (older vendor/)');
    }

    public function test_the_shipped_hook_and_check_are_executable(): void
    {
        $root = dirname(__DIR__, 3);

        $this->assertTrue(is_executable($root . '/resources/git-hooks/pre-commit'));
        $this->assertStringContainsString('check-committed-files.sh" --staged', (string) file_get_contents($root . '/resources/git-hooks/pre-commit'));
    }
}
