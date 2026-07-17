<?php

declare(strict_types=1);

namespace TAW\Tests\Unit;

use TAW\Tests\TestCase;

/**
 * Guards against the exact regression BaseBlock::enqueueDevAssets() had:
 * every dev-mode asset URL is supposed to go through
 * ViteLoader::devServerOrigin() (hot-file-aware, correct even when Vite
 * fell back off its default port) — never the raw ViteLoader::DEV_SERVER
 * constant, which is only ever correct when Vite happens to still be on
 * port 5173. BaseBlock used the constant directly, silently pointing
 * every block's dev script.js/style.css at the wrong origin whenever Vite
 * picked a different port — a working main bundle (correct, via the hot
 * file) masked broken per-block assets, which is exactly why this needs a
 * standing check rather than relying on someone noticing by eye again.
 */
final class DevServerOriginUsageTest extends TestCase
{
    public function test_only_vite_loader_itself_references_the_dev_server_constant(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if ($file->getFilename() === 'ViteLoader.php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && str_contains($contents, 'ViteLoader::DEV_SERVER')) {
                $offenders[] = str_replace($srcDir . '/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Use ViteLoader::devServerOrigin() instead of the raw ViteLoader::DEV_SERVER constant in: "
                . implode(', ', $offenders)
        );
    }
}
