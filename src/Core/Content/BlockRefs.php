<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots (see Exporter).

/**
 * The IDs inside post content: which block attributes hold attachment,
 * post, term or user IDs, collected on export and rewritten on import.
 * Only the attributes listed here are touched, never a block's other
 * `"id"` (a loop's or a form's), and content is re-serialized only when a
 * value changed, so content without references comes back byte for byte.
 *
 * Also the `wp-image-N` class and `data-id="N"` in the HTML itself (classic
 * content, and the markup image and gallery blocks save).
 *
 * The walk works on parsed block arrays ({@see self::collectBlocks()},
 * {@see self::rewriteBlocks()}), so it's unit-tested without WordPress;
 * {@see self::collect()} / {@see self::rewrite()} add `parse_blocks()`.
 *
 * @phpstan-type Refs array{attachments: list<int>, posts: list<int>, terms: list<int>, users: list<int>}
 */
final class BlockRefs
{
    /** Blocks whose `id` attribute is an attachment. */
    private const ATTACHMENT_ID = ['core/image', 'core/cover', 'core/file', 'core/video', 'core/audio'];

    /**
     * @return Refs
     */
    public static function collect(string $content): array
    {
        $refs = self::collectBlocks(self::parse($content));
        $refs['attachments'] = array_values(array_unique(array_merge($refs['attachments'], self::htmlAttachmentIds($content))));

        return $refs;
    }

    /**
     * @param list<array<string, mixed>> $blocks parse_blocks() output
     * @return Refs
     */
    public static function collectBlocks(array $blocks): array
    {
        $refs = ['attachments' => [], 'posts' => [], 'terms' => [], 'users' => []];
        foreach ($blocks as $block) {
            self::walk($block, $refs, null);
            $inner = self::collectBlocks(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : []);
            foreach ($refs as $kind => $ids) {
                $refs[$kind] = array_merge($ids, $inner[$kind]);
            }
        }
        foreach ($refs as $kind => $ids) {
            $refs[$kind] = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        }

        return $refs;
    }

    /** $content with its references mapped to this site's, and its URLs. */
    public static function rewrite(string $content, RefMap $map): string
    {
        if ($content === '') {
            return $content;
        }
        if (str_contains($content, '<!-- wp:')) {
            $changed = false;
            $blocks = self::rewriteBlocks(self::parse($content), $map, $changed);
            if ($changed && function_exists('serialize_blocks')) {
                $content = serialize_blocks($blocks);
            }
        }

        return $map->urls(self::rewriteHtml($content, $map));
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    public static function rewriteBlocks(array $blocks, RefMap $map, bool &$changed): array
    {
        foreach ($blocks as $i => $block) {
            $before = $block['attrs'] ?? [];
            $none = null;
            self::walk($block, $none, $map);
            if (($block['attrs'] ?? []) !== $before) {
                $changed = true;
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                $block['innerBlocks'] = self::rewriteBlocks($block['innerBlocks'], $map, $changed);
            }
            $blocks[$i] = $block;
        }

        return $blocks;
    }

    /** `wp-image-N` and `data-id="N"` in HTML, through the attachment map. */
    public static function rewriteHtml(string $html, RefMap $map): string
    {
        $swap = static function (array $m) use ($map): string {
            $local = $map->attachment((int) $m[2]);

            return $m[1] . (is_int($local) ? $local : $m[2]) . $m[3];
        };
        $html = (string) preg_replace_callback('/(wp-image-)(\d+)()/', $swap, $html);

        return (string) preg_replace_callback('/(data-id=["\'])(\d+)(["\'])/', $swap, $html);
    }

    /**
     * @return list<int>
     */
    private static function htmlAttachmentIds(string $html): array
    {
        preg_match_all('/(?:wp-image-|data-id=["\'])(\d+)/', $html, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * Collect ($refs) or rewrite ($map) one block's ID attributes.
     *
     * @param array<string, mixed>          $block
     * @param array<string, list<int>>|null $refs
     */
    private static function walk(array &$block, ?array &$refs, ?RefMap $map): void
    {
        $name = (string) ($block['blockName'] ?? '');
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        if ($name === '' || $attrs === []) {
            return;
        }

        if (in_array($name, self::ATTACHMENT_ID, true)) {
            self::one($attrs, 'id', 'attachments', $refs, $map);
        } elseif ($name === 'core/media-text') {
            self::one($attrs, 'mediaId', 'attachments', $refs, $map);
        } elseif ($name === 'core/gallery') {
            self::many($attrs, 'ids', 'attachments', $refs, $map);
        } elseif ($name === 'core/navigation-link' || $name === 'core/navigation-submenu') {
            $kind = match ($attrs['kind'] ?? '') {
                'taxonomy'  => 'terms',
                'post-type' => 'posts',
                default     => '',
            };
            if ($kind !== '') {
                self::one($attrs, 'id', $kind, $refs, $map);
            }
        } elseif ($name === 'core/navigation' || $name === 'core/block') {
            // A block navigation menu (`wp_navigation`) or a reusable block (`wp_block`), 1.7.
            self::one($attrs, 'ref', 'posts', $refs, $map);
        } elseif ($name === 'core/page-list') {
            self::one($attrs, 'rootPageID', 'posts', $refs, $map);
        } elseif ($name === 'core/latest-posts') {
            self::one($attrs, 'selectedAuthor', 'users', $refs, $map);
            if (is_array($attrs['categories'] ?? null)) {
                $categories = [];
                foreach ($attrs['categories'] as $category) {
                    if (is_array($category)) {
                        self::one($category, 'id', 'terms', $refs, $map);
                        if (!isset($category['id'])) {
                            continue; // its term isn't on this site
                        }
                    }
                    $categories[] = $category;
                }
                $attrs['categories'] = $categories;
            }
        } elseif ($name === 'core/query' && is_array($attrs['query'] ?? null)) {
            $query = $attrs['query'];
            foreach (['include', 'exclude', 'parents'] as $key) {
                self::many($query, $key, 'posts', $refs, $map);
            }
            self::byTaxonomy($query, 'taxQuery', $refs, $map);
            $author = $query['author'] ?? '';
            if (is_string($author) && preg_match('/^\d+(,\d+)*$/', $author)) {
                $ids = ['author' => array_map('intval', explode(',', $author))];
                self::many($ids, 'author', 'users', $refs, $map);
                $query['author'] = implode(',', $ids['author']);
            } else {
                self::one($query, 'author', 'users', $refs, $map);
            }
            $attrs['query'] = $query;
        } elseif ($name === 'taw/loop' && is_array($attrs['source'] ?? null)) {
            $source = $attrs['source'];
            self::many($source, 'include', 'posts', $refs, $map);
            self::many($source, 'exclude', 'posts', $refs, $map);
            self::byTaxonomy($source, 'terms', $refs, $map);
            self::one($source, 'author', 'users', $refs, $map);
            self::one($source, 'parent', 'terms', $refs, $map);
            $attrs['source'] = $source;
        }

        $block['attrs'] = $attrs;
    }

    /**
     * One ID at $a[$key]: collected, mapped, or removed when its record
     * isn't on this site.
     *
     * @param array<string, mixed>          $a
     * @param array<string, list<int>>|null $refs
     */
    private static function one(array &$a, string $key, string $kind, ?array &$refs, ?RefMap $map): void
    {
        if (!isset($a[$key]) || !is_numeric($a[$key]) || (int) $a[$key] <= 0) {
            return;
        }
        if ($refs !== null) {
            $refs[$kind][] = (int) $a[$key];
            return;
        }
        $local = self::lookup($map, $kind, (int) $a[$key]);
        if ($local === false) {
            unset($a[$key]);
        } elseif ($local !== null) {
            $a[$key] = $local;
        }
    }

    /**
     * A list of IDs at $a[$key].
     *
     * @param array<string, mixed>          $a
     * @param array<string, list<int>>|null $refs
     */
    private static function many(array &$a, string $key, string $kind, ?array &$refs, ?RefMap $map): void
    {
        if (!isset($a[$key]) || !is_array($a[$key])) {
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $a[$key]), static fn (int $id): bool => $id > 0));
        if ($refs !== null) {
            $refs[$kind] = array_merge($refs[$kind], $ids);
            return;
        }
        if ($map !== null && $ids !== []) {
            $a[$key] = RefMap::mapIds($ids, static fn (int $id) => self::lookup($map, $kind, $id));
        }
    }

    /**
     * Term ID lists by taxonomy at $a[$key] (`{"category": [3, 4]}`; a
     * value like `"current"` is left alone).
     *
     * @param array<string, mixed>          $a
     * @param array<string, list<int>>|null $refs
     */
    private static function byTaxonomy(array &$a, string $key, ?array &$refs, ?RefMap $map): void
    {
        if (!isset($a[$key]) || !is_array($a[$key])) {
            return;
        }
        $byTaxonomy = $a[$key];
        foreach (array_keys($byTaxonomy) as $taxonomy) {
            if (is_array($byTaxonomy[$taxonomy])) {
                self::many($byTaxonomy, (string) $taxonomy, 'terms', $refs, $map);
            }
        }
        $a[$key] = $byTaxonomy;
    }

    private static function lookup(?RefMap $map, string $kind, int $id): int|false|null
    {
        return match (true) {
            $map === null => null,
            $kind === 'attachments' => $map->attachment($id),
            $kind === 'posts' => $map->post($id),
            $kind === 'terms' => $map->term($id),
            $kind === 'users' => $map->user($id),
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parse(string $content): array
    {
        if (!str_contains($content, '<!-- wp:') || !function_exists('parse_blocks')) {
            return [];
        }

        return array_values(array_filter(parse_blocks($content), 'is_array'));
    }
}
