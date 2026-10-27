<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Helpers;

use TAW\Helpers\Framework;
use TAW\Tests\TestCase;

/**
 * Framework::version() runs on every request (Loop, Bindings, Metabox…), so
 * it must never throw. Composer\InstalledVersions is one global class, and a
 * plugin that bundles Composer (Wordfence) can be the loaded copy; when that
 * copy doesn't know taw/core, getPrettyVersion() throws OutOfBoundsException
 * — a "critical error" page on a live site (v1.99.2). version() now reads the
 * installed.php of its own vendor directory first, and asks InstalledVersions
 * only as a guarded fallback.
 */
final class FrameworkVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/taw-framework-version-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);

        parent::tearDown();
    }

    public function test_reads_the_version_from_installed_php_without_asking_installed_versions(): void
    {
        $file = $this->installedPhp(['taw/core' => ['pretty_version' => 'v1.99.2']]);

        $this->assertSame('v1.99.2', Framework::readInstalledVersion($file, ThrowingInstalledVersions::class));
    }

    public function test_falls_back_to_installed_versions_when_installed_php_is_missing(): void
    {
        $this->assertSame('v1.2.3', Framework::readInstalledVersion($this->dir . '/missing.php', KnowingInstalledVersions::class));
    }

    public function test_falls_back_when_installed_php_has_no_taw_core_entry(): void
    {
        $file = $this->installedPhp(['symfony/console' => ['pretty_version' => 'v7.4.0']]);

        $this->assertSame('v1.2.3', Framework::readInstalledVersion($file, KnowingInstalledVersions::class));
    }

    public function test_null_when_installed_versions_does_not_know_taw_core(): void
    {
        $this->assertNull(Framework::readInstalledVersion($this->dir . '/missing.php', ForeignInstalledVersions::class));
    }

    public function test_null_when_installed_versions_throws(): void
    {
        $this->assertNull(Framework::readInstalledVersion($this->dir . '/missing.php', ThrowingInstalledVersions::class));
    }

    public function test_null_when_installed_versions_is_not_loaded(): void
    {
        $this->assertNull(Framework::readInstalledVersion($this->dir . '/missing.php', 'TAW\\Tests\\NoSuchInstalledVersions'));
    }

    public function test_version_is_a_non_empty_string(): void
    {
        $this->assertNotSame('', Framework::version());
    }

    /**
     * The production failure, end to end: a vendored copy of Framework.php
     * (vendor/taw/core/src/Helpers) in a PHP process where a foreign
     * Composer\InstalledVersions that throws is the loaded one, as on the
     * WP Engine site with Wordfence active.
     */
    public function test_vendored_copy_reads_its_own_installed_php_when_a_foreign_installed_versions_is_loaded(): void
    {
        $vendor = $this->dir . '/theme/vendor';
        mkdir($vendor . '/taw/core/src/Helpers', 0777, true);
        mkdir($vendor . '/composer', 0777, true);
        copy((new \ReflectionClass(Framework::class))->getFileName(), $vendor . '/taw/core/src/Helpers/Framework.php');
        file_put_contents($vendor . '/composer/installed.php', '<?php return ' . var_export([
            'root'     => ['name' => 'acme/theme', 'pretty_version' => 'dev-main'],
            'versions' => ['taw/core' => ['pretty_version' => 'v1.99.2']],
        ], true) . ';');

        $script = $this->dir . '/run.php';
        file_put_contents($script, <<<'PHP'
            <?php
            namespace Composer {
                class InstalledVersions
                {
                    public static function isInstalled($name) { throw new \OutOfBoundsException('Package "' . $name . '" is not installed'); }
                    public static function getPrettyVersion($name) { throw new \OutOfBoundsException('Package "' . $name . '" is not installed'); }
                }
            }
            namespace {
                require $argv[1];
                echo \TAW\Helpers\Framework::version(), '|', \TAW\Helpers\Framework::installedVersion() ?? 'null';
            }
            PHP);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($vendor . '/taw/core/src/Helpers/Framework.php') . ' 2>&1', $output, $exit);

        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertSame('v1.99.2|v1.99.2', implode("\n", $output));
    }

    /** @param array<string, array<string, string>> $versions */
    private function installedPhp(array $versions): string
    {
        $file = $this->dir . '/installed.php';
        file_put_contents($file, '<?php return ' . var_export(['root' => [], 'versions' => $versions], true) . ';');

        return $file;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

/** Composer\InstalledVersions as a plugin's own copy answers: taw/core isn't its package. */
final class ForeignInstalledVersions
{
    public static function isInstalled(string $name): bool
    {
        return false;
    }

    public static function getPrettyVersion(string $name): ?string
    {
        throw new \OutOfBoundsException('Package "' . $name . '" is not installed');
    }
}

/** A copy that throws on any question. */
final class ThrowingInstalledVersions
{
    public static function isInstalled(string $name): bool
    {
        throw new \OutOfBoundsException('Package "' . $name . '" is not installed');
    }

    public static function getPrettyVersion(string $name): ?string
    {
        throw new \OutOfBoundsException('Package "' . $name . '" is not installed');
    }
}

/** The theme's own copy: it knows taw/core. */
final class KnowingInstalledVersions
{
    public static function isInstalled(string $name): bool
    {
        return $name === 'taw/core';
    }

    public static function getPrettyVersion(string $name): ?string
    {
        return 'v1.2.3';
    }
}
