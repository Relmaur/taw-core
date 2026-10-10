<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\UpgradeCommand;
use TAW\Tests\TestCase;
use TAW\Update\Migration;
use TAW\Update\MigrationResult;
use TAW\Update\Migrations;

/**
 * Migrations: the changes a release makes to a theme, done by code. Each finds
 * its own work (running twice does nothing) and explains itself for a person
 * (umbrella ADR-0004).
 */
final class MigrationsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-migrations-' . getmypid() . '-' . uniqid();
        @mkdir($this->dir, 0777, true);
        Migrations::reset();
    }

    protected function tearDown(): void
    {
        Migrations::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_every_migration_has_an_id_a_kind_and_a_human_path(): void
    {
        $ids = [];
        foreach (Migrations::all() as $m) {
            $this->assertMatchesRegularExpression('#^\d+\.\d+\.\d+/[a-z0-9-]+$#', $m->id());
            $this->assertNotContains($m->id(), $ids, 'ids are unique');
            $ids[] = $m->id();
            $this->assertContains($m->themeKind(), ['classic', 'block', 'any']);
            foreach (['What:', 'Why:', 'By hand:', 'Undo:'] as $part) {
                $this->assertStringContainsString($part, $m->explain(), $m->id() . ' explains ' . $part . ' (ADR-0004)');
            }
        }
        $sorted = $ids;
        usort($sorted, fn ($a, $b) => version_compare(strtok($a, '/'), strtok($b, '/')));
        $this->assertSame($sorted, $ids, 'oldest first');
    }

    public function test_an_old_theme_is_brought_up_to_date_and_a_second_run_does_nothing(): void
    {
        // A classic theme with a full copy of the old agent docs and no site skills.
        file_put_contents($this->dir . '/AGENTS.md', "# AGENTS.md — AI Agent Guide for TAW Theme\n…\n");
        file_put_contents($this->dir . '/CLAUDE.md', "# CLAUDE.md — Claude Code Instructions\n…\n");

        $pending = array_map(fn (Migration $m) => $m->id(), Migrations::pending($this->dir));
        $this->assertSame(['1.89.0/site-skills', '1.91.0/agent-docs'], $pending);

        foreach (Migrations::pending($this->dir) as $m) {
            $result = $m->run($this->dir);
            $this->assertNotSame([], $result->changed, $m->id());
            $this->assertSame([], $result->manual);
        }
        $this->assertSame([], Migrations::pending($this->dir), 'nothing left');
        $this->assertFileExists($this->dir . '/.claude/skills/resolve-comments/SKILL.md');
        $this->assertStringContainsString('taw:agent-doc', (string) file_get_contents($this->dir . '/CLAUDE.md'));
    }

    public function test_an_edited_config_stays_pending_with_the_steps_for_a_person(): void
    {
        file_put_contents($this->dir . '/phpstan.neon', "parameters:\n    level: 9\n");
        $m = Migrations::find('1.91.0/configs');
        $this->assertNotNull($m);
        $this->assertTrue($m->pending($this->dir));
        $result = $m->run($this->dir);
        $this->assertSame([], $result->changed);
        $this->assertStringContainsString("phpstan.neon has this site's own changes", $result->manual[0]);
        $this->assertTrue($m->pending($this->dir), 'still listed until a person moves the edits over');
    }

    public function test_a_block_theme_skips_classic_only_migrations(): void
    {
        file_put_contents($this->dir . '/theme.json', '{}');
        mkdir($this->dir . '/templates');
        file_put_contents($this->dir . '/phpstan.neon', "parameters:\n    level: max\n");

        $this->assertNotContains('1.91.0/configs', array_map(fn ($m) => $m->id(), Migrations::pending($this->dir)));
    }

    public function test_an_extension_adds_migrations(): void
    {
        Migrations::add(new class implements Migration {
            public function id(): string { return '1.0.0/acme-rename'; }
            public function title(): string { return 'Rename the acme option'; }
            public function themeKind(): string { return 'any'; }
            public function explain(): string { return "What: …\nWhy: …\nBy hand: …\nUndo: …"; }
            public function pending(string $themeDir): bool { return !is_file($themeDir . '/.acme'); }
            public function run(string $themeDir): MigrationResult { touch($themeDir . '/.acme'); return new MigrationResult(['.acme']); }
        });
        $this->assertNotNull(Migrations::find('1.0.0/acme-rename'));
        $this->assertContains('1.0.0/acme-rename', array_map(fn ($m) => $m->id(), Migrations::pending($this->dir)));
    }

    public function test_the_upgrade_command(): void
    {
        file_put_contents($this->dir . '/AGENTS.md', "# old copy\n");
        $tester = new CommandTester(new UpgradeCommand($this->dir));

        $tester->execute([]);
        $this->assertStringContainsString('↻ 1.91.0/agent-docs', $tester->getDisplay());
        $this->assertStringContainsString('# old copy', (string) file_get_contents($this->dir . '/AGENTS.md'), 'a check writes nothing');

        $tester->execute(['--explain' => '1.91.0/agent-docs']);
        $this->assertStringContainsString('By hand: php bin/taw docs:sync --apply', $tester->getDisplay());
        $this->assertSame(1, $tester->execute(['--explain' => 'nope']));

        $tester->execute(['--apply' => true, '--json' => true]);
        $report = json_decode($tester->getDisplay(), true);
        $this->assertArrayHasKey('1.91.0/agent-docs', $report['applied']);
        $this->assertSame([], $report['manual']);

        $tester->execute([]);
        $this->assertStringContainsString('Nothing to migrate', $tester->getDisplay());
    }
}
