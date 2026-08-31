<?php

declare(strict_types=1);

namespace TAW\Hub\Assets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checks that an extracted payload is a coherent Vite build: the manifest
 * parses, has the expected shape, and every file it references was actually
 * in the archive. Guards against a half-uploaded or hand-tampered `dist/`
 * being swapped in.
 */
final class ViteManifestValidator
{
    /**
     * @param array<string, mixed> $manifest        Decoded manifest.json.
     * @param list<string>          $extractedFiles  Relative paths present in the payload.
     * @throws PayloadException
     */
    public function validate(array $manifest, array $extractedFiles): void
    {
        if ($manifest === []) {
            throw new PayloadException(PayloadException::MANIFEST_INVALID, 'empty manifest');
        }

        $present = array_fill_keys($extractedFiles, true);

        foreach ($manifest as $key => $chunk) {
            if (!is_array($chunk)) {
                throw new PayloadException(PayloadException::MANIFEST_INVALID, "bad chunk: {$key}");
            }

            $file = $chunk['file'] ?? null;
            if (!is_string($file) || $file === '') {
                throw new PayloadException(PayloadException::MANIFEST_INVALID, "chunk {$key} has no file");
            }
            if (!isset($present[$file])) {
                throw new PayloadException(
                    PayloadException::MANIFEST_INVALID,
                    "manifest references a missing file: {$file}",
                );
            }

            foreach ($this->referencedAssets($chunk) as $asset) {
                if (!isset($present[$asset])) {
                    throw new PayloadException(
                        PayloadException::MANIFEST_INVALID,
                        "manifest references a missing asset: {$asset}",
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $chunk
     * @return list<string>
     */
    private function referencedAssets(array $chunk): array
    {
        $assets = [];
        foreach (['css', 'assets'] as $group) {
            $value = $chunk[$group] ?? [];
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && $item !== '') {
                        $assets[] = $item;
                    }
                }
            }
        }

        return $assets;
    }
}
