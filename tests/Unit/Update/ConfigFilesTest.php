<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Update;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\ConfigsSyncCommand;
use TAW\Tests\TestCase;
use TAW\Update\ConfigFiles;

/**
 * vite.config.js / phpstan.neon: only an unedited scaffold copy is replaced
 * by the short file that loads taw/core's base; an edited one is reported.
 */
final class ConfigFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-configs-' . getmypid() . '-' . uniqid();
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_unedited_copies_convert_edited_ones_stay(): void
    {
        $scaffold = dirname(__DIR__, 4) . '/taw-theme';
        if (!is_file($scaffold . '/vite.config.js')) {
            $this->markTestSkipped('needs the umbrella checkout of taw-theme (its git history) next to taw-core');
        }
        // An old scaffold vite.config.js (unedited), a phpstan.neon a site edited.
        $old = (string) shell_exec('git -C ' . escapeshellarg($scaffold) . ' show $(git -C ' . escapeshellarg($scaffold) . ' log --format=%H -- vite.config.js | tail -1):vite.config.js');
        file_put_contents($this->dir . '/vite.config.js', $old);
        file_put_contents($this->dir . '/phpstan.neon', "includes:\n    - vendor/szepeviktor/phpstan-wordpress/extension.neon\nparameters:\n    scanFiles:\n        - vendor/php-stubs/wp-cli-stubs/wp-cli-stubs.php\n");

        $configs = new ConfigFiles($this->dir);
        $plan = $configs->plan();
        $this->assertSame(['vite.config.js'], $plan['convert']);
        $this->assertSame(['phpstan.neon'], $plan['custom']);

        $configs->apply($plan);
        $this->assertStringContainsString('classicTheme(env', (string) file_get_contents($this->dir . '/vite.config.js'));
        $this->assertStringContainsString('wp-cli-stubs', (string) file_get_contents($this->dir . '/phpstan.neon'), 'an edited file is never touched');
        $this->assertSame(['vite.config.js'], $configs->plan()['current']);
    }

    public function test_known_versions_cover_the_current_scaffold_copies(): void
    {
        $scaffold = dirname(__DIR__, 4) . '/taw-theme';
        if (!is_file($scaffold . '/phpstan.neon')) {
            $this->markTestSkipped('needs the umbrella checkout of taw-theme next to taw-core');
        }
        $known = json_decode((string) file_get_contents(ConfigFiles::root() . '/known.json'), true)['classic'];
        foreach (array_keys(ConfigFiles::FILES) as $path) {
            $contents = (string) file_get_contents($scaffold . '/' . $path);
            $this->assertTrue(
                str_contains($contents, ConfigFiles::MARKER) || in_array(hash('sha256', $contents), $known[$path], true),
                "taw-theme's {$path} is neither a short taw:config file nor a known version: regenerate resources/configs/known.json"
            );
        }
    }

    public function test_the_templates_load_files_that_exist(): void
    {
        $root = dirname(ConfigFiles::root(), 2);
        $this->assertStringContainsString('vendor/taw/core/resources/phpstan/classic.neon', (string) file_get_contents(ConfigFiles::root() . '/classic/phpstan.neon'));
        $this->assertFileExists($root . '/resources/phpstan/classic.neon');
        $this->assertStringContainsString('export function classicTheme', (string) file_get_contents($root . '/resources/vite/taw-vite.mjs'));
        foreach (ConfigFiles::FILES as $template) {
            $this->assertStringContainsString(ConfigFiles::MARKER, (string) file_get_contents(ConfigFiles::root() . '/classic/' . $template));
        }
    }

    public function test_the_command_lists_what_to_do_by_hand(): void
    {
        file_put_contents($this->dir . '/phpstan.neon', "parameters:\n    level: 9\n");
        $tester = new CommandTester(new ConfigsSyncCommand($this->dir));
        $tester->execute(['--json' => true]);
        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(['phpstan.neon'], $report['custom']);
        $this->assertStringContainsString('add this site\'s own lines', $report['by_hand']['phpstan.neon']);
    }
}
