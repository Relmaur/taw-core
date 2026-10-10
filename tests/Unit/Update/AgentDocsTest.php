<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\DocsSyncCommand;
use TAW\Tests\TestCase;
use TAW\Update\AgentDocs;

/**
 * Agent docs: full framework copies in a theme become short site-owned files
 * that import or point to taw/core's copy; marked files are never touched.
 */
final class AgentDocsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-agentdocs-' . getmypid() . '-' . uniqid();
        @mkdir($this->dir . '/.github', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_full_copies_are_converted_and_marked_files_left_alone(): void
    {
        file_put_contents($this->dir . '/AGENTS.md', "# AGENTS.md — AI Agent Guide for TAW Theme\n…2000 lines…\n");
        file_put_contents($this->dir . '/.windsurfrules', "You are working on TAW Theme\n");
        file_put_contents($this->dir . '/CLAUDE.md', "# CLAUDE.md\n<!-- taw:agent-doc 1 -->\n@vendor/x\n## This site\nOur notes.\n");
        $docs = new AgentDocs($this->dir, 'classic');

        $plan = $docs->plan();
        $this->assertSame(['AGENTS.md', '.windsurfrules'], $plan['convert']);
        $this->assertSame([], $plan['create'], 'copilot rules are only converted, never created');
        $this->assertSame(['CLAUDE.md'], $plan['current']);

        $this->assertSame(['AGENTS.md', '.windsurfrules'], $docs->apply($plan));
        $agents = (string) file_get_contents($this->dir . '/AGENTS.md');
        $this->assertStringContainsString(AgentDocs::MARKER, $agents);
        $this->assertStringContainsString('vendor/taw/core/resources/agents/classic/AGENTS.md', $agents);
        $this->assertStringContainsString('Our notes.', (string) file_get_contents($this->dir . '/CLAUDE.md'), 'a marked file is never changed');
        $this->assertSame(['convert' => [], 'create' => [], 'current' => ['AGENTS.md', 'CLAUDE.md', '.windsurfrules']], $docs->plan());
    }

    public function test_a_block_theme_gets_its_own_and_missing_main_docs_are_created(): void
    {
        $docs = new AgentDocs($this->dir, 'block');
        $plan = $docs->plan();
        $this->assertSame(['AGENTS.md', 'CLAUDE.md'], $plan['create']);
        $docs->apply($plan);
        $this->assertStringContainsString('@vendor/taw/core/resources/agents/block/CLAUDE.md', (string) file_get_contents($this->dir . '/CLAUDE.md'));
    }

    public function test_every_template_and_imported_file_exists(): void
    {
        foreach (AgentDocs::FILES as $kind => $files) {
            foreach ($files as $doc) {
                $template = AgentDocs::root() . '/' . $doc['template'];
                $this->assertFileExists($template);
                $this->assertStringContainsString(AgentDocs::MARKER, (string) file_get_contents($template));
                preg_match_all('#vendor/taw/core/(resources/agents/[\w/.-]*\w)#', (string) file_get_contents($template), $m);
                foreach ($m[1] as $ref) {
                    $this->assertFileExists(dirname(AgentDocs::root(), 2) . '/' . $ref, $kind . ' template points to ' . $ref);
                }
            }
        }
    }

    public function test_the_command_reports_then_converts(): void
    {
        file_put_contents($this->dir . '/AGENTS.md', "# old full copy\n");
        $tester = new CommandTester(new DocsSyncCommand($this->dir));
        $tester->execute([]);
        $this->assertStringContainsString('to convert AGENTS.md', $tester->getDisplay());
        $this->assertStringContainsString('# old full copy', (string) file_get_contents($this->dir . '/AGENTS.md'), 'a check writes nothing');
        $tester->execute(['--apply' => true, '--json' => true]);
        $this->assertSame(['AGENTS.md', 'CLAUDE.md'], json_decode($tester->getDisplay(), true)['applied']);
    }
}
