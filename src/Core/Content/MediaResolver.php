<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots, and the
// guard's `exit` silently kills the command (v1.25.1 fix). They are
// pure class definitions with no include-time side effects — like
// TAW\Helpers\Framework and TAW\CLI\WpLoader, which omit it too.

/**
 * Resolves the `media[]` section of a snapshot against the target site.
 *
 * Matching is by the file's **path under uploads** (`2024/05/photo.jpg`),
 * then its **filename**, never by numeric ID (IDs are meaningless across
 * environments). For each media entry: find an existing attachment
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
    /** Tries per download before the file counts as failed. */
    public const DOWNLOAD_ATTEMPTS = 3;

    /** @var array<int, int> old (source) attachment id => new (target) attachment id */
    private array $idMap = [];

    /** @var list<string> */
    private array $warnings = [];

    private int $sideloadedCount = 0;

    /**
     * How each entry resolved: `matched` (an attachment here), `sideloaded`,
     * `would-sideload` (a dry run), `missing` (not here, no URL) or `failed`
     * (the download failed).
     *
     * @var list<array{id: int, filename: string, path: string, url: string, status: string, local: int, entry: array<string, mixed>}>
     */
    private array $outcomes = [];

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

            // The path under uploads first (`2024/05/photo.jpg`): two files
            // can share a name in different month folders.
            $url = (string) ($entry['url'] ?? '');
            $path = self::uploadsPath($url);
            $outcome = ['id' => $sourceId, 'filename' => $filename, 'path' => $path !== '' ? $path : $filename, 'url' => $url, 'status' => '', 'local' => 0, 'entry' => $entry];
            // A file an earlier import downloaded first (it may have been
            // renamed on the way: `photo-1.jpg`), then the path, then the name.
            $existing = ($url !== '' ? self::findBySource($url) : null)
                ?? ($path !== '' ? self::findByPath($path) : null)
                ?? self::findByFilename($filename, array_values($this->idMap), $url);
            if ($existing !== null) {
                if ($sourceId > 0) {
                    $this->idMap[$sourceId] = $existing;
                }
                $this->outcomes[] = ['status' => 'matched', 'local' => $existing] + $outcome;
                continue;
            }

            if ($url === '') {
                $this->warnings[] = "Media '{$filename}' is not on the target site and has no URL to sideload from — references to it are cleared.";
                $this->outcomes[] = ['status' => 'missing'] + $outcome;
                continue;
            }

            if (!$sideload) {
                $this->outcomes[] = ['status' => 'would-sideload'] + $outcome;
                continue;
            }

            $error = '';
            $newId = $this->sideload($url, $entry, $error);
            if ($newId === null) {
                $this->warnings[] = "Failed to sideload media '{$filename}' from {$url} ({$error}) — references to it are cleared.";
                $this->outcomes[] = ['status' => 'failed'] + $outcome;
                continue;
            }

            $this->sideloadedCount++;
            if ($sourceId > 0) {
                $this->idMap[$sourceId] = $newId;
            }
            $this->outcomes[] = ['status' => 'sideloaded', 'local' => $newId] + $outcome;
        }
    }

    /** @return array<int, int> */
    public function idMap(): array
    {
        return $this->idMap;
    }

    /**
     * @return list<array{id: int, filename: string, path: string, url: string, status: string, local: int, entry: array<string, mixed>}>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * Source IDs of entries this site doesn't have and won't get (no URL,
     * or the download failed): references to them are cleared.
     *
     * @return list<int>
     */
    public function missingIds(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $o): int => in_array($o['status'], ['missing', 'failed'], true) ? $o['id'] : 0,
            $this->outcomes
        )));
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
     * Superseded by {@see BlockRefs::rewrite()}, which the importer uses: this
     * regex rewrites every block's `"id":N`, not only the attributes that
     * hold attachment IDs. Kept for callers outside the importer.
     *
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
     * The basename of an attachment's file — the stable, portable key used
     * throughout the interchange format for `featured_media` and media
     * references. Falls back to the URL basename, then the ID.
     */
    public static function attachmentFilename(int $attachmentId): string
    {
        $file = get_post_meta($attachmentId, '_wp_attached_file', true);
        if (is_string($file) && $file !== '') {
            return wp_basename($file);
        }
        $url = wp_get_attachment_url($attachmentId);
        return is_string($url) && $url !== '' ? wp_basename($url) : (string) $attachmentId;
    }

    /** A media URL's path under the uploads folder, or '' when it isn't there. */
    public static function uploadsPath(string $url): string
    {
        return preg_match('#/wp-content/uploads/(.+)$#', (string) strtok($url, '?#'), $m) ? $m[1] : '';
    }

    /** Meta key holding the source URL of an attachment an import downloaded. */
    public const SOURCE_META = '_taw_interchange_source';

    /** The attachment an earlier import downloaded from $url. */
    public static function findBySource(string $url): ?int
    {
        $matches = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => self::SOURCE_META, 'value' => $url]],
        ]);

        return isset($matches[0]) ? (int) $matches[0] : null;
    }

    /** The attachment whose `_wp_attached_file` is exactly $path. */
    public static function findByPath(string $path): ?int
    {
        $matches = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_wp_attached_file', 'value' => $path]],
        ]);

        return isset($matches[0]) ? (int) $matches[0] : null;
    }

    /**
     * Locate an existing attachment whose file is named $filename
     * (basename match against `_wp_attached_file`). Never one in $taken
     * (already matched to another entry of this import), nor, given the
     * entry's $url, one an import downloaded from a different URL: two
     * `hero.jpg` in different month folders are two files.
     *
     * @param list<int> $taken
     */
    public static function findByFilename(string $filename, array $taken = [], string $url = ''): ?int
    {
        $filename = wp_basename($filename);

        $matches = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 20,
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
                'posts_per_page' => 20,
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
            if (in_array((int) $id, $taken, true)) {
                continue;
            }
            $source = $url !== '' ? (string) get_post_meta((int) $id, self::SOURCE_META, true) : '';
            if ($source !== '' && $source !== $url) {
                continue;
            }
            $file = get_post_meta((int) $id, '_wp_attached_file', true);
            if (is_string($file) && wp_basename($file) === $filename) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Download a remote file to a temporary path, trying again when the
     * transfer fails: some hosts drop large files mid-way (cURL 56), and a
     * dropped file clears every reference to it.
     *
     * @return string|\WP_Error the temporary path, or the last attempt's error
     */
    public static function download(string $url, int $attempts = self::DOWNLOAD_ATTEMPTS): string|\WP_Error
    {
        $tmp = new \WP_Error('http_request_failed', 'not attempted');
        for ($i = 0; $i < max(1, $attempts); $i++) {
            $tmp = download_url($url);
            if (!is_wp_error($tmp)) {
                return $tmp;
            }
        }

        return $tmp;
    }

    /**
     * Sideload a remote file into the media library.
     *
     * @param array<string, mixed> $entry
     * @param string               $error why it failed, when it did
     */
    private function sideload(string $url, array $entry, string &$error = ''): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // A `-scaled` file is WordPress's copy of a large upload; uploaded
        // under that name it'd become `photo-scaled-1.jpg`. The original sits
        // beside it: download that, and WordPress makes the same copy here.
        $name = wp_basename((string) ($entry['filename'] ?? parse_url($url, PHP_URL_PATH) ?? 'file'));
        $original = (string) preg_replace('/-scaled(\.[a-z0-9]+)$/i', '$1', $url);
        $tmp = $original !== $url ? self::download($original) : null;
        if ($tmp !== null && !is_wp_error($tmp)) {
            $name = (string) preg_replace('/-scaled(\.[a-z0-9]+)$/i', '$1', $name);
        } else {
            $tmp = self::download($url);
        }
        if (is_wp_error($tmp)) {
            $error = $tmp->get_error_message();
            return null;
        }

        $fileArray = [
            'name'     => $name,
            'tmp_name' => $tmp,
        ];

        $postData = [];
        if (isset($entry['title']) && (string) $entry['title'] !== '') {
            $postData['post_title'] = sanitize_text_field((string) $entry['title']);
        }
        if (isset($entry['description']) && (string) $entry['description'] !== '') {
            $postData['post_content'] = wp_kses_post((string) $entry['description']);
        }

        // Keep the source's month folder (`2024/05/hero.jpg` stays there): a
        // second `hero.jpg` from another month doesn't become `hero-1.jpg`,
        // and an import back the other way finds each file by its path.
        $folder = dirname(self::uploadsPath($url));
        $keepFolder = static function (array $dirs) use ($folder): array {
            $dirs['subdir'] = '/' . $folder;
            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url'] = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        };
        $monthly = preg_match('#^\d{4}/\d{2}$#', $folder) === 1;
        if ($monthly) {
            add_filter('upload_dir', $keepFolder);
        }
        try {
            $id = media_handle_sideload($fileArray, 0, null, $postData);
        } finally {
            if ($monthly) {
                remove_filter('upload_dir', $keepFolder);
            }
        }

        if (is_wp_error($id)) {
            $error = $id->get_error_message();
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return null;
        }

        update_post_meta($id, self::SOURCE_META, esc_url_raw($url));
        if (!empty($entry['alt'])) {
            update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $entry['alt']));
        }
        if (!empty($entry['caption'])) {
            // A caption can carry markup (a link, emphasis): keep it as an editor would.
            wp_update_post(['ID' => $id, 'post_excerpt' => wp_kses_post((string) $entry['caption'])]);
        }

        return (int) $id;
    }
}
