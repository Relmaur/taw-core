<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\LensCommand;

final class LensCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/taw-lens-cmd-' . uniqid();
        mkdir($this->root . '/Local Sites/acme/app/public/wp-content/themes/acme', 0777, true);
        mkdir($this->root . '/Local Sites/linked/app/public/wp-content/themes', 0777, true);
        mkdir($this->root . '/repos/linked-theme', 0777, true);
        symlink($this->root . '/repos/linked-theme', $this->root . '/Local Sites/linked/app/public/wp-content/themes/linked-theme');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string, array<string, string>> */
    private function sites(): array
    {
        return [
            'a' => ['path' => $this->root . '/Local Sites/acme', 'domain' => 'acme.local'],
            'b' => ['path' => $this->root . '/Local Sites/linked', 'domain' => 'linked.local'],
            'c' => ['path' => $this->root . '/Local Sites/evil', 'domain' => 'evil.local/"><script>'],
        ];
    }

    public function test_it_finds_the_site_holding_the_theme_in_locals_list(): void
    {
        $this->assertSame(
            ['https://acme.local', 'http://acme.local'],
            LensCommand::localOrigins($this->sites(), $this->root . '/Local Sites/acme/app/public/wp-content/themes/acme'),
        );
    }

    public function test_it_finds_the_site_whose_themes_folder_links_to_the_theme(): void
    {
        $this->assertSame(
            ['https://linked.local', 'http://linked.local'],
            LensCommand::localOrigins($this->sites(), $this->root . '/repos/linked-theme'),
        );
        $this->assertSame([], LensCommand::localOrigins($this->sites(), $this->root . '/repos'));
    }

    public function test_an_origin_is_scheme_host_and_port_only(): void
    {
        $this->assertSame('https://acme.local', LensCommand::origin('https://Acme.local/'));
        $this->assertSame('http://localhost:10003', LensCommand::origin('http://localhost:10003'));
        $this->assertNull(LensCommand::origin('acme.local'));
        $this->assertNull(LensCommand::origin('ftp://acme.local'));
        $this->assertNull(LensCommand::origin('https://acme.local/blog'));
        $this->assertNull(LensCommand::origin('https://user@acme.local'));
    }

    public function test_versions(): void
    {
        $this->assertSame('2.1.319', LensCommand::parseVersion("2.1.319 (Claude Code)\n"));
        $this->assertSame('22.23.1', LensCommand::parseVersion('v22.23.1'));
        $this->assertNull(LensCommand::parseVersion('nope'));
    }

    public function test_the_bridges_command_line(): void
    {
        $this->assertSame(
            ['/b/bridge.mjs', '--theme', '/t', '--origin', 'https://a.local', '--origin', 'http://a.local', '--port', '47615', '--claude', '/bin/claude', '--resume', 'abc12345'],
            LensCommand::bridgeArgs('/b/bridge.mjs', '/t', ['https://a.local', 'http://a.local'], 47615, ['claude' => '/bin/claude', 'model' => '', 'resume' => 'abc12345']),
        );
        $this->assertFileExists(LensCommand::bridgeDir() . '/bridge.mjs');
        $this->assertFileExists(LensCommand::bridgeDir() . '/agent-prompt.md');
    }

    public function test_prompt_prints_the_agents_instructions_which_are_the_human_guide_too(): void
    {
        $tester = $this->tester($this->root . '/Local Sites/acme/app/public/wp-content/themes/acme');
        $tester->execute(['--prompt' => true]);

        $this->assertStringContainsString('# TAW Lens', $tester->getDisplay());
        $this->assertStringContainsString('taw fields:set', $tester->getDisplay());
    }

    public function test_it_explains_what_is_missing_instead_of_starting(): void
    {
        putenv('TAW_LENS_NODE=' . $this->root . '/no-node');
        putenv('TAW_LENS_CLAUDE=' . $this->root . '/no-claude');
        try {
            $tester = $this->tester($this->root . '/repos/linked-theme');
            $code = $tester->execute(['--origin' => ['nope'], '--permission-mode' => 'yolo', '--resume' => null]);
        } finally {
            putenv('TAW_LENS_NODE');
            putenv('TAW_LENS_CLAUDE');
        }
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Node.js isn't installed", (string) $display);
        $this->assertStringContainsString("Claude Code isn't installed", (string) $display);
        $this->assertStringContainsString("--origin nope isn't a site address", (string) $display);
        $this->assertStringContainsString('--permission-mode yolo', (string) $display);
        $this->assertStringContainsString('no earlier Lens conversation', (string) $display);
    }

    private function tester(string $theme): CommandTester
    {
        $app = new Application();
        $app->add(new LensCommand($theme));

        return new CommandTester($app->find('lens'));
    }
}
