<?php

declare(strict_types=1);

namespace TAW\Hub\Assets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Swaps a Hub-pushed Vite build in for the live one, safely and reversibly:
 *
 *   stage()    extract + validate into a sibling staging dir (same filesystem
 *              as the build dir, so the swap is an atomic rename)
 *   apply()    rename live build → rollback dir, rename staging → live
 *   rollback() reverse the last apply()
 *
 * One rollback generation is kept; older ones are pruned on apply(). All
 * working directories are siblings of the build dir (dot-prefixed) so nothing
 * crosses a filesystem boundary and `rename()` stays atomic.
 */
final class DeploymentTransaction
{
    private const MARKER         = '.taw-hub-deployment.json';
    private const STAGING_DIR    = '.taw-hub-staging';
    private const ROLLBACK_PREFIX = '.taw-hub-rollback-';
    private const TRASH_PREFIX    = '.taw-hub-trash-';

    private string $parent;
    private string $stagingRoot;

    public function __construct(
        private string $buildDir,
        private PayloadExtractor $extractor = new PayloadExtractor(),
        private ViteManifestValidator $validator = new ViteManifestValidator(),
    ) {
        $this->parent      = dirname($this->buildDir);
        $this->stagingRoot = $this->parent . '/' . self::STAGING_DIR;
    }

    /**
     * @return array{deployment_id: string, files: int, manifest_hash: string}
     * @throws PayloadException
     */
    public function stage(string $archivePath): array
    {
        $id  = bin2hex(random_bytes(8));
        $dir = $this->stagingRoot . '/' . $id;

        $files    = $this->extractor->extract($archivePath, $dir);
        $manifest = $this->readManifest($dir);
        $this->validator->validate($manifest, $files);

        $hash = hash('sha256', implode("\n", $files) . "\0" . (string) json_encode($manifest));

        $marker = [
            'id'            => $id,
            'staged_at'     => time(),
            'files'         => count($files),
            'manifest_hash' => $hash,
        ];
        file_put_contents($dir . '/' . self::MARKER, (string) json_encode($marker));

        return ['deployment_id' => $id, 'files' => count($files), 'manifest_hash' => $hash];
    }

    /**
     * @return array{applied: true, manifest_hash: string, rollback_id: string|null}
     * @throws PayloadException
     */
    public function apply(string $deploymentId): array
    {
        $staged = $this->stagingDir($deploymentId);
        $marker = $this->readMarker($staged);

        $rollbackId  = null;
        $rollbackDir = null;

        if (is_dir($this->buildDir)) {
            $rollbackId  = bin2hex(random_bytes(6));
            $rollbackDir = $this->parent . '/' . self::ROLLBACK_PREFIX . $rollbackId;
            if (!@rename($this->buildDir, $rollbackDir)) {
                throw new PayloadException(PayloadException::SWAP_FAILED, 'could not move the current build aside');
            }
        }

        if (!@rename($staged, $this->buildDir)) {
            // Put the old build back before giving up.
            if ($rollbackDir !== null && is_dir($rollbackDir)) {
                @rename($rollbackDir, $this->buildDir);
            }
            throw new PayloadException(PayloadException::SWAP_FAILED, 'could not move the new build into place');
        }

        // Drop the deployment marker from the now-live dir.
        @unlink($this->buildDir . '/' . self::MARKER);

        $this->pruneRollbacks($rollbackId);

        return [
            'applied'       => true,
            'manifest_hash' => (string) ($marker['manifest_hash'] ?? ''),
            'rollback_id'   => $rollbackId,
        ];
    }

    /**
     * @return array{rolled_back_to: string}
     * @throws PayloadException
     */
    public function rollback(string $rollbackId): array
    {
        $rollbackDir = $this->parent . '/' . self::ROLLBACK_PREFIX . $rollbackId;
        if (!is_dir($rollbackDir)) {
            throw new PayloadException(PayloadException::DEPLOYMENT_UNKNOWN, $rollbackId);
        }

        if (is_dir($this->buildDir)) {
            $trash = $this->parent . '/' . self::TRASH_PREFIX . bin2hex(random_bytes(6));
            if (!@rename($this->buildDir, $trash)) {
                throw new PayloadException(PayloadException::SWAP_FAILED, 'could not move the current build to trash');
            }
            $this->removeTree($trash);
        }

        if (!@rename($rollbackDir, $this->buildDir)) {
            throw new PayloadException(PayloadException::SWAP_FAILED, 'could not restore the rollback build');
        }

        return ['rolled_back_to' => $rollbackId];
    }

    /**
     * @return array{state: string, manifest_hash?: string, staged_at?: int}
     */
    public function status(string $deploymentId): array
    {
        $dir = $this->stagingRoot . '/' . $deploymentId;
        if (!is_dir($dir) || !is_file($dir . '/' . self::MARKER)) {
            return ['state' => 'unknown'];
        }

        $marker = $this->readMarker($dir);

        return [
            'state'         => 'staged',
            'manifest_hash' => (string) ($marker['manifest_hash'] ?? ''),
            'staged_at'     => (int) ($marker['staged_at'] ?? 0),
        ];
    }

    private function stagingDir(string $deploymentId): string
    {
        if (preg_match('/^[0-9a-f]{16}$/', $deploymentId) !== 1) {
            throw new PayloadException(PayloadException::DEPLOYMENT_UNKNOWN, $deploymentId);
        }
        $dir = $this->stagingRoot . '/' . $deploymentId;
        if (!is_dir($dir)) {
            throw new PayloadException(PayloadException::DEPLOYMENT_UNKNOWN, $deploymentId);
        }

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $dir): array
    {
        foreach (['.vite/manifest.json', 'manifest.json'] as $candidate) {
            $path = $dir . '/' . $candidate;
            if (is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);

                return is_array($decoded) ? $decoded : throw new PayloadException(PayloadException::MANIFEST_INVALID);
            }
        }

        throw new PayloadException(PayloadException::MANIFEST_MISSING);
    }

    /**
     * @return array<string, mixed>
     */
    private function readMarker(string $dir): array
    {
        $path = $dir . '/' . self::MARKER;
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function pruneRollbacks(?string $keep): void
    {
        $dirs = glob($this->parent . '/' . self::ROLLBACK_PREFIX . '*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            if ($keep !== null && basename($dir) === self::ROLLBACK_PREFIX . $keep) {
                continue;
            }
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
