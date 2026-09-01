<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use TAW\CLI\HubInstallCommand;
use TAW\Tests\TestCase;

final class HubInstallCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-hub-install-' . getmypid() . '-' . uniqid();
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

    public function test_resolves_plugins_dir_from_a_standard_theme_layout(): void
    {
        $wpRoot   = $this->tmp . '/wp';
        $themeDir = $wpRoot . '/wp-content/themes/taw-theme';
        @mkdir($themeDir, 0777, true);
        @mkdir($wpRoot . '/wp-content/plugins', 0777, true);
        file_put_contents($wpRoot . '/wp-load.php', '<?php');

        $this->assertSame(
            $wpRoot . '/wp-content/plugins',
            HubInstallCommand::resolvePluginsDir($themeDir),
        );
    }

    public function test_falls_back_to_two_levels_up_when_wp_load_is_absent(): void
    {
        // theme at <x>/themes/taw-theme, plugins at <x>/plugins — no wp-load.php
        $base     = $this->tmp . '/wp-content';
        $themeDir = $base . '/themes/taw-theme';
        @mkdir($themeDir, 0777, true);
        @mkdir($base . '/plugins', 0777, true);

        $this->assertSame($base . '/plugins', HubInstallCommand::resolvePluginsDir($themeDir));
    }

    public function test_returns_null_when_no_plugins_directory_exists(): void
    {
        $themeDir = $this->tmp . '/nowhere/themes/taw-theme';
        @mkdir($themeDir, 0777, true);

        $this->assertNull(HubInstallCommand::resolvePluginsDir($themeDir));
    }
}
