<?php

declare(strict_types=1);

namespace TAW\Hub\Assets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extracts a Hub-pushed asset archive (a Vite `dist/`) into a destination
 * directory, entry by entry, refusing anything unsafe.
 *
 * Deliberately does NOT use `ZipArchive::extractTo()` — that gives no
 * per-entry control. Every entry is checked for:
 *   - path traversal / absolute paths / NUL bytes / backslashes
 *   - a final resolved path that escapes the destination
 *   - symlink entries (external-attr S_IFLNK)
 *   - a disallowed file extension
 *   - per-file, total, and compression-ratio (zip-bomb) size limits
 *
 * The archive itself is size-checked before it's opened. This is stricter
 * than {@see \TAW\CLI\ImportBlockCommand} on purpose: that runs on a file a
 * developer hand-picked; this runs on whatever crossed the wire.
 */
final class PayloadExtractor
{
    public function __construct(private ExtractionLimits $limits = new ExtractionLimits())
    {
    }

    /**
     * @return list<string> Relative paths of the files written, sorted.
     * @throws PayloadException
     */
    public function extract(string $archivePath, string $destDir): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new PayloadException(PayloadException::NO_ZIP_EXTENSION);
        }

        $size = is_file($archivePath) ? filesize($archivePath) : false;
        if ($size === false) {
            throw new PayloadException(PayloadException::ARCHIVE_UNREADABLE);
        }
        if ($size > $this->limits->maxArchiveBytes) {
            throw new PayloadException(PayloadException::ARCHIVE_TOO_LARGE);
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new PayloadException(PayloadException::ARCHIVE_UNREADABLE);
        }

        try {
            if ($zip->numFiles > $this->limits->maxEntries) {
                throw new PayloadException(PayloadException::TOO_MANY_ENTRIES);
            }

            $canonicalDest = $this->prepareDestination($destDir);
            $written       = [];
            $runningTotal  = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new PayloadException(PayloadException::ARCHIVE_UNREADABLE);
                }

                $name = (string) $stat['name'];
                $this->assertSafeName($name);

                // Directory entry — nothing to write, and mkdir happens lazily
                // when a real file needs it.
                if (str_ends_with($name, '/')) {
                    continue;
                }

                $this->assertNotSymlink($zip, $i);

                if (!$this->limits->allowsExtension($name)) {
                    throw new PayloadException(
                        PayloadException::DISALLOWED_FILE,
                        "Disallowed file in payload: {$name}",
                    );
                }

                $uncompressed = (int) $stat['size'];
                $compressed   = (int) $stat['comp_size'];

                if ($uncompressed > $this->limits->maxFileBytes) {
                    throw new PayloadException(PayloadException::ENTRY_TOO_LARGE, $name);
                }
                $runningTotal += $uncompressed;
                if ($runningTotal > $this->limits->maxTotalBytes) {
                    throw new PayloadException(PayloadException::PAYLOAD_TOO_LARGE);
                }
                if ($compressed > 0 && $uncompressed / $compressed > $this->limits->maxCompressionRatio) {
                    throw new PayloadException(PayloadException::COMPRESSION_BOMB, $name);
                }

                $target = $this->resolveTarget($canonicalDest, $name);
                $this->writeEntry($zip, $i, $target);
                $written[] = $name;
            }

            sort($written);

            return $written;
        } finally {
            $zip->close();
        }
    }

    private function prepareDestination(string $destDir): string
    {
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            throw new PayloadException(PayloadException::WRITE_FAILED, $destDir);
        }

        $real = realpath($destDir);
        if ($real === false) {
            throw new PayloadException(PayloadException::WRITE_FAILED, $destDir);
        }

        return $real;
    }

    private function assertSafeName(string $name): void
    {
        if (
            $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('~^[a-zA-Z]:~', $name) === 1
            || preg_match('~(^|/)\.\.(/|$)~', $name) === 1
        ) {
            throw new PayloadException(PayloadException::PATH_TRAVERSAL, $name);
        }
    }

    /**
     * The high 16 bits of a Unix entry's external attributes are the file
     * mode; S_IFLNK == 0xA000.
     */
    private function assertNotSymlink(\ZipArchive $zip, int $index): void
    {
        $opsys = 0;
        $attr  = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return;
        }

        if ($opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0xF000) === 0xA000)) {
            throw new PayloadException(PayloadException::SYMLINK_ENTRY);
        }
    }

    private function resolveTarget(string $canonicalDest, string $name): string
    {
        $target = $canonicalDest . '/' . $name;

        // Resolve against the deepest existing ancestor — the file itself
        // won't exist yet — and confirm it stays inside the destination.
        $dir = dirname($target);
        $probe = $dir;
        while (!is_dir($probe) && $probe !== dirname($probe)) {
            $probe = dirname($probe);
        }
        $realProbe = realpath($probe);
        if ($realProbe === false || !str_starts_with($realProbe . '/', $canonicalDest . '/')) {
            throw new PayloadException(PayloadException::PATH_TRAVERSAL, $name);
        }

        return $target;
    }

    private function writeEntry(\ZipArchive $zip, int $index, string $target): void
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new PayloadException(PayloadException::WRITE_FAILED, $target);
        }

        $stream = $zip->getStream($zip->getNameIndex($index) ?: '');
        if ($stream === false) {
            throw new PayloadException(PayloadException::ARCHIVE_UNREADABLE);
        }

        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new PayloadException(PayloadException::WRITE_FAILED, $target);
        }

        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
    }
}
