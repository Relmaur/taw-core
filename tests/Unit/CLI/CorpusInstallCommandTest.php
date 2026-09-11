<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Brain\Monkey\Functions;
use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\CorpusInstallCommand;
use TAW\Tests\TestCase;

final class CorpusInstallCommandTest extends TestCase
{
    private string $tmp;
    private string $uploadsDir;
    private string $themeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . '/taw-corpus-install-' . getmypid() . '-' . uniqid();
        $this->themeDir = $this->tmp . '/wp/wp-content/themes/taw-theme';
        $this->uploadsDir = $this->tmp . '/uploads';
        @mkdir($this->themeDir, 0777, true);
        @mkdir($this->uploadsDir, 0777, true);
        file_put_contents($this->tmp . '/wp/wp-load.php', '<?php');

        Functions\when('wp_upload_dir')->justReturn(['basedir' => $this->uploadsDir]);
        Functions\when('trailingslashit')->alias(static fn (string $s): string => rtrim($s, '/\\') . '/');
        Functions\when('wp_mkdir_p')->alias(static fn (string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
    }

    protected function tearDown(): void
    {
        $it = @new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it ?: [] as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function fakeSqliteFile(): string
    {
        $path = $this->tmp . '/source.sqlite';
        (new \PDO('sqlite:' . $path))->exec('CREATE TABLE t (a TEXT)');

        return $path;
    }

    public function test_installs_a_valid_sqlite_file_under_the_given_filename(): void
    {
        $source = $this->fakeSqliteFile();

        $tester = new CommandTester(new CorpusInstallCommand($this->themeDir));
        $exit = $tester->execute(['path' => $source, 'filename' => 'bible-straubinger.sqlite']);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->uploadsDir . '/taw-private/corpus/bible-straubinger.sqlite');
        $this->assertStringContainsString('Installed', $tester->getDisplay());
    }

    public function test_rejects_a_missing_source_file(): void
    {
        $tester = new CommandTester(new CorpusInstallCommand($this->themeDir));
        $exit = $tester->execute(['path' => $this->tmp . '/nope.sqlite', 'filename' => 'bible-straubinger.sqlite']);

        $this->assertSame(1, $exit);
        $this->assertFileDoesNotExist($this->uploadsDir . '/taw-private/corpus/bible-straubinger.sqlite');
    }

    public function test_rejects_a_non_sqlite_source_file(): void
    {
        $source = $this->tmp . '/not-a-db.sqlite';
        file_put_contents($source, 'plain text, not a database');

        $tester = new CommandTester(new CorpusInstallCommand($this->themeDir));
        $exit = $tester->execute(['path' => $source, 'filename' => 'bible-straubinger.sqlite']);

        $this->assertSame(1, $exit);
        $this->assertFileDoesNotExist($this->uploadsDir . '/taw-private/corpus/bible-straubinger.sqlite');
    }

    public function test_rejects_a_filename_with_path_segments(): void
    {
        $source = $this->fakeSqliteFile();

        $tester = new CommandTester(new CorpusInstallCommand($this->themeDir));
        $exit = $tester->execute(['path' => $source, 'filename' => '../../evil.sqlite']);

        $this->assertSame(1, $exit);
    }

    public function test_rejects_a_filename_not_ending_in_sqlite(): void
    {
        $source = $this->fakeSqliteFile();

        $tester = new CommandTester(new CorpusInstallCommand($this->themeDir));
        $exit = $tester->execute(['path' => $source, 'filename' => 'bible-straubinger.db']);

        $this->assertSame(1, $exit);
    }

    public function test_re_running_against_the_same_source_overwrites_cleanly(): void
    {
        $source = $this->fakeSqliteFile();

        $tester = new CommandTester(new CorpusInstallCommand($this->themeDir));
        $tester->execute(['path' => $source, 'filename' => 'bible-straubinger.sqlite']);
        $exit = $tester->execute(['path' => $source, 'filename' => 'bible-straubinger.sqlite']);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->uploadsDir . '/taw-private/corpus/bible-straubinger.sqlite');
    }
}
