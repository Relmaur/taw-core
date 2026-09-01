<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\LogTailCommand;
use TAW\Tests\TestCase;

final class LogTailCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-log-tail-' . getmypid() . '-' . uniqid();
        @mkdir($this->tmp, 0777, true);
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

    public function test_resolves_the_log_dir_from_a_standard_theme_layout(): void
    {
        $wpRoot   = $this->tmp . '/wp';
        $themeDir = $wpRoot . '/wp-content/themes/taw-theme';
        @mkdir($themeDir, 0777, true);
        file_put_contents($wpRoot . '/wp-load.php', '<?php');

        $this->assertSame(
            $wpRoot . '/wp-content/taw-logs',
            LogTailCommand::resolveLogDir($themeDir),
        );
    }

    public function test_returns_null_when_wp_load_cannot_be_found(): void
    {
        $themeDir = $this->tmp . '/loose/theme';
        @mkdir($themeDir, 0777, true);

        $this->assertNull(LogTailCommand::resolveLogDir($themeDir));
    }

    public function test_rejects_an_unknown_level(): void
    {
        $tester = new CommandTester(new LogTailCommand($this->tmp));
        $exit = $tester->execute(['--level' => 'loud']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown level', $tester->getDisplay());
    }

    public function test_prints_matching_entries_as_json(): void
    {
        $wpRoot   = $this->tmp . '/wp';
        $themeDir = $wpRoot . '/wp-content/themes/taw-theme';
        $logDir   = $wpRoot . '/wp-content/taw-logs';
        @mkdir($themeDir, 0777, true);
        @mkdir($logDir, 0777, true);
        file_put_contents($wpRoot . '/wp-load.php', '<?php');
        file_put_contents(
            $logDir . '/taw.log.jsonl',
            json_encode(['ts' => '2026-09-01T00:00:00+00:00', 'level' => 'error', 'code' => 'form.x', 'message' => 'boom', 'context' => [], 'request_id' => 'r']) . "\n",
        );

        $tester = new CommandTester(new LogTailCommand($themeDir));
        $exit = $tester->execute(['--json' => true, '--level' => 'error']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"code": "form.x"', $tester->getDisplay());
    }
}
