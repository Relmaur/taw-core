<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots, and the
// guard's `exit` silently kills the command (v1.25.1 fix). They are
// pure class definitions with no include-time side effects — like
// TAW\Helpers\Framework and TAW\CLI\WpLoader, which omit it too.

use TAW\Core\Metabox\Metabox;

/**
 * Consumes a content snapshot ({@see Exporter} output) or a change-set
 * ({@see ChangeSet} output) and applies it to the current site — with a
 * **mandatory dry-run first**: {@see self::plan()} produces a field-level
 * diff and writes nothing. {@see self::apply()} is the only method that
 * touches the database, and it writes a full rollback snapshot before it
 * does.
 *
 * Records are matched by natural key — posts by `(type, slug)`, options by
 * key, terms by `(taxonomy, slug)` — never by numeric ID. Meta is written
 * through {@see Metabox::writeMeta()}, the same sanitize + `wp_slash` path
 * an admin metabox save uses.
 *
 * @phpstan-type Operation array{op?: string, target: array<string, mixed>, post?: array<string, mixed>, fields?: array<string, mixed>}
 */
class Importer
{
    public const POLICIES = ['update', 'create', 'skip'];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Normalize either input shape into a flat operations list.
     *
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public static function operationsFrom(array $input): array
    {
        if (isset($input['taw_changeset'])) {
            $ops = $input['operations'] ?? [];
            return is_array($ops) ? array_values(array_filter($ops, 'is_array')) : [];
        }

        // A full snapshot — every record becomes an upsert operation.
        $ops = [];

        foreach ((is_array($input['posts'] ?? null) ? $input['posts'] : []) as $post) {
            if (!is_array($post)) {
                continue;
            }
            $ops[] = [
                'op'     => 'update',
                'target' => ['kind' => 'post', 'type' => $post['type'] ?? null, 'slug' => $post['slug'] ?? null],
                'post'   => $post,
            ];
        }

        foreach ((is_array($input['options'] ?? null) ? $input['options'] : []) as $key => $value) {
            $ops[] = ['op' => 'update', 'target' => ['kind' => 'option', 'key' => (string) $key], 'fields' => ['value' => $value]];
        }

        foreach ((is_array($input['terms'] ?? null) ? $input['terms'] : []) as $taxonomy => $rows) {
            foreach ((is_array($rows) ? $rows : []) as $row) {
                if (is_array($row) && isset($row['slug'])) {
                    $ops[] = [
                        'op'     => 'update',
                        'target' => ['kind' => 'term', 'type' => (string) $taxonomy, 'slug' => $row['slug']],
                        'fields' => $row,
                    ];
                }
            }
        }

        return $ops;
    }

    /**
     * Dry run — a per-record, field-level diff. **Writes nothing.**
     *
     * @param array<string, mixed> $input
     * @return array{registry_drift: list<string>, records: list<array<string, mixed>>, warnings: list<string>}
     */
    public function plan(array $input): array
    {
        $this->warnings = [];

        $records = [];
        foreach (self::operationsFrom($input) as $op) {
            $kind = $op['target']['kind'] ?? '';
            $records[] = match ($kind) {
                'post'   => $this->planPost($op),
                'option' => $this->planOption($op),
                'term'   => $this->planTerm($op),
                default  => ['kind' => $kind, 'op' => 'skip', 'note' => "Unknown target kind '{$kind}'."],
            };
        }

        return [
            'registry_drift' => $this->registryDrift($input),
            'records'        => $records,
            'warnings'       => $this->warnings,
        ];
    }

    /**
     * Apply the input. Writes a rollback snapshot first (unless
     * `$options['rollback'] === false`). Only call after a reviewed
     * {@see self::plan()} / an explicit `--yes` / the admin confirm.
     *
     * @param array<string, mixed> $input
     * @param array{policy?: string, rollback?: bool} $options
     * @return array<string, mixed>
     */
    public function apply(array $input, array $options = []): array
    {
        $this->warnings = [];
        $policy = $options['policy'] ?? 'update';
        if (!in_array($policy, self::POLICIES, true)) {
            $policy = 'update';
        }

        $report = [
            'created' => [], 'updated' => [], 'skipped' => [], 'deleted' => [],
            'media_sideloaded' => 0, 'warnings' => [], 'rollback_path' => null,
        ];

        if (($options['rollback'] ?? true) !== false) {
            $report['rollback_path'] = $this->writeRollbackSnapshot();
        }

        // Records the dry-run diff shows as already matching are skipped —
        // so a clean export → import round-trip is a genuine no-op and
        // re-running an import doesn't churn post_modified dates. Computed
        // before the run mutates anything (it also resets $this->warnings,
        // so it must come before the media step below).
        $unchanged = $this->unchangedRecordKeys($input);
        $this->warnings = [];

        // Media first — the ID map is needed to rewrite references in posts/fields.
        $media = is_array($input['media'] ?? null) ? $input['media'] : [];
        $resolver = new MediaResolver();
        $resolver->build($media, true);
        $idMap = $resolver->idMap();
        $report['media_sideloaded'] = $resolver->sideloadedCount();
        $this->warnings = array_merge($this->warnings, $resolver->warnings());

        $report['registry_drift'] = $this->registryDrift($input);

        foreach (self::operationsFrom($input) as $op) {
            $kind = $op['target']['kind'] ?? '';

            if (($op['op'] ?? 'update') === 'update' && isset($unchanged[$this->recordKey($op)])) {
                $report['skipped'][] = $this->recordKey($op);
                continue;
            }

            $result = match ($kind) {
                'post'   => $this->applyPost($op, $policy, $idMap),
                'option' => $this->applyOption($op, $policy),
                'term'   => $this->applyTerm($op, $policy),
                default  => ['bucket' => 'skipped', 'label' => "unknown:{$kind}"],
            };
            $report[$result['bucket']][] = $result['label'];
        }

        $report['warnings'] = $this->warnings;

        return $report;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Stable identity for an operation / plan record — used to line up the
     * dry-run diff with the apply loop.
     *
     * @param array<string, mixed> $opOrRecord
     */
    private function recordKey(array $opOrRecord): string
    {
        $t = is_array($opOrRecord['target'] ?? null) ? $opOrRecord['target'] : $opOrRecord;
        $kind = (string) ($t['kind'] ?? '');

        if ($kind === 'option') {
            return 'option:' . (string) ($t['key'] ?? '');
        }

        return $kind . ':' . (string) ($t['type'] ?? '') . ':' . (string) ($t['slug'] ?? '');
    }

    /**
     * The set of record keys the dry run reports as already matching the
     * site — `[key => true]`.
     *
     * @param array<string, mixed> $input
     * @return array<string, true>
     */
    private function unchangedRecordKeys(array $input): array
    {
        $keys = [];
        foreach ($this->plan($input)['records'] as $record) {
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            if ($changes === [] && ($record['op'] ?? '') !== 'would-delete') {
                $keys[$this->recordKey($record)] = true;
            }
        }
        return $keys;
    }

    /* -----------------------------------------------------------------
     * Plan (read-only)
     * ----------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function planPost(array $op): array
    {
        $type = (string) ($op['target']['type'] ?? '');
        $slug = (string) ($op['target']['slug'] ?? '');
        $incoming = is_array($op['post'] ?? null) ? $op['post'] : [];
        $explicitOp = $op['op'] ?? 'update';

        $existing = $this->findPost($type, $slug);

        if ($explicitOp === 'delete') {
            return [
                'kind' => 'post', 'type' => $type, 'slug' => $slug,
                'op' => $existing ? 'would-delete' : 'skip',
            ];
        }

        $changes = [];

        foreach (['title', 'excerpt', 'content', 'status', 'menu_order', 'template', 'parent'] as $prop) {
            if (!array_key_exists($prop, $incoming)) {
                continue;
            }
            $new = $incoming[$prop];
            $old = $existing ? $this->currentPostProp($existing, $prop) : null;
            if (!$existing) {
                $changes[$prop] = ['status' => 'new', 'new' => $new];
            } elseif ((string) $old !== (string) $new) {
                $changes[$prop] = ['status' => 'changed', 'old' => $old, 'new' => $new];
            }
        }

        // featured_media — the exporter renders it as a filename; compare
        // against the current thumbnail's filename, not its numeric ID.
        if (array_key_exists('featured_media', $incoming)) {
            $newRef = $incoming['featured_media'];
            $currentThumb = $existing ? (int) get_post_thumbnail_id($existing) : 0;
            $currentRef = $currentThumb > 0 ? MediaResolver::attachmentFilename($currentThumb) : null;
            if ((string) $currentRef !== (string) $newRef) {
                $changes['featured_media'] = ($existing && $currentRef !== null)
                    ? ['status' => 'changed', 'old' => $currentRef, 'new' => $newRef]
                    : ['status' => 'new', 'new' => $newRef];
            }
        }

        foreach (is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [] as $fieldId => $newVal) {
            $config = Metabox::get_field_config((string) $fieldId) ?? ['type' => 'text', 'id' => $fieldId];

            // Normalize BOTH sides through the same decode path so that
            // empty ↔ empty, "1" ↔ true, "[…]" ↔ [...], "42" ↔ 42 all
            // compare equal — a clean export→import round-trip must be a
            // no-op (see Bug B).
            $oldDecoded = FieldCodec::decode(
                $config,
                $existing ? get_post_meta($existing->ID, '_taw_' . $fieldId, true) : ''
            );
            $newDecoded = FieldCodec::decode($config, $newVal);

            if (self::valueKey($oldDecoded) === self::valueKey($newDecoded)) {
                continue;
            }

            $changes['fields.' . $fieldId] = ($existing && !self::isEmptyValue($oldDecoded))
                ? ['status' => 'changed', 'old' => $oldDecoded, 'new' => $newDecoded]
                : ['status' => 'new', 'new' => $newDecoded];
        }

        return [
            'kind' => 'post', 'type' => $type, 'slug' => $slug,
            'op' => $existing ? 'update' : 'create',
            'changes' => $changes,
        ];
    }

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function planOption(array $op): array
    {
        $key = (string) ($op['target']['key'] ?? '');
        $incoming = $op['fields']['value'] ?? null;
        $currentRaw = get_option($key, null);
        $exists = $currentRaw !== null;

        if ($exists && $this->optionCompareKey($key, $currentRaw) === $this->optionCompareKey($key, $incoming)) {
            return ['kind' => 'option', 'key' => $key, 'op' => 'update', 'changes' => []];
        }

        return [
            'kind'    => 'option',
            'key'     => $key,
            'op'      => $exists ? 'update' : 'create',
            'changes' => ['value' => ['status' => $exists ? 'changed' : 'new', 'old' => $currentRaw, 'new' => $incoming]],
        ];
    }

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function planTerm(array $op): array
    {
        $taxonomy = (string) ($op['target']['type'] ?? '');
        $slug = (string) ($op['target']['slug'] ?? '');
        $existing = get_term_by('slug', $slug, $taxonomy);
        $fields = is_array($op['fields'] ?? null) ? $op['fields'] : [];

        $changes = [];
        foreach (['name', 'description'] as $prop) {
            if (!array_key_exists($prop, $fields)) {
                continue;
            }
            if (!$existing) {
                $changes[$prop] = ['status' => 'new', 'new' => $fields[$prop]];
            } elseif ((string) $existing->{$prop} !== (string) $fields[$prop]) {
                $changes[$prop] = ['status' => 'changed', 'old' => $existing->{$prop}, 'new' => $fields[$prop]];
            }
        }

        return ['kind' => 'term', 'type' => $taxonomy, 'slug' => $slug,
                'op' => $existing ? 'update' : 'create', 'changes' => $changes];
    }

    /* -----------------------------------------------------------------
     * Apply (writes)
     * ----------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $op
     * @param array<int, int>      $idMap
     * @return array{bucket: string, label: string}
     */
    private function applyPost(array $op, string $policy, array $idMap): array
    {
        $type = (string) ($op['target']['type'] ?? '');
        $slug = (string) ($op['target']['slug'] ?? '');
        $label = "{$type}:{$slug}";
        $incoming = is_array($op['post'] ?? null) ? $op['post'] : [];
        $explicitOp = $op['op'] ?? 'update';

        $existing = $this->findPost($type, $slug);

        if ($explicitOp === 'delete') {
            if ($existing) {
                wp_delete_post($existing->ID, false);
                return ['bucket' => 'deleted', 'label' => $label];
            }
            return ['bucket' => 'skipped', 'label' => $label];
        }

        if ($explicitOp === 'skip' || $policy === 'skip') {
            return ['bucket' => 'skipped', 'label' => $label];
        }
        if ($existing && $policy === 'create') {
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $postArr = [
            'post_type'    => $type,
            'post_name'    => $slug,
            'post_title'   => (string) ($incoming['title'] ?? $slug),
            'post_status'  => (string) ($incoming['status'] ?? 'publish'),
            'post_excerpt' => (string) ($incoming['excerpt'] ?? ''),
            'post_content' => MediaResolver::rewriteContent((string) ($incoming['content'] ?? ''), $idMap),
            'menu_order'   => (int) ($incoming['menu_order'] ?? 0),
        ];

        if (!empty($incoming['date'])) {
            $postArr['post_date_gmt'] = (string) $incoming['date'];
        }
        if (!empty($incoming['parent'])) {
            $parent = $this->findPost($type, (string) $incoming['parent']);
            if ($parent) {
                $postArr['post_parent'] = $parent->ID;
            } else {
                $this->warnings[] = "{$label}: parent '{$incoming['parent']}' not found — left unparented.";
            }
        }

        if ($existing) {
            $postArr['ID'] = $existing->ID;
            $postId = (int) wp_update_post(wp_slash($postArr), true);
            $bucket = 'updated';
        } else {
            $postId = (int) wp_insert_post(wp_slash($postArr), true);
            $bucket = 'created';
        }

        if ($postId <= 0) {
            $this->warnings[] = "{$label}: write failed.";
            return ['bucket' => 'skipped', 'label' => $label];
        }

        if (array_key_exists('template', $incoming)) {
            $tpl = (string) ($incoming['template'] ?? '');
            $tpl === '' ? delete_post_meta($postId, '_wp_page_template') : update_post_meta($postId, '_wp_page_template', $tpl);
        }

        $this->writePostFields($postId, is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [], $idMap);
        $this->writePostTerms($postId, is_array($incoming['terms'] ?? null) ? $incoming['terms'] : []);
        $this->assignFeaturedMedia($postId, $incoming['featured_media'] ?? null, $idMap);

        return ['bucket' => $bucket, 'label' => $label];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, int>      $idMap
     */
    private function writePostFields(int $postId, array $fields, array $idMap): void
    {
        foreach ($fields as $fieldId => $value) {
            $config = Metabox::get_field_config((string) $fieldId) ?? ['type' => 'text', 'id' => $fieldId];
            $value = FieldCodec::rewriteAttachmentIds($config, $value, $idMap);
            Metabox::writeMeta($postId, $config + ['id' => $fieldId], $value);
        }
    }

    /**
     * @param array<string, list<string>> $terms
     */
    private function writePostTerms(int $postId, array $terms): void
    {
        foreach ($terms as $taxonomy => $slugs) {
            if (!taxonomy_exists((string) $taxonomy)) {
                $this->warnings[] = "Taxonomy '{$taxonomy}' does not exist — terms skipped.";
                continue;
            }
            wp_set_object_terms($postId, array_map('strval', (array) $slugs), (string) $taxonomy, false);
        }
    }

    /**
     * @param mixed           $ref
     * @param array<int, int> $idMap
     */
    private function assignFeaturedMedia(int $postId, mixed $ref, array $idMap): void
    {
        if (empty($ref)) {
            return;
        }
        if (is_numeric($ref)) {
            $id = $idMap[(int) $ref] ?? (int) $ref;
            set_post_thumbnail($postId, $id);
            return;
        }
        $found = MediaResolver::findByFilename((string) $ref);
        if ($found !== null) {
            set_post_thumbnail($postId, $found);
        } else {
            $this->warnings[] = "Featured image '{$ref}' not found on the target site.";
        }
    }

    /**
     * @param array<string, mixed> $op
     * @return array{bucket: string, label: string}
     */
    private function applyOption(array $op, string $policy): array
    {
        $key = (string) ($op['target']['key'] ?? '');
        $value = $op['fields']['value'] ?? null;
        $explicitOp = $op['op'] ?? 'update';
        $exists = get_option($key, null) !== null;

        if ($explicitOp === 'delete') {
            delete_option($key);
            return ['bucket' => 'deleted', 'label' => "option:{$key}"];
        }
        if ($explicitOp === 'skip' || $policy === 'skip' || ($exists && $policy === 'create')) {
            return ['bucket' => 'skipped', 'label' => "option:{$key}"];
        }

        // page_on_front / page_for_posts arrive as a slug (the exporter's
        // portable form); WordPress requires the integer post ID — resolve
        // it back before writing, or the front page breaks (Bug A).
        $stored = in_array($key, self::POST_ID_OPTIONS, true)
            ? $this->resolveLocalPostId($value)
            : $this->encodeOptionForStorage($key, $value);

        update_option($key, $stored);

        return ['bucket' => $exists ? 'updated' : 'created', 'label' => "option:{$key}"];
    }

    /**
     * @param array<string, mixed> $op
     * @return array{bucket: string, label: string}
     */
    private function applyTerm(array $op, string $policy): array
    {
        $taxonomy = (string) ($op['target']['type'] ?? '');
        $slug = (string) ($op['target']['slug'] ?? '');
        $label = "term:{$taxonomy}:{$slug}";
        $fields = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $explicitOp = $op['op'] ?? 'update';

        if (!taxonomy_exists($taxonomy)) {
            $this->warnings[] = "Taxonomy '{$taxonomy}' does not exist — '{$slug}' skipped.";
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $existing = get_term_by('slug', $slug, $taxonomy);

        if ($explicitOp === 'delete') {
            if ($existing) {
                wp_delete_term($existing->term_id, $taxonomy);
                return ['bucket' => 'deleted', 'label' => $label];
            }
            return ['bucket' => 'skipped', 'label' => $label];
        }
        if ($explicitOp === 'skip' || $policy === 'skip' || ($existing && $policy === 'create')) {
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $args = [
            'description' => (string) ($fields['description'] ?? ($existing->description ?? '')),
        ];
        if (!empty($fields['parent'])) {
            $parent = get_term_by('slug', (string) $fields['parent'], $taxonomy);
            if ($parent) {
                $args['parent'] = $parent->term_id;
            }
        }

        if ($existing) {
            $args['name'] = (string) ($fields['name'] ?? $existing->name);
            wp_update_term($existing->term_id, $taxonomy, $args);
            $bucket = 'updated';
        } else {
            $created = wp_insert_term((string) ($fields['name'] ?? $slug), $taxonomy, $args + ['slug' => $slug]);
            if (is_wp_error($created)) {
                $this->warnings[] = "{$label}: " . $created->get_error_message();
                return ['bucket' => 'skipped', 'label' => $label];
            }
            $bucket = 'created';
        }

        return ['bucket' => $bucket, 'label' => $label];
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    private function findPost(string $type, string $slug): ?\WP_Post
    {
        if ($type === '' || $slug === '') {
            return null;
        }
        $matches = get_posts([
            'post_type'        => $type,
            'name'             => $slug,
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ]);
        return $matches[0] ?? null;
    }

    private function currentPostProp(\WP_Post $post, string $prop): mixed
    {
        return match ($prop) {
            'title'      => $post->post_title,
            'excerpt'    => $post->post_excerpt,
            'content'    => $post->post_content,
            'status'     => $post->post_status,
            'menu_order' => (int) $post->menu_order,
            'template'   => get_page_template_slug($post) ?: '',
            'parent'     => $post->post_parent ? (get_post($post->post_parent)->post_name ?? '') : '',
            default      => null,
        };
    }

    /**
     * Options the exporter renders as a post slug for portability but which
     * WordPress stores (and requires) as an integer post ID.
     */
    private const POST_ID_OPTIONS = ['page_on_front', 'page_for_posts'];

    /**
     * Reverse a portable post reference (slug, or an already-numeric ID) to
     * a local post ID — `0` when it is empty or doesn't resolve on this
     * site. The inverse of the exporter's slug-isation.
     */
    private function resolveLocalPostId(mixed $ref): int
    {
        if ($ref === null || $ref === '' || $ref === 0 || $ref === '0') {
            return 0;
        }

        if (is_numeric($ref)) {
            $post = get_post((int) $ref);
            return $post instanceof \WP_Post ? (int) $post->ID : 0;
        }

        $found = get_posts([
            'name'             => (string) $ref,
            'post_type'        => ['page', 'post'],
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        return ($found[0] ?? null) instanceof \WP_Post ? (int) $found[0]->ID : 0;
    }

    /**
     * A stable comparison key for a decoded field / option value. Whichever
     * "effectively empty" form a value takes — missing key, `''`, `null`,
     * `[]`, `false` — collapses to the same token, so a clean
     * export → import round-trip diffs to nothing.
     */
    private static function valueKey(mixed $value): string
    {
        return self::isEmptyValue($value) ? "\0empty" : (string) wp_json_encode($value);
    }

    private static function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }

    /**
     * Comparison key for an option value — post-ID options resolve through
     * {@see self::resolveLocalPostId()} (so a stored ID and an incoming
     * slug that point at the same post compare equal); TAW options decode
     * through their registered field type; everything else compares raw.
     */
    private function optionCompareKey(string $key, mixed $value): string
    {
        if (in_array($key, self::POST_ID_OPTIONS, true)) {
            return 'pid:' . $this->resolveLocalPostId($value);
        }

        $config = \TAW\Core\OptionsPage\OptionsPage::getFieldRegistry()[$key] ?? null;
        $decoded = $config !== null ? FieldCodec::decode($config, $value) : $value;

        return self::valueKey($decoded);
    }

    private function encodeOptionForStorage(string $key, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $config = \TAW\Core\OptionsPage\OptionsPage::getFieldRegistry()[$key] ?? null;
        if ($config !== null) {
            return Metabox::sanitizeForStorage($config, $value);
        }
        return wp_json_encode($value);
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function registryDrift(array $input): array
    {
        $recorded = $input['meta']['registry_fingerprint'] ?? null;
        if (!is_array($recorded)) {
            return [];
        }
        return RegistryFingerprint::drift($recorded, RegistryFingerprint::current());
    }

    private function writeRollbackSnapshot(): string
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'taw-private';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        foreach (['.htaccess' => "Require all denied\nDeny from all\n", 'index.php' => "<?php\n// Silence is golden.\n"] as $guard => $body) {
            $path = $dir . '/' . $guard;
            if (!file_exists($path)) {
                file_put_contents($path, $body);
            }
        }

        $path = $dir . '/rollback-' . gmdate('Ymd-His') . '.json';
        file_put_contents(
            $path,
            (string) wp_json_encode((new Exporter())->snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $path;
    }
}
