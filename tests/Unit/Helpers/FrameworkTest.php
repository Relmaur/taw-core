<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Helpers;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    // ── Locations other than the parent theme (ADR-0003) ──────────────────────
    //
    // taw/core can now be vendored by a child theme, a site plugin or an
    // mu-plugin, not only the active parent theme. Each scenario pretends the
    // package's real parent directory is that location. They run in separate
    // processes because they define WordPress constants (WP_PLUGIN_DIR…),
    // which can't be undefined afterwards, and because a WP function defined
    // by Brain Monkey in an earlier test would throw if called unstubbed.

    #[RunInSeparateProcess]
    public function test_url_resolves_inside_a_child_theme(): void
    {
        Functions\when('get_template_directory')->justReturn('/srv/wp/wp-content/themes/parent');
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/parent');
        Functions\when('get_stylesheet_directory')->justReturn(dirname(Framework::path()));
        Functions\when('get_stylesheet_directory_uri')->justReturn('https://example.test/wp-content/themes/child');

        $url = Framework::url('assets/admin.css');

        $this->assertSame(
            'https://example.test/wp-content/themes/child/' . basename(Framework::path()) . '/assets/admin.css',
            $url
        );
    }

    #[RunInSeparateProcess]
    public function test_url_resolves_inside_a_plugin_and_prefers_the_most_specific_root(): void
    {
        Functions\when('get_template_directory')->justReturn('/srv/wp/wp-content/themes/some-theme');
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/some-theme');
        // Plugins dir nested inside wp-content, like a real install — the
        // longer (plugins) root must win over the shorter (wp-content) one.
        define('WP_CONTENT_DIR', dirname(Framework::path(), 2));
        define('WP_PLUGIN_DIR', dirname(Framework::path()));
        Functions\when('content_url')->justReturn('https://example.test/wp-content');
        Functions\when('plugins_url')->justReturn('https://example.test/wp-content/plugins');

        $url = Framework::url('assets/admin.css');

        $this->assertSame(
            'https://example.test/wp-content/plugins/' . basename(Framework::path()) . '/assets/admin.css',
            $url
        );
    }

    #[RunInSeparateProcess]
    public function test_a_sibling_directory_sharing_a_name_prefix_is_not_treated_as_the_parent_theme(): void
    {
        // "/…/taw-cor" is a string prefix of "/…/taw-core" but not its parent
        // directory — the parent-theme fast path must not match it.
        Functions\when('get_template_directory')->justReturn(substr(Framework::path(), 0, -1));
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/wrong');
        Functions\when('get_stylesheet_directory')->justReturn(dirname(Framework::path()));
        Functions\when('get_stylesheet_directory_uri')->justReturn('https://example.test/wp-content/themes/child');

        $url = Framework::url('assets/admin.css');

        $this->assertStringStartsWith('https://example.test/wp-content/themes/child/', $url);
    }

    #[RunInSeparateProcess]
    public function test_url_falls_back_to_the_legacy_result_in_an_unknown_location(): void
    {
        Functions\when('get_template_directory')->justReturn('/srv/wp/wp-content/themes/some-theme');
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/some-theme');

        $url = Framework::url('assets/admin.css');

        // Exactly what url() returned before ADR-0003 — no guessing.
        $this->assertSame(
            'https://example.test/wp-content/themes/some-theme/' . Framework::path('assets/admin.css'),
            $url
        );
    }

    public function test_the_package_url_filter_can_override_the_result(): void
    {
        Functions\when('get_template_directory')->justReturn(Framework::path());
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/my-theme');
        Filters\expectApplied('taw_core_package_url')
            ->once()
            ->with('https://example.test/wp-content/themes/my-theme/assets/admin.css', 'assets/admin.css')
            ->andReturn('https://cdn.example.test/taw/assets/admin.css');

        $this->assertSame('https://cdn.example.test/taw/assets/admin.css', Framework::url('assets/admin.css'));
    }
}
