<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use TAW\Helpers\Framework;
use TAW\Tests\TestCase;

/**
 * Framework::path() resolves via __DIR__, which PHP follows through any
 * symlink in the chain to this file's real location. get_template_directory()
 * does no such resolution — it's a plain string built from WordPress's own
 * constants. Whenever the theme directory is reached through a symlink (a
 * supported, machine-specific setup for this package — see taw-theme's
 * AGENTS.md), those two paths can diverge textually even though they point
 * at the same files. This reproduces that exact scenario with a real
 * symlink pointing at Framework::path()'s own real target, standing in for
 * "WordPress sees the theme through a symlink."
 */
final class FrameworkTest extends TestCase
{
    private string $symlinkThemeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->symlinkThemeDir = sys_get_temp_dir() . '/taw-framework-symlink-theme-' . getmypid();

        if (is_link($this->symlinkThemeDir) || file_exists($this->symlinkThemeDir)) {
            unlink($this->symlinkThemeDir);
        }

        symlink(Framework::path(), $this->symlinkThemeDir);
    }

    protected function tearDown(): void
    {
        unlink($this->symlinkThemeDir);

        parent::tearDown();
    }

    public function test_url_resolves_correctly_when_theme_directory_is_a_symlink(): void
    {
        Functions\when('get_template_directory')->justReturn($this->symlinkThemeDir);
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/my-theme');

        $url = Framework::url('assets/admin.css');

        $this->assertSame('https://example.test/wp-content/themes/my-theme/assets/admin.css', $url);
    }

    public function test_url_still_works_when_theme_directory_is_not_a_symlink(): void
    {
        Functions\when('get_template_directory')->justReturn(Framework::path());
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/my-theme');

        $url = Framework::url('assets/admin.css');

        $this->assertSame('https://example.test/wp-content/themes/my-theme/assets/admin.css', $url);
    }
}
