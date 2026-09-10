<?php

declare(strict_types=1);

namespace TAW\Core\Content;

use Composer\InstalledVersions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds a portable, human-reviewable snapshot of a TAW site's content —
 * posts / CPT entries and their `_taw_*` metabox values, `_taw_*` options,
 * terms, and every referenced media file — as a plain array ready to
 * `json_encode()`.
 *
 * What it deliberately never touches: users, revisions, comments,
 * transients, non-allowlisted core/plugin options, and
 * `nav_menu` / `nav_menu_item` (code-owned in TAW themes).
 *
 * Schema: see `resources/schema/content-interchange-1.0.json`.
 *
 * @phpstan-type Scope array{types?: list<string>, since?: string, posts?: list<int|string>, include_media?: bool}
 */
class Exporter
{
    public const SCHEMA_VERSION = '1.0';

    /**
     * Core (non-`_taw_`) options included in every export. `page_on_front`
     * and `page_for_posts` are resolved to slugs by {@see self::exportOptions()}.
     */
    public const CORE_OPTION_ALLOWLIST = [
        'blogname',
        'blogdescription',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
    ];

    /**
     * Post types never exported, regardless of scope — framework-internal
     * or code-owned content.
     */
    private const NEVER_EXPORT_POST_TYPES = [
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'taw_submission',
    ];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, true> attachment IDs referenced by exported content */
    private array $referencedAttachments = [];

    /**
     * @param Scope $scope
     * @return array<string, mixed>
     */
    public function snapshot(array $scope = []): array
    {
        $this->warnings = [];
        $this->referencedAttachments = [];

        $includeMedia = $scope['include_media'] ?? true;

        $posts = $this->exportPosts($scope);
        $terms = $this->exportTerms();
        $options = $this->exportOptions();

        $snapshot = [
            'meta' => [
                'schema'       => self::SCHEMA_VERSION,
                'generated_at' => gmdate('c'),
                'source'       => [
                    'url'              => home_url(),
                    'taw_core_version' => InstalledVersions::isInstalled('taw/core')
                        ? InstalledVersions::getPrettyVersion('taw/core')
                        : null,
                    'theme'            => get_stylesheet(),
                ],
                'registry_fingerprint' => RegistryFingerprint::current(),
            ],
            'options' => $options,
            'terms'   => $terms,
            'posts'   => $posts,
        ];

        if ($includeMedia) {
            $snapshot['media'] = $this->exportMedia();
        }

        return $snapshot;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /* -----------------------------------------------------------------
     * Posts
     * ----------------------------------------------------------------- */

    /**
     * @param Scope $scope
     * @return list<array<string, mixed>>
     */
    private function exportPosts(array $scope): array
    {
        $types = $this->postTypesToExport($scope['types'] ?? null);

        $args = [
            'post_type'        => $types,
            'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page'   => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ];

        if (!empty($scope['since'])) {
            $args['date_query'] = [['after' => (string) $scope['since'], 'inclusive' => true]];
        }

        if (!empty($scope['posts'])) {
            [$ids, $slugs] = $this->splitIdsAndSlugs($scope['posts']);
            if ($ids !== []) {
                $args['post__in'] = $ids;
            }
            if ($slugs !== []) {
                // WP_Query can't OR post__in with post_name__in cleanly; resolve slugs to IDs.
                $bySlug = get_posts(array_merge($args, ['post_name__in' => $slugs, 'post__in' => [], 'fields' => 'ids']));
                $args['post__in'] = array_values(array_unique(array_merge($ids, array_map('intval', $bySlug))));
            }
            if (empty($args['post__in'])) {
                return [];
            }
        }

        $records = [];
        foreach (get_posts($args) as $post) {
            $records[] = $this->buildPostRecord($post);
        }

        return $records;
    }

    /**
     * @param list<string>|null $requested
     * @return list<string>
     */
    private function postTypesToExport(?array $requested): array
    {
        $all = array_values(array_unique(array_merge(
            ['page', 'post'],
            array_keys(get_post_types(['public' => true], 'names'))
        )));

        $all = array_values(array_filter($all, fn (string $t): bool => !in_array($t, self::NEVER_EXPORT_POST_TYPES, true)));

        /**
         * Filter: the post types the content exporter includes.
         *
         * @param list<string> $all
         */
        $all = apply_filters('taw_content_export_post_types', $all);
        $all = array_map('strval', $all);

        if ($requested !== null && $requested !== []) {
            $requestedTypes = array_map('strval', $requested);
            $all = array_values(array_filter($all, static fn (string $t): bool => in_array($t, $requestedTypes, true)));
        }

        return $all;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPostRecord(\WP_Post $post): array
    {
        $fields = [];
        $meta = get_post_meta($post->ID);
        $meta = is_array($meta) ? $meta : [];

        foreach ($meta as $key => $rawValues) {
            if (!is_string($key) || !str_starts_with($key, '_taw_')) {
                continue;
            }
            $fieldId = substr($key, strlen('_taw_'));
            $raw = is_array($rawValues) ? ($rawValues[0] ?? '') : $rawValues;

            $config = Metabox::get_field_config($fieldId) ?? ['type' => 'text', 'id' => $fieldId];
            $decoded = FieldCodec::decode($config, $raw);
            $fields[$fieldId] = $decoded;

            foreach (FieldCodec::referencedAttachmentIds($config, $decoded) as $attId) {
                $this->referencedAttachments[$attId] = true;
            }
        }

        $thumbId = (int) get_post_thumbnail_id($post) ?: 0;
        $featured = null;
        if ($thumbId > 0) {
            $this->referencedAttachments[$thumbId] = true;
            $featured = $this->attachmentFilename($thumbId);
        }

        return [
            'type'           => $post->post_type,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'title'          => $post->post_title,
            'excerpt'        => $post->post_excerpt,
            'content'        => $post->post_content,
            'menu_order'     => (int) $post->menu_order,
            'date'           => $post->post_date_gmt,
            'parent'         => $post->post_parent ? (get_post($post->post_parent)->post_name ?? null) : null,
            'template'       => get_page_template_slug($post) ?: null,
            'terms'          => $this->postTerms($post),
            'featured_media' => $featured,
            'fields'         => $fields,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function postTerms(\WP_Post $post): array
    {
        $out = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            if ($taxonomy === 'nav_menu') {
                continue;
            }
            $terms = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'slugs']);
            if (is_wp_error($terms) || $terms === []) {
                continue;
            }
            $out[$taxonomy] = array_values(array_map('strval', $terms));
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     * Terms
     * ----------------------------------------------------------------- */

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function exportTerms(): array
    {
        $out = [];
        foreach (get_taxonomies(['public' => true], 'names') as $taxonomy) {
            if ($taxonomy === 'nav_menu') {
                continue;
            }

            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (is_wp_error($terms) || $terms === []) {
                continue;
            }

            $rows = [];
            foreach ($terms as $term) {
                $rows[] = [
                    'slug'        => $term->slug,
                    'name'        => $term->name,
                    'description' => $term->description,
                    'parent'      => $term->parent ? (get_term($term->parent)->slug ?? null) : null,
                    'meta'        => $this->termMeta($term->term_id),
                ];
            }
            $out[$taxonomy] = $rows;
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function termMeta(int $termId): array
    {
        $meta = get_term_meta($termId);
        if (!is_array($meta)) {
            return [];
        }
        $out = [];
        foreach ($meta as $key => $values) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }
            $out[(string) $key] = is_array($values) ? ($values[0] ?? '') : $values;
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     * Options
     * ----------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function exportOptions(): array
    {
        global $wpdb;

        $out = [];

        $optionRegistry = OptionsPage::getFieldRegistry();

        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_taw\_%'"
        );

        foreach ((array) $names as $name) {
            $name = (string) $name;
            $raw = get_option($name);
            $config = $optionRegistry[$name] ?? null;
            $out[$name] = $config !== null ? FieldCodec::decode($config, $raw) : $this->decodeUnknownOption($raw);
        }

        /** @var list<string> $allowlist */
        $allowlist = (array) apply_filters('taw_content_export_core_options', self::CORE_OPTION_ALLOWLIST);

        foreach ($allowlist as $name) {
            $name = (string) $name;
            if (in_array($name, ['page_on_front', 'page_for_posts'], true)) {
                $id = (int) get_option($name);
                $out[$name] = $id > 0 ? (get_post($id)->post_name ?? null) : null;
                continue;
            }
            $out[$name] = get_option($name);
        }

        return $out;
    }

    private function decodeUnknownOption(mixed $raw): mixed
    {
        if (is_string($raw) && $raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $raw;
    }

    /* -----------------------------------------------------------------
     * Media
     * ----------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    private function exportMedia(): array
    {
        $out = [];
        foreach (array_keys($this->referencedAttachments) as $attId) {
            $attId = (int) $attId;
            $post = get_post($attId);
            if (!$post || $post->post_type !== 'attachment') {
                $this->warnings[] = "Referenced attachment {$attId} no longer exists.";
                continue;
            }

            $filename = $this->attachmentFilename($attId);
            $out[] = [
                'id'       => $attId,
                'ref'      => $filename,
                'filename' => $filename,
                'url'      => wp_get_attachment_url($attId) ?: '',
                'alt'      => get_post_meta($attId, '_wp_attachment_image_alt', true) ?: '',
                'caption'  => $post->post_excerpt,
                'mime'     => $post->post_mime_type,
            ];
        }
        return $out;
    }

    private function attachmentFilename(int $attId): string
    {
        $file = get_post_meta($attId, '_wp_attached_file', true);
        if (is_string($file) && $file !== '') {
            return wp_basename($file);
        }
        $url = wp_get_attachment_url($attId);
        return is_string($url) ? wp_basename($url) : (string) $attId;
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    /**
     * @param list<int|string> $items
     * @return array{0: list<int>, 1: list<string>}
     */
    private function splitIdsAndSlugs(array $items): array
    {
        $ids = [];
        $slugs = [];
        foreach ($items as $item) {
            if (is_int($item) || ctype_digit((string) $item)) {
                $ids[] = (int) $item;
            } else {
                $slugs[] = (string) $item;
            }
        }
        return [$ids, $slugs];
    }
}
