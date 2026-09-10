<?php

declare(strict_types=1);

namespace TAW\Core\Content;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the `media[]` section of a snapshot against the target site.
 *
 * Matching is by **filename**, never by numeric ID (IDs are meaningless
 * across environments). For each media entry: find an existing attachment
 * with that filename; if there is none and the entry carries a URL,
 * sideload it. The result is an `old id => new id` map the importer uses
 * to rewrite every attachment reference — in `post_content`
 * (`wp-image-<id>` classes, `"id":<id>` block attrs) and in `image` /
 * `files` field values — before anything is written.
 *
 * The string-rewriting half ({@see self::rewriteContent()},
 * {@see self::applyIdMap()}) is pure and unit-tested; the DB lookup and
 * sideload are thin wrappers over WordPress core.
 */
final class MediaResolver
{
    /** @var array<int, int> old (source) attachment id => new (target) attachment id */
    private array $idMap = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $sideloadedCount = 0;

    /**
     * Build the ID map from a snapshot's `media[]` entries. Safe to call in
     * a dry run — pass $sideload=false to only match existing attachments
     * and record what *would* be sideloaded as a warning-style note.
     *
     * @param list<array<string, mixed>> $mediaEntries
     */
    public function build(array $mediaEntries, bool $sideload = true): void
    {
        foreach ($mediaEntries as $entry) {
            $sourceId = (int) ($entry['id'] ?? 0);
            $filename = (string) ($entry['filename'] ?? $entry['ref'] ?? '');

            if ($filename === '') {
                $this->warnings[] = 'Media entry with no filename skipped.';
                continue;
            }

            $existing = self::findByFilename($filename);
            if ($existing !== null) {
                if ($sourceId > 0) {
                    $this->idMap[$sourceId] = $existing;
                }
                continue;
            }

            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                $this->warnings[] = "Media '{$filename}' is not on the target site and has no URL to sideload from.";
                continue;
            }

            if (!$sideload) {
                $this->warnings[] = "Media '{$filename}' would be sideloaded from {$url}.";
                continue;
            }

            $newId = $this->sideload($url, $entry);
            if ($newId === null) {
                $this->warnings[] = "Failed to sideload media '{$filename}' from {$url}.";
                continue;
            }

            $this->sideloadedCount++;
            if ($sourceId > 0) {
                $this->idMap[$sourceId] = $newId;
            }
        }
    }

    /** @return array<int, int> */
    public function idMap(): array
    {
        return $this->idMap;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function sideloadedCount(): int
    {
        return $this->sideloadedCount;
    }

    /**
     * Rewrite attachment IDs inside post_content — Gutenberg image blocks
     * (`wp-image-<id>` on the <img>, `"id":<id>` in the block comment) and
     * gallery blocks (`"ids":[...]`).
     *
     * @param array<int, int> $idMap
     */
    public static function rewriteContent(string $content, array $idMap): string
    {
        if ($idMap === []) {
            return $content;
        }

        $content = (string) preg_replace_callback(
            '/wp-image-(\d+)/',
            static fn (array $m): string => 'wp-image-' . ($idMap[(int) $m[1]] ?? $m[1]),
            $content
        );

        $content = (string) preg_replace_callback(
            '/"id":(\d+)/',
            static fn (array $m): string => '"id":' . ($idMap[(int) $m[1]] ?? $m[1]),
            $content
        );

        $content = (string) preg_replace_callback(
            '/"ids":\[([\d,\s]*)\]/',
            static function (array $m) use ($idMap): string {
                $ids = array_filter(array_map('trim', explode(',', $m[1])), static fn ($s): bool => $s !== '');
                $mapped = array_map(static fn ($id): int => $idMap[(int) $id] ?? (int) $id, $ids);
                return '"ids":[' . implode(',', $mapped) . ']';
            },
            $content
        );

        return $content;
    }

    /**
     * Locate an existing attachment whose file is named $filename
     * (basename match against `_wp_attached_file`).
     */
    public static function findByFilename(string $filename): ?int
    {
        $filename = wp_basename($filename);

        $matches = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 2,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_wp_attached_file',
                    'value'   => '/' . $filename,
                    'compare' => 'LIKE',
                ],
            ],
        ]);

        if ($matches === []) {
            // Fall back to an exact basename match (files uploaded to the
            // uploads root have no '/' before the name).
            $matches = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => 2,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => '_wp_attached_file',
                        'value'   => $filename,
                        'compare' => 'LIKE',
                    ],
                ],
            ]);
        }

        foreach ($matches as $id) {
            $file = get_post_meta((int) $id, '_wp_attached_file', true);
            if (is_string($file) && wp_basename($file) === $filename) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Sideload a remote file into the media library.
     *
     * @param array<string, mixed> $entry
     */
    private function sideload(string $url, array $entry): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            return null;
        }

        $fileArray = [
            'name'     => wp_basename((string) ($entry['filename'] ?? parse_url($url, PHP_URL_PATH) ?? 'file')),
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($fileArray, 0);

        if (is_wp_error($id)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return null;
        }

        if (!empty($entry['alt'])) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $entry['alt']));
        }
        if (!empty($entry['caption'])) {
            wp_update_post(['ID' => $id, 'post_excerpt' => sanitize_text_field((string) $entry['caption'])]);
        }

        return (int) $id;
    }
}
