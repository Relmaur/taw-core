<?php

declare(strict_types=1);

namespace TAW\Core\Metabox;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exposes all TAW Metabox content to SEO plugin analysis engines.
 *
 * Yoast SEO and SmartCrawl only analyse post_content by default. This class
 * appends every text-bearing or media custom field — including repeaters — to
 * the content string each plugin receives, emitting the HTML structures each
 * analyser actually inspects:
 *
 *   text / textarea   → plain text  (word count, keyphrase density)
 *   wysiwyg           → raw HTML    (preserves <h2>, <a>, <img> inside the editor)
 *   url               → <a href>    (internal / external link checks)
 *   image             → <img>       (image presence + alt-text keyphrase check)
 *   files             → <img> × N   (one per image attachment in the file list)
 *   post_select       → <a href> × N (internal link checks, per selected post)
 *   repeater          → recurse     (all of the above, across every row)
 */
class SeoContentIntegration
{
    private const HANDLED_TYPES = [
        'text', 'textarea', 'wysiwyg',
        'url', 'image', 'files', 'post_select',
        'repeater',
    ];

    public function __construct()
    {
        // Yoast SEO
        add_filter('wpseo_pre_analysis_post_content', [$this, 'appendMetaContent'], 10, 2);

        // SmartCrawl (WPMU Dev) — filter name varies by version; hook both
        add_filter('smartcrawl_analysis_post_content', [$this, 'appendMetaContent'], 10, 2);
        add_filter('smartcrawl_post_content',          [$this, 'appendMetaContent'], 10, 2);
    }

    /**
     * Main filter callback: appends all TAW meta content to the analysis string.
     *
     * @param string              $content    Content string the SEO plugin will analyse.
     * @param \WP_Post|int|null   $post_or_id Post object or ID from the plugin (may be absent).
     */
    public function appendMetaContent(string $content, mixed $post_or_id = null): string
    {
        $post = $this->resolvePost($post_or_id);
        if (!$post instanceof \WP_Post) {
            return $content;
        }

        $chunks = [];

        foreach (Metabox::getFieldRegistry() as $field_id => $field) {
            $type = $field['type'] ?? 'text';

            if (!in_array($type, self::HANDLED_TYPES, true)) {
                continue;
            }

            $meta_key  = $field['prefix'] . $field_id;
            $raw_value = get_post_meta($post->ID, $meta_key, true);

            if ($raw_value === '' || $raw_value === null || $raw_value === false) {
                continue;
            }

            if ($type === 'repeater') {
                $rows = is_string($raw_value) ? (json_decode($raw_value, true) ?: []) : [];
                if ($rows) {
                    $html = $this->repeaterRowsToHtml($rows, $field['fields'] ?? []);
                    if ($html !== '') {
                        $chunks[] = $html;
                    }
                }
                continue;
            }

            $html = $this->fieldToHtml($type, $raw_value, $field);
            if ($html !== '') {
                $chunks[] = $html;
            }
        }

        return $chunks ? $content . ' ' . implode(' ', $chunks) : $content;
    }

    // ── Field → HTML converters ──────────────────────────────────────────────

    private function fieldToHtml(string $type, mixed $value, array $field): string
    {
        return match ($type) {
            'text', 'textarea' => esc_html((string) $value),
            // Wysiwyg is already wp_kses_post-sanitised on save; pass raw HTML so
            // Yoast can see <h2>, <a>, and any inline <img> tags.
            'wysiwyg'          => (string) $value,
            'url'              => $this->urlToLink((string) $value),
            'image'            => $this->attachmentToImg((int) $value),
            'files'            => $this->filesToImgs((string) $value),
            'post_select'      => $this->postSelectToLinks((string) $value, $field),
            default            => '',
        };
    }

    private function urlToLink(string $url): string
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        return '<a href="' . esc_url($url) . '">' . esc_html($url) . '</a>';
    }

    private function attachmentToImg(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        return wp_get_attachment_image($id, 'full') ?: '';
    }

    private function filesToImgs(string $raw): string
    {
        $ids = json_decode($raw, true);
        if (!is_array($ids)) {
            return '';
        }

        $imgs = [];
        foreach ($ids as $id) {
            $id = absint($id);
            if ($id && wp_attachment_is_image($id)) {
                $img = wp_get_attachment_image($id, 'full');
                if ($img) {
                    $imgs[] = $img;
                }
            }
        }

        return implode(' ', $imgs);
    }

    private function postSelectToLinks(string $raw, array $field): string
    {
        $multiple = !empty($field['multiple']);

        if ($multiple) {
            $decoded = json_decode($raw, true);
            $ids     = is_array($decoded) ? array_filter(array_map('absint', $decoded)) : [];
        } else {
            $id  = absint($raw);
            $ids = $id ? [$id] : [];
        }

        $links = [];
        foreach ($ids as $id) {
            $linked_post = get_post($id);
            if (!$linked_post) {
                continue;
            }
            $links[] = '<a href="' . esc_url(get_permalink($id)) . '">'
                     . esc_html($linked_post->post_title)
                     . '</a>';
        }

        return implode(' ', $links);
    }

    // ── Repeater recursion ───────────────────────────────────────────────────

    /**
     * Converts all rows of a (possibly nested) repeater into an HTML string.
     *
     * @param array[] $rows       Decoded rows.
     * @param array[] $sub_fields Sub-field definitions for this repeater level.
     */
    private function repeaterRowsToHtml(array $rows, array $sub_fields): string
    {
        $chunks = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($sub_fields as $sub) {
                $type = $sub['type'] ?? 'text';

                if (!in_array($type, self::HANDLED_TYPES, true)) {
                    continue;
                }

                $val = $row[$sub['id']] ?? '';
                if ($val === '' || $val === null || $val === false) {
                    continue;
                }

                if ($type === 'repeater') {
                    // Nested repeater rows are stored as a decoded PHP array by
                    // sanitize_repeater() to avoid double-JSON-encoding.
                    $nested = is_string($val) ? (json_decode($val, true) ?: []) : $val;
                    if (is_array($nested) && $nested) {
                        $html = $this->repeaterRowsToHtml($nested, $sub['fields'] ?? []);
                        if ($html !== '') {
                            $chunks[] = $html;
                        }
                    }
                    continue;
                }

                $html = $this->fieldToHtml($type, $val, $sub);
                if ($html !== '') {
                    $chunks[] = $html;
                }
            }
        }

        return implode(' ', $chunks);
    }

    // ── Utilities ────────────────────────────────────────────────────────────

    private function resolvePost(mixed $post_or_id): mixed
    {
        if ($post_or_id instanceof \WP_Post) {
            return $post_or_id;
        }

        if (is_int($post_or_id) && $post_or_id > 0) {
            return get_post($post_or_id);
        }

        // Some plugins pass no post argument — fall back to the global
        global $post;
        return $post;
    }
}
