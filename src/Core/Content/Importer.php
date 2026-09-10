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
 * Records are matched by natural key — posts by `(type, slug)` (or a
 * composite `(type, match_key)` for slug-less drafts), options by key, terms
 * by `(taxonomy, slug)`, users by login→email, comments by a content hash —
 * never by numeric ID. Meta is written through {@see Metabox::writeMeta()},
 * the same sanitize + `wp_slash` path an admin metabox save uses.
 *
 * **Apply order** is dependency-first and deterministic:
 * `users → terms → posts → options (non-settings) → comments → settings`.
 * Media is sideloaded and its old→new ID map built before posts are written.
 *
 * Accepts snapshots at schema `1.0` and `1.1`.
 *
 * @phpstan-type Operation array{op?: string, target: array<string, mixed>, post?: array<string, mixed>, fields?: array<string, mixed>}
 */
class Importer
{
    public const POLICIES = ['update', 'create', 'skip'];

    public const SUPPORTED_SCHEMA_MAJORS = ['1'];

    /** Relative apply order for each target kind (lower runs first). */
    private const KIND_ORDER = ['user' => 0, 'term' => 1, 'post' => 2, 'option' => 3, 'comment' => 4];

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
            $ops = is_array($ops) ? array_values(array_filter($ops, 'is_array')) : [];
            return self::sortByDependencyOrder($ops);
        }

        // A full snapshot — every record becomes an upsert operation.
        $ops = [];

        foreach ((is_array($input['users'] ?? null) ? $input['users'] : []) as $user) {
            if (is_array($user) && isset($user['login'])) {
                $ops[] = ['op' => 'update', 'target' => ['kind' => 'user', 'key' => (string) $user['login']], 'fields' => $user];
            }
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

        foreach ((is_array($input['posts'] ?? null) ? $input['posts'] : []) as $post) {
            if (!is_array($post)) {
                continue;
            }
            $ops[] = [
                'op'     => 'update',
                'target' => ['kind' => 'post', 'type' => $post['type'] ?? null, 'slug' => $post['slug'] ?? null, 'match_key' => $post['match_key'] ?? null],
                'post'   => $post,
            ];
        }

        foreach ((is_array($input['options'] ?? null) ? $input['options'] : []) as $key => $value) {
            $ops[] = ['op' => 'update', 'target' => ['kind' => 'option', 'key' => (string) $key], 'fields' => ['value' => $value]];
        }

        foreach ((is_array($input['comments'] ?? null) ? $input['comments'] : []) as $comment) {
            if (is_array($comment) && isset($comment['post_ref'])) {
                $ops[] = ['op' => 'update', 'target' => ['kind' => 'comment', 'key' => self::commentKey($comment)], 'fields' => $comment];
            }
        }

        return self::sortByDependencyOrder($ops);
    }

    /**
     * Stable sort into `users → terms → posts → options → comments →
     * settings-options` — the dependency order {@see self::apply()} relies on.
     *
     * @param list<array<string, mixed>> $ops
     * @return list<array<string, mixed>>
     */
    private static function sortByDependencyOrder(array $ops): array
    {
        $rank = static function (array $op): int {
            $kind = is_array($op['target'] ?? null) ? (string) ($op['target']['kind'] ?? '') : '';
            $base = self::KIND_ORDER[$kind] ?? 9;
            if ($kind === 'option' && in_array((string) ($op['target']['key'] ?? ''), Exporter::SETTINGS_OPTION_ALLOWLIST, true)) {
                return 5; // settings options run last of all
            }
            return $base;
        };

        // array_multisort would drop string keys; a stable manual sort keeps insertion order within a rank.
        $indexed = [];
        foreach ($ops as $i => $op) {
            $indexed[] = [$rank($op), $i, $op];
        }
        usort($indexed, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return array_map(static fn (array $row): array => $row[2], $indexed);
    }

    /**
     * Content-hash idempotency key for a comment record.
     *
     * @param array<string, mixed> $comment
     */
    private static function commentKey(array $comment): string
    {
        return sha1(implode('|', [
            (string) ($comment['post_ref'] ?? ''),
            (string) ($comment['author_email'] ?? ''),
            (string) ($comment['date_gmt'] ?? ''),
            (string) ($comment['content'] ?? ''),
        ]));
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
        $this->checkSchema($input);

        $records = [];
        foreach (self::operationsFrom($input) as $op) {
            $kind = $op['target']['kind'] ?? '';
            $records[] = match ($kind) {
                'post'    => $this->planPost($op),
                'option'  => $this->planOption($op),
                'term'    => $this->planTerm($op),
                'user'    => $this->planUser($op),
                'comment' => $this->planComment($op),
                default   => ['kind' => $kind, 'op' => 'skip', 'note' => "Unknown target kind '{$kind}'."],
            };
        }

        return [
            'registry_drift' => $this->registryDrift($input),
            'records'        => $records,
            'warnings'       => $this->warnings,
        ];
    }

    /**
     * Record a warning (not a hard failure) if the snapshot's schema major
     * is one this importer doesn't know.
     *
     * @param array<string, mixed> $input
     */
    private function checkSchema(array $input): void
    {
        $schema = $input['meta']['schema'] ?? ($input['taw_changeset']['schema'] ?? null);
        if (!is_string($schema) || $schema === '') {
            return;
        }
        $major = explode('.', $schema)[0];
        if (!in_array($major, self::SUPPORTED_SCHEMA_MAJORS, true)) {
            $this->warnings[] = "Snapshot schema '{$schema}' is newer than this taw/core understands (supports "
                . implode('.x / ', self::SUPPORTED_SCHEMA_MAJORS) . ".x) — importing best-effort.";
        }
    }

    /**
     * Apply the input. Writes a rollback snapshot first (unless
     * `$options['rollback'] === false`). Only call after a reviewed
     * {@see self::plan()} / an explicit `--yes` / the admin confirm.
     *
     * @param array<string, mixed> $input
     * @param array{policy?: string, rollback?: bool, include_settings?: bool} $options
     * @return array<string, mixed>
     */
    public function apply(array $input, array $options = []): array
    {
        $this->warnings = [];
        $this->commentIdMap = [];
        $this->pendingCommentParents = [];
        $this->touchedCommentPosts = [];
        $includeSettings = !empty($options['include_settings']);
        $policy = $options['policy'] ?? 'update';
        if (!in_array($policy, self::POLICIES, true)) {
            $policy = 'update';
        }
        $this->checkSchema($input);
        $schemaWarnings = $this->warnings;

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

            // The one section that's import-gated as well as export-gated:
            // environment settings only move under an explicit --with-settings.
            if ($kind === 'option'
                && in_array((string) ($op['target']['key'] ?? ''), Exporter::SETTINGS_OPTION_ALLOWLIST, true)
                && !$includeSettings
            ) {
                $report['skipped'][] = 'option:' . (string) ($op['target']['key'] ?? '') . ' (settings — pass --with-settings)';
                continue;
            }

            $result = match ($kind) {
                'post'    => $this->applyPost($op, $policy, $idMap),
                'option'  => $this->applyOption($op, $policy),
                'term'    => $this->applyTerm($op, $policy),
                'user'    => $this->applyUser($op, $policy),
                'comment' => $this->applyComment($op, $policy),
                default   => ['bucket' => 'skipped', 'label' => "unknown:{$kind}"],
            };
            $report[$result['bucket']][] = $result['label'];
        }

        // Second pass — comment threading + counts, once every comment exists.
        $this->finalizeComments();

        $report['warnings'] = array_merge($schemaWarnings, $this->warnings);

        return $report;
    }

    /** @var array<string, int> source comment ref => new comment ID */
    private array $commentIdMap = [];
    /** @var array<int, string> new comment ID => source parent ref */
    private array $pendingCommentParents = [];
    /** @var array<int, true> post IDs whose comment count needs recomputing */
    private array $touchedCommentPosts = [];

    private function finalizeComments(): void
    {
        foreach ($this->pendingCommentParents as $newId => $parentRef) {
            $newParent = $this->commentIdMap[$parentRef] ?? 0;
            if ($newParent > 0) {
                wp_update_comment(['comment_ID' => $newId, 'comment_parent' => $newParent]);
            }
        }
        foreach (array_keys($this->touchedCommentPosts) as $postId) {
            wp_update_comment_count((int) $postId);
        }
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

        if (in_array($kind, ['option', 'user', 'comment'], true)) {
            return $kind . ':' . (string) ($t['key'] ?? '');
        }

        // Slug-less drafts key on their composite match_key instead.
        $slug = (string) ($t['slug'] ?? '');
        $identity = $slug !== '' ? $slug : (string) ($t['match_key'] ?? '');

        return $kind . ':' . (string) ($t['type'] ?? '') . ':' . $identity;
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
        $matchKey = (string) ($op['target']['match_key'] ?? ($incoming['match_key'] ?? ''));
        $explicitOp = $op['op'] ?? 'update';

        $existing = $this->findPost($type, $slug, $matchKey, $incoming);
        $identity = ['kind' => 'post', 'type' => $type, 'slug' => $slug, 'match_key' => $matchKey];

        if ($explicitOp === 'delete') {
            return $identity + ['op' => $existing ? 'would-delete' : 'skip'];
        }

        $changes = [];

        foreach (['title', 'excerpt', 'content', 'status', 'menu_order', 'template', 'parent', 'comment_status', 'ping_status'] as $prop) {
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

        // Author — compare the incoming ref's *resolved local* user ID to the
        // post's current author, so a round-trip (or a snapshot from a site
        // with the same login) is a no-op and a missing author isn't noise.
        if ($existing && array_key_exists('author', $incoming) && $incoming['author'] !== null) {
            $incomingAuthor = $this->resolveLocalUserId($incoming['author']);
            if ($incomingAuthor > 0 && $incomingAuthor !== (int) $existing->post_author) {
                $changes['author'] = ['status' => 'changed', 'old' => (int) $existing->post_author, 'new' => $incomingAuthor];
            }
        } elseif (!$existing && !empty($incoming['author'])) {
            $changes['author'] = ['status' => 'new', 'new' => $incoming['author']];
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

        return $identity + [
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

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function planUser(array $op): array
    {
        $fields = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $login = (string) ($fields['login'] ?? '');
        $key = (string) ($op['target']['key'] ?? $login);
        $existing = $this->resolveLocalUserId(['login' => $login, 'email' => (string) ($fields['email'] ?? '')]);

        $changes = [];
        if ($existing === 0) {
            $changes['user'] = ['status' => 'new', 'new' => $login];
            return ['kind' => 'user', 'key' => $key, 'op' => 'create', 'changes' => $changes];
        }

        $user = get_userdata($existing);
        if ($user) {
            if (array_key_exists('display_name', $fields) && (string) $user->display_name !== (string) $fields['display_name']) {
                $changes['display_name'] = ['status' => 'changed', 'old' => $user->display_name, 'new' => $fields['display_name']];
            }
            $incomingRoles = array_values(array_map('strval', (array) ($fields['roles'] ?? [])));
            sort($incomingRoles);
            $currentRoles = array_values(array_map('strval', (array) $user->roles));
            sort($currentRoles);
            if ($incomingRoles !== [] && $incomingRoles !== $currentRoles) {
                $changes['roles'] = ['status' => 'changed', 'old' => $currentRoles, 'new' => $incomingRoles];
            }
            foreach (is_array($fields['meta'] ?? null) ? $fields['meta'] : [] as $mk => $mv) {
                if ((string) get_user_meta($existing, (string) $mk, true) !== (string) $mv) {
                    $changes['meta.' . $mk] = ['status' => 'changed', 'old' => get_user_meta($existing, (string) $mk, true), 'new' => $mv];
                }
            }
        }

        return ['kind' => 'user', 'key' => $key, 'op' => 'update', 'changes' => $changes];
    }

    /**
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private function planComment(array $op): array
    {
        $fields = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $key = (string) ($op['target']['key'] ?? '');
        $postId = $this->resolveAnyPostId((string) ($fields['post_ref'] ?? ''));

        if ($postId === 0) {
            return ['kind' => 'comment', 'key' => $key, 'op' => 'skip',
                    'note' => "post '" . (string) ($fields['post_ref'] ?? '') . "' not matched", 'changes' => []];
        }

        $exists = $this->findExistingComment($postId, $fields) > 0;

        return ['kind' => 'comment', 'key' => $key,
                'op' => $exists ? 'update' : 'create',
                'changes' => $exists ? [] : ['comment' => ['status' => 'new', 'new' => mb_substr((string) ($fields['content'] ?? ''), 0, 40)]]];
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
        $incoming = is_array($op['post'] ?? null) ? $op['post'] : [];
        $matchKey = (string) ($op['target']['match_key'] ?? ($incoming['match_key'] ?? ''));
        $label = "{$type}:" . ($slug !== '' ? $slug : "(draft {$matchKey})");
        $explicitOp = $op['op'] ?? 'update';

        $existing = $this->findPost($type, $slug, $matchKey, $incoming);

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

        foreach (['comment_status', 'ping_status'] as $prop) {
            if (array_key_exists($prop, $incoming)) {
                $postArr[$prop] = (string) $incoming[$prop];
            }
        }

        // Author — resolve the portable {login,email} ref to a local user;
        // fall back to the importing user with a warning when it's absent.
        if (array_key_exists('author', $incoming) && $incoming['author'] !== null) {
            $authorId = $this->resolveLocalUserId($incoming['author']);
            if ($authorId > 0) {
                $postArr['post_author'] = $authorId;
            } else {
                $fallback = (int) get_current_user_id();
                if ($fallback > 0) {
                    $postArr['post_author'] = $fallback;
                }
                $ref = is_array($incoming['author']) ? (string) ($incoming['author']['login'] ?? $incoming['author']['email'] ?? '?') : '?';
                $this->warnings[] = "{$label}: author '{$ref}' not found on this site — assigned to the importing user.";
            }
        }

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
        // sticky_posts is the list variant.
        if (in_array($key, self::POST_ID_OPTIONS, true)) {
            $stored = $this->resolveLocalPostId($value);
        } elseif (in_array($key, self::POST_ID_LIST_OPTIONS, true)) {
            $stored = array_values(array_filter(array_map(
                fn ($ref): int => $this->resolveLocalPostId($ref),
                is_array($value) ? $value : []
            )));
        } else {
            $stored = $this->encodeOptionForStorage($key, $value);
        }

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

    /**
     * @param array<string, mixed> $op
     * @return array{bucket: string, label: string}
     */
    private function applyUser(array $op, string $policy): array
    {
        $fields = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $login = (string) ($fields['login'] ?? '');
        $email = (string) ($fields['email'] ?? '');
        $label = "user:{$login}";
        $explicitOp = $op['op'] ?? 'update';

        if ($login === '' || $email === '') {
            $this->warnings[] = "{$label}: missing login or email — skipped.";
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $existingId = $this->resolveLocalUserId(['login' => $login, 'email' => $email]);

        if ($explicitOp === 'delete') {
            return ['bucket' => 'skipped', 'label' => $label]; // user deletion is never automatic
        }
        if ($explicitOp === 'skip' || $policy === 'skip' || ($existingId > 0 && $policy === 'create')) {
            return ['bucket' => 'skipped', 'label' => $label];
        }

        // Only ever grant roles the target site actually defines.
        $definedRoles = array_keys(wp_roles()->get_names());
        $roles = array_values(array_intersect(
            array_map('strval', (array) ($fields['roles'] ?? [])),
            array_map('strval', $definedRoles)
        ));
        foreach (array_diff(array_map('strval', (array) ($fields['roles'] ?? [])), $roles) as $dropped) {
            $this->warnings[] = "{$label}: role '{$dropped}' is not defined on this site — not granted.";
        }

        $userData = [
            'user_login'   => $login,
            'user_email'   => $email,
            'display_name' => (string) ($fields['display_name'] ?? $login),
            'role'         => $roles[0] ?? '',
        ];

        if ($existingId > 0) {
            $userData['ID'] = $existingId;
            $result = wp_update_user($userData);
            $bucket = 'updated';
        } else {
            $userData['user_pass'] = wp_generate_password(24, true, true);
            $result = wp_insert_user($userData);
            $bucket = 'created';
        }

        if (is_wp_error($result)) {
            $this->warnings[] = "{$label}: " . $result->get_error_message();
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $userId = (int) $result;

        // Apply every role (wp_insert_user only takes the first).
        if ($roles !== []) {
            $user = new \WP_User($userId);
            $user->set_role('');
            foreach ($roles as $role) {
                $user->add_role($role);
            }
        }

        foreach (is_array($fields['meta'] ?? null) ? $fields['meta'] : [] as $mk => $mv) {
            update_user_meta($userId, (string) $mk, $mv);
        }

        // Portable password hash — WP would re-hash a plain value, so write it raw.
        if (!empty($fields['password_hash'])) {
            global $wpdb;
            $wpdb->update($wpdb->users, ['user_pass' => (string) $fields['password_hash']], ['ID' => $userId]);
            clean_user_cache($userId);
        }

        return ['bucket' => $bucket, 'label' => $label];
    }

    /**
     * @param array<string, mixed> $op
     * @return array{bucket: string, label: string}
     */
    private function applyComment(array $op, string $policy): array
    {
        $fields = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $sourceRef = (string) ($fields['ref'] ?? ($op['target']['key'] ?? ''));
        $postRef = (string) ($fields['post_ref'] ?? '');
        $label = "comment:{$postRef}";

        if (($op['op'] ?? 'update') === 'skip' || $policy === 'skip') {
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $postId = $this->resolveAnyPostId($postRef);
        if ($postId === 0) {
            $this->warnings[] = "{$label}: target post not matched — comment skipped.";
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $existing = $this->findExistingComment($postId, $fields);
        if ($existing > 0) {
            if ($sourceRef !== '') {
                $this->commentIdMap[$sourceRef] = $existing;
            }
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $newId = (int) wp_insert_comment([
            'comment_post_ID'      => $postId,
            'comment_author'       => (string) ($fields['author_name'] ?? ''),
            'comment_author_email' => (string) ($fields['author_email'] ?? ''),
            'comment_author_url'   => (string) ($fields['author_url'] ?? ''),
            'comment_content'      => (string) ($fields['content'] ?? ''),
            'comment_date_gmt'     => (string) ($fields['date_gmt'] ?? ''),
            'comment_approved'     => (string) ($fields['approved'] ?? '1'),
            'comment_type'         => (string) ($fields['type'] ?? 'comment'),
        ]);

        if ($newId <= 0) {
            $this->warnings[] = "{$label}: insert failed.";
            return ['bucket' => 'skipped', 'label' => $label];
        }

        if ($sourceRef !== '') {
            $this->commentIdMap[$sourceRef] = $newId;
        }
        if (!empty($fields['parent_ref'])) {
            $this->pendingCommentParents[$newId] = (string) $fields['parent_ref'];
        }
        $this->touchedCommentPosts[$postId] = true;

        return ['bucket' => 'created', 'label' => $label];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function findExistingComment(int $postId, array $fields): int
    {
        $matches = get_comments([
            'post_id'      => $postId,
            'author_email' => (string) ($fields['author_email'] ?? ''),
            'date_query'   => [['column' => 'comment_date_gmt', 'after' => '-1 second', 'before' => '+1 second', 'inclusive' => true]],
            'number'       => 50,
            'status'       => 'all',
        ]);
        $wanted = (string) ($fields['content'] ?? '');
        $wantedDate = (string) ($fields['date_gmt'] ?? '');
        foreach ($matches as $c) {
            if ((string) $c->comment_content === $wanted
                && ($wantedDate === '' || (string) $c->comment_date_gmt === $wantedDate)) {
                return (int) $c->comment_ID;
            }
        }
        return 0;
    }

    /* -----------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    /**
     * Resolve a post slug to a local ID regardless of post type — used for
     * comment `post_ref`s, which don't carry their post's type.
     */
    private function resolveAnyPostId(string $slug): int
    {
        if ($slug === '') {
            return 0;
        }
        $found = get_posts([
            'name'             => $slug,
            'post_type'        => 'any',
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);
        return ($found[0] ?? null) instanceof \WP_Post ? (int) $found[0]->ID : 0;
    }

    /**
     * @param array<string, mixed> $incoming The full incoming post record (for the slug-less composite match).
     */
    private function findPost(string $type, string $slug, string $matchKey = '', array $incoming = []): ?\WP_Post
    {
        if ($type === '') {
            return null;
        }

        if ($slug !== '') {
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

        // Slug-less draft: match on the composite key (type | title | date_gmt).
        if ($matchKey === '') {
            return null;
        }
        $title = (string) ($incoming['title'] ?? '');
        $dateGmt = (string) ($incoming['date'] ?? '');
        foreach (get_posts([
            'post_type'        => $type,
            'post_status'      => ['draft', 'pending', 'auto-draft'],
            'title'            => $title,
            'posts_per_page'   => 20,
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ]) as $candidate) {
            $candidateKey = sha1($type . '|' . $candidate->post_title . '|' . $candidate->post_date_gmt);
            if ($candidateKey === $matchKey || ($title !== '' && $dateGmt !== '' && $candidate->post_title === $title && $candidate->post_date_gmt === $dateGmt)) {
                return $candidate;
            }
        }
        return null;
    }

    private function currentPostProp(\WP_Post $post, string $prop): mixed
    {
        return match ($prop) {
            'title'          => $post->post_title,
            'excerpt'        => $post->post_excerpt,
            'content'        => $post->post_content,
            'status'         => $post->post_status,
            'menu_order'     => (int) $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'template'       => get_page_template_slug($post) ?: '',
            'parent'         => $post->post_parent ? (get_post($post->post_parent)->post_name ?? '') : '',
            default          => null,
        };
    }

    /**
     * Options the exporter renders as a post slug for portability but which
     * WordPress stores (and requires) as an integer post ID.
     */
    private const POST_ID_OPTIONS = ['page_on_front', 'page_for_posts'];

    /** Options stored as a *list* of post IDs, exported as a list of slugs. */
    private const POST_ID_LIST_OPTIONS = ['sticky_posts'];

    /**
     * Reverse a portable user reference (`{login, email}`) to a local user
     * ID — login first, then email; `0` when unresolved.
     */
    private function resolveLocalUserId(mixed $ref): int
    {
        if (!is_array($ref)) {
            return 0;
        }
        $login = (string) ($ref['login'] ?? '');
        if ($login !== '') {
            $user = get_user_by('login', $login);
            if ($user) {
                return (int) $user->ID;
            }
        }
        $email = (string) ($ref['email'] ?? '');
        if ($email !== '') {
            $user = get_user_by('email', $email);
            if ($user) {
                return (int) $user->ID;
            }
        }
        return 0;
    }

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

        if (in_array($key, self::POST_ID_LIST_OPTIONS, true)) {
            $ids = array_map(fn ($ref): int => $this->resolveLocalPostId($ref), is_array($value) ? $value : []);
            sort($ids);
            return 'pids:' . implode(',', $ids);
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

        // Maximal scope — an undo of a `--migrate` import has to be able to
        // put users, settings, drafts and every attachment back, regardless
        // of what the incoming file happened to carry.
        $rollbackScope = [
            'include_users'    => true,
            'include_comments' => true,
            'include_settings' => true,
            'all_media'        => true,
            'include_drafts'   => true,
        ];

        $path = $dir . '/rollback-' . gmdate('Ymd-His') . '.json';
        file_put_contents(
            $path,
            (string) wp_json_encode((new Exporter())->snapshot($rollbackScope), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $path;
    }
}
