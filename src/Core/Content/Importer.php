<?php

declare(strict_types=1);

namespace TAW\Core\Content;

use TAW\Core\Metabox\Metabox;

if (!defined('ABSPATH')) {
    exit;
}

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
        $policy = in_array($options['policy'] ?? 'update', self::POLICIES, true) ? $options['policy'] : 'update';

        $report = [
            'created' => [], 'updated' => [], 'skipped' => [], 'deleted' => [],
            'media_sideloaded' => 0, 'warnings' => [], 'rollback_path' => null,
        ];

        if (($options['rollback'] ?? true) !== false) {
            $report['rollback_path'] = $this->writeRollbackSnapshot();
        }

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

        foreach (is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [] as $fieldId => $newVal) {
            $config = Metabox::get_field_config((string) $fieldId) ?? ['type' => 'text', 'id' => $fieldId];
            $oldDecoded = $existing
                ? FieldCodec::decode($config, get_post_meta($existing->ID, '_taw_' . $fieldId, true))
                : null;
            if (!$existing || $oldDecoded === '' || $oldDecoded === null) {
                $changes['fields.' . $fieldId] = ['status' => 'new', 'new' => $newVal];
            } elseif (wp_json_encode($oldDecoded) !== wp_json_encode($newVal)) {
                $changes['fields.' . $fieldId] = ['status' => 'changed', 'old' => $oldDecoded, 'new' => $newVal];
            }
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
        $new = $op['fields']['value'] ?? null;
        $current = get_option($key, null);

        $status = $current === null
            ? 'new'
            : (wp_json_encode($this->decodeOptionForCompare($key, $current)) === wp_json_encode($new) ? 'unchanged' : 'changed');

        return ['kind' => 'option', 'key' => $key, 'op' => $current === null ? 'create' : 'update',
                'changes' => $status === 'unchanged' ? [] : ['value' => ['status' => $status, 'old' => $current, 'new' => $new]]];
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

        update_option($key, $this->encodeOptionForStorage($key, $value));

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

    private function decodeOptionForCompare(string $key, mixed $current): mixed
    {
        $config = \TAW\Core\OptionsPage\OptionsPage::getFieldRegistry()[$key] ?? null;
        return $config !== null ? FieldCodec::decode($config, $current) : $current;
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
