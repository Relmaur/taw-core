<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\SkillsSyncCommand;
use TAW\Tests\TestCase;

/**
 * `bin/taw skills:sync` installs taw/core's site skills (resources/skills/)
 * into a theme's .claude/skills/ — the way block themes get them.
 */
final class SkillsSyncCommandTest extends TestCase
{
    private string $theme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->theme = sys_get_temp_dir() . '/taw-skills-sync-' . getmypid() . '-' . uniqid();
        // A block theme with its own skill and an unrelated framework one.
        $this->write('.claude/skills/publish-news/SKILL.md', "---\nname: publish-news\nowner: site\n---\nours\n");
        $this->write('.claude/skills/studio/SKILL.md', "---\nname: studio\nowner: taw\n---\nscaffold\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->theme));
        parent::tearDown();
    }

    public function test_reports_then_installs_and_leaves_the_rest_alone(): void
    {
        $tester = new CommandTester(new SkillsSyncCommand($this->theme));

        $tester->execute(['--json' => true]);
        $report = json_decode($tester->getDisplay(), true);
        $this->assertContains('resolve-comments', $report['install']);
        $this->assertFalse($report['applied']);
        $this->assertDirectoryDoesNotExist($this->theme . '/.claude/skills/resolve-comments', 'a check writes nothing');

        $tester->execute(['--apply' => true]);
        $this->assertStringContainsString('installed', $tester->getDisplay());
        $this->assertFileExists($this->theme . '/.claude/skills/resolve-comments/SKILL.md');
        $this->assertFileExists($this->theme . '/.claude/skills/resolve-comments/playbooks/content.md');
        $this->assertFileExists($this->theme . '/.claude/skills/perf-audit/SKILL.md');
        $this->assertFileExists($this->theme . '/.claude/skills/publish-news/SKILL.md', 'the site skill stays');
        $this->assertFileExists($this->theme . '/.claude/skills/studio/SKILL.md', 'skills taw/core does not ship are never touched');

        $tester->execute(['--json' => true]);
        $again = json_decode($tester->getDisplay(), true);
        $this->assertSame([], $again['install'], 'current after --apply');
        $this->assertContains('resolve-comments', $again['current']);
    }

    private function write(string $rel, string $body): void
    {
        $file = $this->theme . '/' . $rel;
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $body);
    }
}
