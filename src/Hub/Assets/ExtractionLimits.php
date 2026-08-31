<?php

declare(strict_types=1);

namespace TAW\Hub\Assets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hard ceilings for {@see PayloadExtractor}. Conservative on purpose — a Vite
 * `dist/` for a theme is a few MB; anything near these limits is a mistake or
 * an attack.
 */
final class ExtractionLimits
{
    /**
     * @param int           $maxArchiveBytes   Reject the .zip itself above this (default 50 MB).
     * @param int           $maxTotalBytes     Reject if the sum of uncompressed entries exceeds this (default 150 MB).
     * @param int           $maxFileBytes      Reject any single uncompressed entry above this (default 25 MB).
     * @param int           $maxEntries        Reject an archive with more entries than this.
     * @param int           $maxCompressionRatio Reject an entry whose uncompressed/compressed ratio exceeds this (zip bomb).
     * @param list<string>  $allowedExtensions Lower-case, without the dot. Everything else is refused.
     */
    public function __construct(
        public int $maxArchiveBytes = 52_428_800,
        public int $maxTotalBytes = 157_286_400,
        public int $maxFileBytes = 26_214_400,
        public int $maxEntries = 5_000,
        public int $maxCompressionRatio = 200,
        public array $allowedExtensions = [
            'js', 'mjs', 'cjs', 'css', 'map', 'json',
            'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'txt', 'html',
        ],
    ) {
    }

    public function allowsExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $this->allowedExtensions, true);
    }
}
