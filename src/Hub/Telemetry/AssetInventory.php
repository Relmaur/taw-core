<?php

declare(strict_types=1);

namespace TAW\Hub\Telemetry;

use TAW\Support\ViteLoader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The state of the theme's Vite build as this site currently sees it: which
 * entry points the manifest declares, where the built files live, whether a
 * dev server is shadowing the build, and a hash of the whole manifest so the
 * Hub can detect drift after (or instead of) a deploy.
 */
final class AssetInventory
{
    /**
     * @return array{dev_server: bool, dist_dir: string, entry_count: int, manifest_hash: string|null, entries: list<array{src: string, file: string|null}>}
     */
    public static function collect(): array
    {
        $manifest = self::fromManifest(ViteLoader::getManifest());

        return [
            'dev_server'    => ViteLoader::isDevServerRunning(),
            'dist_dir'      => ViteLoader::distDir(),
            'entry_count'   => $manifest['entry_count'],
            'manifest_hash' => $manifest['manifest_hash'],
            'entries'       => $manifest['entries'],
        ];
    }

    /**
     * The manifest-derived half — split out so it's testable without the
     * ViteLoader dev-server probe / theme-path resolution.
     *
     * @param array<string, mixed> $manifest
     * @return array{entry_count: int, manifest_hash: string|null, entries: list<array{src: string, file: string|null}>}
     */
    public static function fromManifest(array $manifest): array
    {
        $entries = [];
        foreach ($manifest as $src => $chunk) {
            if (!is_array($chunk) || ($chunk['isEntry'] ?? false) !== true) {
                continue;
            }
            $file = $chunk['file'] ?? null;
            $entries[] = ['src' => (string) $src, 'file' => is_string($file) ? $file : null];
        }

        usort($entries, static fn (array $a, array $b): int => $a['src'] <=> $b['src']);

        return [
            'entry_count'   => count($entries),
            'manifest_hash' => $manifest === [] ? null : hash('sha256', self::stableJson($manifest)),
            'entries'       => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function stableJson(array $manifest): string
    {
        ksort($manifest);

        return (string) json_encode($manifest);
    }
}
