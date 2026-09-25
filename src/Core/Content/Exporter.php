<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots, and the
// guard's `exit` silently kills the command (v1.25.1 fix). They are
// pure class definitions with no include-time side effects — like
// TAW\Helpers\Framework and TAW\CLI\WpLoader, which omit it too.

use Composer\InstalledVersions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

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
 * Schema: see `resources/schema/content-interchange-1.2.json`.
 *
 * @phpstan-type Scope array{types?: list<string>, since?: string, posts?: list<int|string>, include_media?: bool, all_media?: bool, include_drafts?: bool, include_users?: bool, include_user_passwords?: bool, include_comments?: bool, include_settings?: bool}
 */
class Exporter
{
    public const SCHEMA_VERSION = '1.2';

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
     * A *second*, opt-in allowlist for environment-ish settings a full
     * `--migrate` wants but a routine content sync must never touch. Only
     * exported when `$scope['include_settings']` is set, and the importer
     * gates them behind `--with-settings` as well. `sticky_posts` is
     * rendered as a list of post slugs. Filter: `taw_content_export_settings_options`.
     */
    public const SETTINGS_OPTION_ALLOWLIST = [
        'permalink_structure',
        'timezone_string',
        'gmt_offset',
        'date_format',
        'time_format',
        'start_of_week',
        'sticky_posts',
        'blog_public',
        'default_comment_status',
        'default_ping_status',
        'WPLANG',
    ];

    /** Options in an allowlist whose value is a post slug (or list of slugs) standing in for a post ID. */
    public const POST_SLUG_OPTIONS = ['page_on_front', 'page_for_posts'];
    public const POST_SLUG_LIST_OPTIONS = ['sticky_posts'];

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

    /** @var array<int, array{type: string, slug: string}> post ID => natural key of every exported post */
    private array $exportedPosts = [];

    /**
     * @param Scope $scope
     * @return array<string, mixed>
     */
    public function snapshot(array $scope = []): array
    {
        $this->warnings = [];
        $this->referencedAttachments = [];
        $this->exportedPosts = [];

        $includeMedia = $scope['include_media'] ?? true;

        $posts = $this->exportPosts($scope);
        $terms = $this->exportTerms();
        $options = $this->exportOptions($scope);

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

        if (!empty($scope['include_users'])) {
            $snapshot['users'] = $this->exportUsers(!empty($scope['include_user_passwords']));
        }

        if (!empty($scope['include_comments'])) {
            $snapshot['comments'] = $this->exportComments();
        }

        if ($includeMedia) {
            $snapshot['media'] = $this->exportMedia(!empty($scope['all_media']));
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

        // Drafts are excluded by default: a draft with an empty post_name
        // can't be keyed by (type, slug) and duplicates on every re-import.
        // `--include-drafts` re-adds draft + pending, and slug-less records
        // then carry a composite `match_key` the importer keys on instead.
        $statuses = empty($scope['include_drafts'])
            ? ['publish', 'private', 'future']
            : ['publish', 'private', 'future', 'draft', 'pending'];

        $args = [
            'post_type'        => $types,
            'post_status'      => $statuses,
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
            array_keys(get_post_types(['public' => true], 'names')),
            // A registered post type with a TAW Metabox attached is
            // human-curated content by definition — auto-include it even
            // when `public => false` (e.g. a Mass-schedule CPT), so sites
            // stop needing a manual `taw_content_export_post_types` filter.
            Metabox::postTypesWithMetabox()
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

        // Format 1.2 (ADR-0008): `_taw_` fields keep their bare id as the key,
        // fields with another prefix use their full meta key.
        $registered = Metabox::fieldsFor('post', (string) $post->post_type);
        $bareConfig = static fn (string $id): ?array => Metabox::get_field_config($id);
        $keyedBy = [];

        foreach ($meta as $key => $rawValues) {
            if (!is_string($key)) {
                continue;
            }
            $resolved = FieldKeys::forMetaKey($key, $registered, $bareConfig);
            if ($resolved === null) {
                continue;
            }
            $fieldKey = $resolved['key'];
            $config = $resolved['config'];

            // Another prefix's meta key spelling a `_taw_` field's bare id:
            // the `_taw_` field keeps the key (the importer resolves it the same way).
            if (isset($keyedBy[$fieldKey])) {
                $keepNew = ($config['prefix'] ?? '_taw_') === '_taw_';
                $skipped = $keepNew ? $keyedBy[$fieldKey] : $key;
                $this->warnings[] = "Post {$post->post_type}:{$post->post_name}: meta '{$skipped}' has the same snapshot key as '"
                    . ($keepNew ? $key : $keyedBy[$fieldKey]) . "' — '{$skipped}' left out. Rename one of the fields.";
                if (!$keepNew) {
                    continue;
                }
            }
            $keyedBy[$fieldKey] = $key;

            $raw = is_array($rawValues) ? ($rawValues[0] ?? '') : $rawValues;
            $decoded = FieldCodec::decode($config, $raw);
            $fields[$fieldKey] = $decoded;

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

        $this->exportedPosts[(int) $post->ID] = ['type' => (string) $post->post_type, 'slug' => (string) $post->post_name];

        $record = [
            'type'           => $post->post_type,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'title'          => $post->post_title,
            'excerpt'        => $post->post_excerpt,
            'content'        => $post->post_content,
            'menu_order'     => (int) $post->menu_order,
            'date'           => $post->post_date_gmt,
            'author'         => $this->authorRef((int) $post->post_author),
            'comment_status' => (string) $post->comment_status,
            'ping_status'    => (string) $post->ping_status,
            'parent'         => $post->post_parent ? (get_post($post->post_parent)->post_name ?? null) : null,
            'template'       => get_page_template_slug($post) ?: null,
            'terms'          => $this->postTerms($post),
            'featured_media' => $featured,
            'fields'         => $fields,
        ];

        // A slug-less post (draft / auto-draft) needs a stable composite key
        // so the importer doesn't recreate it on every run.
        if ((string) $post->post_name === '') {
            $record['match_key'] = sha1($post->post_type . '|' . $post->post_title . '|' . $post->post_date_gmt);
        }

        return $record;
    }

    /**
     * @return array{login: string, email: string}|null
     */
    private function authorRef(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $user = get_userdata($userId);
        if (!$user) {
            $this->warnings[] = "Post author user #{$userId} no longer exists — author omitted.";
            return null;
        }
        return ['login' => (string) $user->user_login, 'email' => (string) $user->user_email];
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

            // Term fieldsets (ADR-0008): a taxonomy with TAW fields gets a
            // `fields` map per term, keyed like post fields. Taxonomies
            // without any keep the 1.1 row shape exactly.
            $registered = Metabox::fieldsFor('term', (string) $taxonomy);

            $rows = [];
            foreach ($terms as $term) {
                $row = [
                    'slug'        => $term->slug,
                    'name'        => $term->name,
                    'description' => $term->description,
                    'parent'      => $term->parent ? (get_term($term->parent)->slug ?? null) : null,
                    'meta'        => array_diff_key($this->termMeta($term->term_id), $registered),
                ];
                if ($registered !== []) {
                    $row['fields'] = $this->termFields((int) $term->term_id, $registered);
                }
                $rows[] = $row;
            }
            $out[$taxonomy] = $rows;
        }
        return $out;
    }

    /**
     * A term's TAW field values, decoded and keyed like post fields.
     *
     * @param array<string, array<string, mixed>> $registered Metabox::fieldsFor('term', …)
     * @return array<string, mixed>
     */
    private function termFields(int $termId, array $registered): array
    {
        return $this->objectFields(get_term_meta($termId), $registered);
    }

    /**
     * An object's TAW field values from all its meta, decoded and keyed like
     * post fields; referenced attachments are collected for `media`.
     *
     * @param mixed                               $meta       get_{type}_meta($id) (all keys).
     * @param array<string, array<string, mixed>> $registered Metabox::fieldsFor(…)
     * @return array<string, mixed>
     */
    private function objectFields(mixed $meta, array $registered): array
    {
        $fields = [];
        foreach ((is_array($meta) ? $meta : []) as $key => $values) {
            if (!is_string($key) || !isset($registered[$key])) {
                continue;
            }
            $config = $registered[$key];
            $decoded = FieldCodec::decode($config, is_array($values) ? ($values[0] ?? '') : $values);
            $fields[FieldKeys::keyOf($config)] = $decoded;

            foreach (FieldCodec::referencedAttachmentIds($config, $decoded) as $attId) {
                $this->referencedAttachments[$attId] = true;
            }
        }

        return $fields;
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
     * @param Scope $scope
     * @return array<string, mixed>
     */
    private function exportOptions(array $scope): array
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

        if (!empty($scope['include_settings'])) {
            /** @var list<string> $settings */
            $settings = (array) apply_filters('taw_content_export_settings_options', self::SETTINGS_OPTION_ALLOWLIST);
            $allowlist = array_values(array_unique(array_merge($allowlist, $settings)));
        }

        foreach ($allowlist as $name) {
            $name = (string) $name;

            if (in_array($name, self::POST_SLUG_OPTIONS, true)) {
                $id = (int) get_option($name);
                $out[$name] = $id > 0 ? (get_post($id)->post_name ?? null) : null;
                continue;
            }

            if (in_array($name, self::POST_SLUG_LIST_OPTIONS, true)) {
                $ids = (array) get_option($name, []);
                $out[$name] = array_values(array_filter(array_map(
                    static fn ($id): ?string => (int) $id > 0 ? (get_post((int) $id)->post_name ?? null) : null,
                    $ids
                )));
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
    private function exportMedia(bool $allMedia = false): array
    {
        $ids = $this->referencedAttachments;

        if ($allMedia) {
            // Also carry attachments referenced only by widgets / options /
            // orphaned uploads, so a --migrate export is a complete snapshot.
            $everyAttachment = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            foreach ($everyAttachment as $id) {
                $ids[(int) $id] = true;
            }
        }

        $out = [];
        foreach (array_keys($ids) as $attId) {
            $attId = (int) $attId;
            $post = get_post($attId);
            if (!$post || $post->post_type !== 'attachment') {
                if (isset($this->referencedAttachments[$attId])) {
                    $this->warnings[] = "Referenced attachment {$attId} no longer exists.";
                }
                continue;
            }

            $filename = $this->attachmentFilename($attId);
            $out[] = [
                'id'          => $attId,
                'ref'         => $filename,
                'filename'    => $filename,
                'url'         => wp_get_attachment_url($attId) ?: '',
                'title'       => (string) $post->post_title,
                'description' => (string) $post->post_content,
                'alt'         => get_post_meta($attId, '_wp_attachment_image_alt', true) ?: '',
                'caption'     => $post->post_excerpt,
                'mime'        => $post->post_mime_type,
            ];
        }
        return $out;
    }

    private function attachmentFilename(int $attId): string
    {
        return MediaResolver::attachmentFilename($attId);
    }

    /* -----------------------------------------------------------------
     * Users (opt-in — $scope['include_users'])
     * ----------------------------------------------------------------- */

    private const USER_META_KEYS = ['first_name', 'last_name', 'description', 'nickname', 'locale'];

    /**
     * @return list<array<string, mixed>>
     */
    private function exportUsers(bool $includePasswords): array
    {
        // User fieldsets (ADR-0008): `fields`, keyed like post fields, only
        // when some fieldset targets users.
        $registered = Metabox::fieldsFor('user');

        $out = [];
        foreach (get_users(['fields' => 'all']) as $user) {
            $meta = [];
            foreach (self::USER_META_KEYS as $key) {
                $meta[$key] = (string) get_user_meta($user->ID, $key, true);
            }

            $record = [
                'login'           => (string) $user->user_login,
                'email'           => (string) $user->user_email,
                'display_name'    => (string) $user->display_name,
                'roles'           => array_values(array_map('strval', (array) $user->roles)),
                'meta'            => $meta,
                'user_registered' => (string) $user->user_registered,
            ];
            if ($registered !== []) {
                $record['fields'] = $this->objectFields(get_user_meta($user->ID), $registered);
            }

            if ($includePasswords) {
                // WP password hashes (phpass, or bcrypt on newer cores) are
                // self-contained and verify on any install — still gated
                // behind the explicit second flag.
                $record['password_hash'] = (string) $user->user_pass;
            }

            $out[] = $record;
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     * Comments (opt-in — $scope['include_comments'])
     * ----------------------------------------------------------------- */

    /**
     * @return list<array<string, mixed>>
     */
    private function exportComments(): array
    {
        $postIds = array_keys($this->exportedPosts);
        if ($postIds === []) {
            return [];
        }

        $out = [];
        foreach (get_comments(['post__in' => $postIds, 'status' => 'all', 'orderby' => 'comment_ID', 'order' => 'ASC']) as $comment) {
            $postId = (int) $comment->comment_post_ID;
            $postRef = $this->exportedPosts[$postId]['slug'] ?? '';
            if ($postRef === '') {
                continue;
            }

            $out[] = [
                'ref'          => (string) $comment->comment_ID,
                'post_ref'     => $postRef,
                'author_name'  => (string) $comment->comment_author,
                'author_email' => (string) $comment->comment_author_email,
                'author_url'   => (string) $comment->comment_author_url,
                'content'      => (string) $comment->comment_content,
                'date_gmt'     => (string) $comment->comment_date_gmt,
                'approved'     => (string) $comment->comment_approved,
                'type'         => (string) ($comment->comment_type ?: 'comment'),
                'parent_ref'   => (int) $comment->comment_parent > 0 ? (string) $comment->comment_parent : null,
            ];
        }
        return $out;
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
