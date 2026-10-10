<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use TAW\CLI\Application;
use TAW\CLI\CommandRegistry;
use TAW\Tests\TestCase;

/**
 * `vendor/bin/taw`: the command list taw/core owns, per kind of theme, plus
 * commands added through CommandRegistry.
 */
final class ApplicationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-app-' . getmypid() . '-' . uniqid();
        @mkdir($this->dir, 0777, true);
        CommandRegistry::reset();
    }

    protected function tearDown(): void
    {
        CommandRegistry::reset();
        putenv('TAW_THEME_DIR');
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_classic_theme_gets_every_command(): void
    {
        $app = Application::create($this->dir);
        foreach (['make:block', 'sync', 'skills:sync', 'schema:validate', 'content:import', 'wp', 'log:tail', 'canon-law:install'] as $name) {
            $this->assertTrue($app->has($name), $name);
        }
        $this->assertCount(32, Application::coreCommands($this->dir), 'the list the scaffold bin/taw had, plus skills:sync, policy, docs:sync, configs:sync and upgrade');
    }

    public function test_a_block_theme_gets_the_commands_that_work_without_the_classic_scaffold(): void
    {
        file_put_contents($this->dir . '/theme.json', '{}');
        mkdir($this->dir . '/templates');

        $this->assertTrue(Application::isBlockTheme($this->dir));
        $app = Application::create($this->dir);
        $this->assertTrue($app->has('schema:validate'));
        $this->assertTrue($app->has('skills:sync'));
        $this->assertTrue($app->has('policy'));
        $this->assertTrue($app->has('docs:sync'));
        $this->assertTrue($app->has('upgrade'));
        $this->assertFalse($app->has('make:block'));
        $this->assertFalse($app->has('sync'));
        $this->assertFalse($app->has('configs:sync'), 'classic configs only');
    }

    public function test_registered_commands_join_and_core_names_stay_core(): void
    {
        CommandRegistry::add(fn (string $themeDir) => new class ($themeDir) extends Command {
            public function __construct(private string $themeDir)
            {
                parent::__construct('site:report');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $output->writeln('report for ' . basename($this->themeDir));

                return Command::SUCCESS;
            }
        });
        CommandRegistry::add(fn () => new Command('sync')); // must not replace core's

        $app = Application::create($this->dir);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $tester->run(['command' => 'site:report']);
        $this->assertStringContainsString('report for ' . basename($this->dir), $tester->getDisplay());
        $this->assertInstanceOf(\TAW\CLI\SyncCommand::class, $app->find('sync'));
    }

    public function test_the_theme_is_the_project_that_installed_taw_core(): void
    {
        $this->assertSame('/sites/acme/wp-content/themes/acme', Application::themeDir('/sites/acme/wp-content/themes/acme/vendor/autoload.php', '/tmp'));

        @mkdir($this->dir . '/vendor/taw/core/bin', 0777, true);
        touch($this->dir . '/vendor/autoload.php');
        $this->assertSame($this->dir, Application::themeDir(null, $this->dir . '/vendor/taw/core/bin'), 'walks up to vendor/autoload.php');

        putenv('TAW_THEME_DIR=/elsewhere/theme/');
        $this->assertSame('/elsewhere/theme', Application::themeDir('/x/vendor/autoload.php', '/tmp'), '$TAW_THEME_DIR wins');
    }
}
