<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots, and the
// guard's `exit` silently kills the command (v1.25.1 fix). They are
// pure class definitions with no include-time side effects — like
// TAW\Helpers\Framework and TAW\CLI\WpLoader, which omit it too.

use TAW\Core\Metabox\Metabox;
use TAW\Core\Metabox\Store\TermMetaStore;
use TAW\Core\Metabox\Store\UserMetaStore;

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
 * Accepts snapshots at schema `1.x`; a minor version newer than
 * {@see Exporter::SCHEMA_VERSION} imports best-effort, with a warning.
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

    /** How the input's references map to this site's (see {@see self::buildRefMap()}). */
    private ?RefMap $refs = null;

    /** @var list<array{op: array<string, mixed>, id: int, label: string}> posts to rewrite again once the run's posts exist */
    private array $secondPass = [];

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
            $ops[] = ['op' => 'update', 'target' => self::postTarget($post), 'post' => $post];
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
     * A post record's identity: type, slug, and for a hierarchical type its
     * path (1.5), so `about/team` and `services/team` stay two records.
     * Slug-less drafts carry their composite `match_key`.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public static function postTarget(array $post): array
    {
        $target = ['kind' => 'post', 'type' => $post['type'] ?? null, 'slug' => $post['slug'] ?? null, 'match_key' => $post['match_key'] ?? null];
        if (is_string($post['path'] ?? null) && $post['path'] !== '') {
            $target['path'] = $post['path'];
        }

        return $target;
    }

    /**
     * Stable sort into `users → terms → posts → options → comments →
     * settings-options` — the dependency order {@see self::apply()} relies on.
     * Within terms and posts, parents come before their children, so a
     * child finds its parent even when the source listed it first.
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

        // A term's depth follows its `parent` chain through the input's terms.
        $termParents = [];
        foreach ($ops as $op) {
            if (($op['target']['kind'] ?? '') === 'term' && !empty($op['fields']['parent'])) {
                $termParents[(string) ($op['target']['type'] ?? '') . ':' . (string) ($op['target']['slug'] ?? '')] = (string) $op['fields']['parent'];
            }
        }
        $depth = static function (array $op) use ($termParents): int {
            $t = is_array($op['target'] ?? null) ? $op['target'] : [];
            if (($t['kind'] ?? '') === 'post') {
                return substr_count((string) ($t['path'] ?? ''), '/');
            }
            if (($t['kind'] ?? '') !== 'term') {
                return 0;
            }
            $taxonomy = (string) ($t['type'] ?? '');
            $key = $taxonomy . ':' . (string) ($t['slug'] ?? '');
            $seen = [];
            while (isset($termParents[$key]) && !isset($seen[$key])) {
                $seen[$key] = true;
                $key = $taxonomy . ':' . $termParents[$key];
            }
            return count($seen);
        };

        // array_multisort would drop string keys; a stable manual sort keeps insertion order within a rank.
        $indexed = [];
        foreach ($ops as $i => $op) {
            $indexed[] = [$rank($op), $depth($op), $i, $op];
        }
        usort($indexed, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: $a[2] <=> $b[2]);

        return array_map(static fn (array $row): array => $row[3], $indexed);
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
        $this->rememberIncoming($input);
        $media = new MediaResolver();
        $media->build(is_array($input['media'] ?? null) ? $input['media'] : [], false);
        $this->featuredIds = self::featuredIds($input, $media->idMap());
        $this->refs = $this->buildRefMap($input, $media->idMap(), $media->missingIds());

        // Media first, as apply runs it: files to download, files missing,
        // and attachments whose alt / caption / title differ.
        $records = $this->planMedia($media->outcomes());
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
            return;
        }
        if (version_compare($schema, Exporter::SCHEMA_VERSION, '>')) {
            $this->warnings[] = "Snapshot schema '{$schema}' is newer than this taw/core's " . Exporter::SCHEMA_VERSION
                . " — what it added is ignored. Update taw/core here to import it fully.";
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
        // Content is written as the site has it, like an admin's save:
        // kses (on when the current user lacks unfiltered_html, as in a CLI
        // run) would strip embeds, SVG and forms and turn `&` into `&amp;`.
        $kses = function_exists('kses_remove_filters') && has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
        if ($kses) {
            kses_remove_filters();
        }
        try {
            return $this->run($input, $options);
        } finally {
            if ($kses) {
                kses_init_filters();
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array{policy?: string, rollback?: bool, include_settings?: bool} $options
     * @return array<string, mixed>
     */
    private function run(array $input, array $options): array
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
            'created' => [], 'updated' => [], 'skipped' => [], 'deleted' => [], 'failed' => [],
            'media_sideloaded' => 0, 'warnings' => [], 'rollback_path' => null, 'journal' => null, 'error' => null,
        ];

        // A safety net first, or nothing: the full rollback snapshot, and
        // the journal `content:import --undo` reverses.
        if (($options['rollback'] ?? true) === false) {
            $this->applyAll($input, $policy, $includeSettings, $report);
            $report['warnings'] = array_merge($schemaWarnings, $report['warnings']);
            return $report;
        }
        $report['rollback_path'] = $this->writeRollbackSnapshot();
        $journalPath = $report['rollback_path'] !== null ? $this->openJournal() : null;
        if ($journalPath === null) {
            $report['error'] = "Couldn't write the rollback snapshot or the import journal in uploads/taw-private, so nothing was imported.";
            $report['warnings'] = $schemaWarnings;
            return $report;
        }

        $journal = new ImportJournal(new WpRecords());
        $unwatch = WpRecords::watch($journal);
        try {
            $this->applyAll($input, $policy, $includeSettings, $report);
        } catch (\Throwable $e) {
            $report['error'] = 'The import stopped: ' . $e->getMessage() . ' — undo what it did with content:import --undo.';
            $report['warnings'] = $this->warnings;
        } finally {
            $unwatch();
            $report['journal'] = $this->writeJournal($journalPath, $journal->entries(), $input);
            if ($report['journal'] === null) {
                $report['warnings'][] = "Couldn't save the import journal ({$journalPath}); the rollback snapshot is the way back.";
            }
        }
        $report['warnings'] = array_merge($schemaWarnings, $report['warnings']);

        return $report;
    }

    /**
     * Everything the import writes, into $report. A record that fails is
     * listed under `failed` and the rest carry on.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $report
     */
    private function applyAll(array $input, string $policy, bool $includeSettings, array &$report): void
    {
        // Media first: the ID map rewrites references in posts and fields,
        // and the unchanged check below must see the files this run brings
        // (a record linking to a file not yet here would look unchanged and
        // only settle on the next import). Sideloading writes attachments
        // only, never the records compared.
        $media = is_array($input['media'] ?? null) ? $input['media'] : [];
        $resolver = new MediaResolver();
        $resolver->build($media, true);
        $idMap = $resolver->idMap();
        $report['media_sideloaded'] = $resolver->sideloadedCount();
        $mediaWarnings = $resolver->warnings();

        // Records the dry-run diff shows as already matching are skipped —
        // so a clean export → import round-trip is a genuine no-op and
        // re-running an import doesn't churn post_modified dates. (It also
        // resets $this->warnings.)
        $unchanged = $this->unchangedRecordKeys($input);
        $this->warnings = $mediaWarnings;
        $this->refs = $this->buildRefMap($input, $idMap, $resolver->missingIds());
        $this->featuredIds = self::featuredIds($input, $idMap);
        if ($policy === 'update') {
            foreach ($this->planMedia($resolver->outcomes()) as $record) {
                if (($record['op'] ?? '') === 'update' && !empty($record['changes'])) {
                    $this->updateMedia((int) $record['local'], $record['changes']);
                    $report['updated'][] = 'media:' . $record['key'];
                }
            }
        }
        $this->secondPass = [];
        $this->rememberIncoming($input);

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

            try {
                $result = match ($kind) {
                    'post'    => $this->applyPost($op, $policy, $idMap),
                    'option'  => $this->applyOption($op, $policy),
                    'term'    => $this->applyTerm($op, $policy, $idMap),
                    'user'    => $this->applyUser($op, $policy, $idMap),
                    'comment' => $this->applyComment($op, $policy),
                    default   => ['bucket' => 'skipped', 'label' => "unknown:{$kind}"],
                };
            } catch (\Throwable $e) {
                $result = ['bucket' => 'failed', 'label' => $this->recordKey($op) . ': ' . $e->getMessage()];
            }
            $report[$result['bucket']][] = $result['label'];
        }

        // Second pass — references to posts this run created after the post
        // that points at them; then comment threading + counts, once every
        // comment exists.
        foreach (['second pass' => fn () => $this->rewriteSecondPass(), 'comment threading' => fn () => $this->finalizeComments()] as $step => $run) {
            try {
                $run();
            } catch (\Throwable $e) {
                $report['failed'][] = "{$step}: " . $e->getMessage();
            }
        }

        $report['warnings'] = $this->warnings;
    }

    /** @var array<string, int> media filename in the input => the local attachment it matched or became */
    private array $featuredIds = [];

    /**
     * Featured images are referenced by filename: through the media entries
     * to the local attachment (a downloaded copy may have another name).
     *
     * @param array<string, mixed> $input
     * @param array<int, int>      $idMap
     * @return array<string, int>
     */
    private static function featuredIds(array $input, array $idMap): array
    {
        $out = [];
        foreach (is_array($input['media'] ?? null) ? $input['media'] : [] as $entry) {
            $local = is_array($entry) ? ($idMap[(int) ($entry['id'] ?? 0)] ?? 0) : 0;
            $name = is_array($entry) ? (string) ($entry['filename'] ?? $entry['ref'] ?? '') : '';
            if ($local > 0 && $name !== '') {
                $out[$name] = $local;
            }
        }

        return $out;
    }

    /** @var array<string, true> "type:slug" of every post in the input */
    private array $incomingPosts = [];

    /** @var array<string, true> "type:path" of every hierarchical post in the input */
    private array $incomingPaths = [];

    /** @var array<string, true> "taxonomy:slug" of every term in the input */
    private array $incomingTerms = [];

    /** @var array<string, int> "type:path" => the local post an input page moved away from, see {@see self::matchMovedPosts()} */
    private array $movedPosts = [];

    /**
     * The posts and terms the input brings, so a parent this run creates
     * isn't reported as missing, and a page isn't matched to another
     * record's page by its slug alone.
     *
     * @param array<string, mixed> $input
     */
    private function rememberIncoming(array $input): void
    {
        $this->incomingPosts = $this->incomingPaths = $this->incomingTerms = [];
        $bySlug = [];
        foreach (self::operationsFrom($input) as $op) {
            $t = $op['target'];
            $kind = (string) ($t['kind'] ?? '');
            $type = (string) ($t['type'] ?? '');
            if ($kind === 'post') {
                $slug = (string) ($t['slug'] ?? '');
                $this->incomingPosts[$type . ':' . $slug] = true;
                if (($t['path'] ?? '') !== '') {
                    $this->incomingPaths[$type . ':' . (string) $t['path']] = true;
                    $bySlug[$type][$slug][(string) $t['path']] = (string) ($op['post']['title'] ?? '');
                }
            } elseif ($kind === 'term') {
                $this->incomingTerms[$type . ':' . (string) ($t['slug'] ?? '')] = true;
            }
        }
        $this->matchMovedPosts($bySlug);
    }

    /**
     * Pages of the input that aren't at their path here, paired with this
     * site's pages of the same slug that aren't at any path of the input
     * (a page moved at the source since the last import): one of each, or
     * else by a title only one page on each side has. Anything else stays
     * unpaired and is created, never written over another page.
     *
     * @param array<string, array<string, array<string, string>>> $bySlug type => slug => path => title
     */
    private function matchMovedPosts(array $bySlug): void
    {
        $this->movedPosts = [];
        foreach ($bySlug as $type => $slugs) {
            if (!is_post_type_hierarchical((string) $type)) {
                continue;
            }
            foreach ($slugs as $slug => $titles) {
                $local = [];
                foreach ($slug === '' ? [] : get_posts([
                    'post_type'        => (string) $type,
                    'post_name__in'    => [(string) $slug],
                    'post_status'      => 'any',
                    'posts_per_page'   => 50,
                    'suppress_filters' => false,
                    'no_found_rows'    => true,
                ]) as $post) {
                    $path = (string) get_page_uri($post);
                    if (isset($titles[$path])) {
                        unset($titles[$path]); // at its path: matched directly
                    } elseif (!isset($this->incomingPaths[$type . ':' . $path])) {
                        $local[] = $post;
                    }
                }
                if ($titles === [] || $local === []) {
                    continue;
                }
                if (count($titles) === 1 && count($local) === 1) {
                    $this->movedPosts[$type . ':' . array_key_first($titles)] = (int) $local[0]->ID;
                    continue;
                }
                foreach ($local as $post) {
                    $same = array_keys($titles, (string) $post->post_title, true);
                    $twins = array_filter($local, static fn (\WP_Post $p): bool => $p->post_title === $post->post_title);
                    if (count($same) === 1 && count($twins) === 1) {
                        $this->movedPosts[$type . ':' . $same[0]] = (int) $post->ID;
                    }
                }
            }
        }
    }

    private function refs(): RefMap
    {
        return $this->refs ??= new RefMap();
    }

    /**
     * The input's references mapped to this site's: attachments by the
     * media match, posts/terms/users by their natural keys in `refs` (1.4),
     * and URLs — each media file's source URL to its local one, then the
     * source origin to this site's.
     *
     * @param array<string, mixed> $input
     * @param array<int, int>      $idMap   source attachment id => local id
     * @param list<int>            $missing source attachment ids this site won't have
     */
    private function buildRefMap(array $input, array $idMap, array $missing = []): RefMap
    {
        $refs = is_array($input['refs'] ?? null) ? $input['refs'] : [];
        $sourceUrl = (string) ($input['meta']['source']['url'] ?? '');
        $mediaUrls = [];
        foreach (is_array($input['media'] ?? null) ? $input['media'] : [] as $entry) {
            $local = $idMap[(int) ($entry['id'] ?? 0)] ?? 0;
            $from = (string) ($entry['url'] ?? '');
            if ($local > 0 && $from !== '' && is_string($to = wp_get_attachment_url($local))) {
                $mediaUrls[] = ['from' => $from, 'to' => $to];
            }
        }

        return new RefMap(
            $idMap,
            is_array($refs['posts'] ?? null) ? $refs['posts'] : [],
            is_array($refs['terms'] ?? null) ? $refs['terms'] : [],
            is_array($refs['users'] ?? null) ? $refs['users'] : [],
            fn (string $type, string $slug, string $path = ''): int => (int) ($this->findPost($type, $slug, '', [], $path)->ID ?? 0),
            static function (string $taxonomy, string $slug): int {
                $term = $taxonomy !== '' && $slug !== '' ? get_term_by('slug', $slug, $taxonomy) : false;
                return $term instanceof \WP_Term ? (int) $term->term_id : 0;
            },
            fn (array $ref): int => $this->resolveLocalUserId($ref),
            $mediaUrls,
            $sourceUrl,
            $sourceUrl !== '' ? (string) home_url() : '',
            (string) ($input['meta']['source']['uploads_url'] ?? ''),
            $missing,
        );
    }

    /**
     * The media part of the plan: a file to download (`create`), one this
     * site can't get (`missing`: what references it is cleared), and an
     * attachment here whose alt text, caption, title or description differ
     * (`update`). Media that already matches isn't listed.
     *
     * @param list<array{id: int, filename: string, path: string, url: string, status: string, local: int, entry: array<string, mixed>}> $outcomes
     * @return list<array<string, mixed>>
     */
    private function planMedia(array $outcomes): array
    {
        $records = [];
        foreach ($outcomes as $o) {
            $identity = ['kind' => 'media', 'key' => $o['path'], 'local' => $o['local']];
            if ($o['status'] === 'would-sideload') {
                $records[] = $identity + ['op' => 'create', 'changes' => ['file' => ['status' => 'new', 'new' => $o['url']]]];
                continue;
            }
            if (in_array($o['status'], ['missing', 'failed'], true)) {
                $records[] = $identity + ['op' => 'missing', 'changes' => ['file' => ['status' => 'missing', 'new' => $o['filename']]]];
                continue;
            }
            if ($o['status'] !== 'matched' || $o['local'] <= 0) {
                continue;
            }
            $changes = [];
            foreach (self::mediaFields($o['entry']) as $prop => $new) {
                $old = self::currentMediaField($o['local'], $prop);
                if ($old !== $new) {
                    $changes[$prop] = ['status' => $old === '' ? 'new' : 'changed', 'old' => $old, 'new' => $new];
                }
            }
            if ($changes !== []) {
                $records[] = $identity + ['op' => 'update', 'changes' => $changes];
            }
        }

        return $records;
    }

    /**
     * A media entry's metadata as this site would store it (captions and
     * descriptions keep their markup, as an editor's save does).
     *
     * @param array<string, mixed> $entry
     * @return array<string, string>
     */
    private static function mediaFields(array $entry): array
    {
        $out = [];
        foreach (['title', 'caption', 'alt', 'description'] as $prop) {
            if (!array_key_exists($prop, $entry) || !is_scalar($entry[$prop])) {
                continue;
            }
            $value = (string) $entry[$prop];
            $out[$prop] = match ($prop) {
                'alt'                    => sanitize_text_field($value),
                'caption', 'description' => wp_kses_post($value),
                default                  => $value,
            };
        }

        return $out;
    }

    private static function currentMediaField(int $id, string $prop): string
    {
        if ($prop === 'alt') {
            return (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        }
        $post = get_post($id);

        return $post instanceof \WP_Post ? (string) match ($prop) {
            'title'       => $post->post_title,
            'caption'     => $post->post_excerpt,
            default       => $post->post_content,
        } : '';
    }

    /**
     * @param array<string, array{new?: mixed}> $changes
     */
    private function updateMedia(int $id, array $changes): void
    {
        $post = ['ID' => $id];
        foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $prop => $column) {
            if (isset($changes[$prop])) {
                $post[$column] = (string) ($changes[$prop]['new'] ?? '');
            }
        }
        if (count($post) > 1) {
            $result = wp_update_post(wp_slash($post), true);
            if (is_wp_error($result)) {
                $this->warnings[] = "media #{$id}: update failed: " . $result->get_error_message();
            }
        }
        if (isset($changes['alt'])) {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash((string) ($changes['alt']['new'] ?? '')));
        }
    }

    /**
     * A TAW option's incoming value with its references mapped (registered
     * options by their field type; others' strings for URLs). Core options
     * pass through.
     */
    private function rewriteOption(string $key, mixed $value): mixed
    {
        if (!str_starts_with($key, '_taw_')) {
            return $value;
        }
        $config = \TAW\Core\OptionsPage\OptionsPage::getFieldRegistry()[$key] ?? null;

        return $config !== null
            ? FieldCodec::rewriteRefs($config, FieldCodec::decode($config, $value), $this->refs())
            : FieldCodec::rewriteStrings($value, $this->refs());
    }

    /**
     * Rewrite the content and fields of posts that referenced a post this
     * run created after them, now that every post exists.
     */
    private function rewriteSecondPass(): void
    {
        foreach ($this->secondPass as ['op' => $op, 'id' => $postId, 'label' => $label]) {
            $incoming = is_array($op['post'] ?? null) ? $op['post'] : [];
            $this->refs()->takeUnresolved();
            $result = wp_update_post(wp_slash([
                'ID'           => $postId,
                'post_content' => BlockRefs::rewrite((string) ($incoming['content'] ?? ''), $this->refs()),
                'post_excerpt' => BlockRefs::rewrite((string) ($incoming['excerpt'] ?? ''), $this->refs()),
            ]), true);
            if (is_wp_error($result)) {
                $this->warnings[] = "{$label}: second pass failed: " . $result->get_error_message();
                continue;
            }
            $this->writePostFields($postId, (string) ($op['target']['type'] ?? ''), is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [], []);
            $unresolved = $this->refs()->takeUnresolved();
            if ($unresolved !== []) {
                $this->warnings[] = "{$label}: references " . implode(', ', $unresolved) . " — not on this site, dropped.";
            }
        }
        $this->secondPass = [];
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

        if (in_array($kind, ['option', 'user', 'comment', 'media'], true)) {
            return $kind . ':' . (string) ($t['key'] ?? '');
        }

        // A hierarchical post keys on its path (1.5); slug-less drafts on
        // their composite match_key.
        $slug = (string) ($t['slug'] ?? '');
        $identity = ($t['path'] ?? '') !== '' ? (string) $t['path'] : ($slug !== '' ? $slug : (string) ($t['match_key'] ?? ''));

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

        $path = (string) ($op['target']['path'] ?? '');
        $existing = $this->findPost($type, $slug, $matchKey, $incoming, $path);
        $identity = ['kind' => 'post', 'type' => $type, 'slug' => $slug, 'match_key' => $matchKey];
        if ($path !== '') {
            $identity['path'] = $path;
        }

        if ($explicitOp === 'delete') {
            return $identity + ['op' => $existing ? 'would-delete' : 'skip'];
        }

        $changes = [];

        foreach (['title', 'excerpt', 'content', 'status', 'menu_order', 'template', 'comment_status', 'ping_status', 'password'] as $prop) {
            if (!array_key_exists($prop, $incoming)) {
                continue;
            }
            $new = in_array($prop, ['content', 'excerpt'], true)
                ? BlockRefs::rewrite((string) $incoming[$prop], $this->refs())
                : $incoming[$prop];
            $old = $existing ? $this->currentPostProp($existing, $prop) : null;
            if ($prop === 'password' && !$existing && (string) $new === '') {
                continue;
            }
            if (!$existing) {
                $changes[$prop] = ['status' => 'new', 'new' => $new];
            } elseif ((string) $old !== (string) $new) {
                $changes[$prop] = ['status' => 'changed', 'old' => $old, 'new' => $new];
            }
        }

        // Parent — compared by the local post it resolves to; none clears it.
        // A parent neither here nor in the input can't be set: apply warns.
        if (array_key_exists('parent', $incoming)) {
            $ref = (string) ($incoming['parent'] ?? '');
            $old = $existing ? $this->currentPostProp($existing, 'parent') : '';
            $parent = $ref === '' ? null : $this->findParent($type, $ref);
            $settable = $ref === '' || $parent !== null || isset($this->incomingPaths[$type . ':' . $ref]) || isset($this->incomingPosts[$type . ':' . $ref]);
            $differs = $existing ? (int) $existing->post_parent !== (int) ($parent->ID ?? 0) : $ref !== '';
            if ($differs && $settable) {
                $changes['parent'] = $existing ? ['status' => 'changed', 'old' => $old, 'new' => $ref] : ['status' => 'new', 'new' => $ref];
            } elseif (!$settable) {
                $this->warnings[] = "{$type}:" . ($path !== '' ? $path : $slug) . ": parent '{$ref}' isn't on this site or in the import — not set.";
            }
        }

        // Date — compared in GMT; a draft has none (0000-00-00 00:00:00).
        $newDate = self::realDate($incoming['date'] ?? null);
        if ($existing && $newDate !== '' && $newDate !== (string) $existing->post_date_gmt) {
            $changes['date'] = ['status' => 'changed', 'old' => (string) $existing->post_date_gmt, 'new' => $newDate];
        }

        // Terms — per taxonomy the snapshot lists (an empty list clears it).
        if ($existing && is_array($incoming['terms'] ?? null)) {
            foreach ($incoming['terms'] as $taxonomy => $slugs) {
                if (!taxonomy_exists((string) $taxonomy)) {
                    continue;
                }
                $new = array_map('strval', array_values((array) $slugs));
                sort($new);
                $current = wp_get_object_terms($existing->ID, (string) $taxonomy, ['fields' => 'slugs']);
                $old = is_array($current) ? array_map('strval', $current) : [];
                sort($old);
                if ($old !== $new) {
                    $changes['terms.' . $taxonomy] = ['status' => 'changed', 'old' => $old, 'new' => $new];
                }
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
            $newLocal = is_string($newRef) ? ($this->featuredIds[$newRef] ?? 0) : 0;
            if ($newLocal > 0 ? $newLocal !== $currentThumb : (string) $currentRef !== (string) $newRef) {
                $changes['featured_media'] = ($existing && $currentRef !== null)
                    ? ['status' => 'changed', 'old' => $currentRef, 'new' => $newRef]
                    : ['status' => 'new', 'new' => $newRef];
            }
        }

        $registered = Metabox::fieldsFor('post', $type);
        foreach (is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [] as $fieldId => $newVal) {
            $target = FieldKeys::forKey((string) $fieldId, $registered, self::bareConfigLookup());
            $config = $target['config'];

            // Normalize BOTH sides through the same decode path so that
            // empty ↔ empty, "1" ↔ true, "[…]" ↔ [...], "42" ↔ 42 all
            // compare equal — a clean export→import round-trip must be a
            // no-op (see Bug B).
            $oldDecoded = FieldCodec::decode(
                $config,
                $existing ? get_post_meta($existing->ID, $target['meta_key'], true) : ''
            );
            $newDecoded = FieldCodec::decode($config, FieldCodec::rewriteRefs($config, FieldCodec::decode($config, $newVal), $this->refs()));

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
        $incoming = $this->rewriteOption($key, $op['fields']['value'] ?? null);
        $currentRaw = get_option($key, null);
        $exists = $currentRaw !== null;

        if (($op['op'] ?? 'update') === 'delete') {
            return ['kind' => 'option', 'key' => $key, 'op' => $exists ? 'would-delete' : 'skip', 'changes' => []];
        }

        // A page this site lacks (and the import doesn't bring) is never
        // written as 0: the front page would turn into the posts list.
        $missing = $this->unresolvedPostRefs($key, $incoming, true);
        if ($missing !== []) {
            $single = in_array($key, self::POST_ID_OPTIONS, true);
            $this->warnings[] = "option:{$key}: '" . implode("', '", $missing) . "' isn't on this site or in the import — "
                . ($single ? 'kept as it is.' : 'left out.');
            if ($single) {
                return ['kind' => 'option', 'key' => $key, 'op' => 'update', 'changes' => []];
            }
        }

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

        if (($op['op'] ?? 'update') === 'delete') {
            return ['kind' => 'term', 'type' => $taxonomy, 'slug' => $slug, 'op' => $existing ? 'would-delete' : 'skip', 'changes' => []];
        }

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

        // Parent — by slug (unique within a taxonomy); none clears it. A
        // parent neither here nor in the input can't be set: apply warns.
        if (array_key_exists('parent', $fields)) {
            $ref = (string) ($fields['parent'] ?? '');
            $old = $existing ? $this->termParentSlug($existing) : '';
            $settable = $ref === '' || get_term_by('slug', $ref, $taxonomy) instanceof \WP_Term || isset($this->incomingTerms[$taxonomy . ':' . $ref]);
            if ($old !== $ref && $settable) {
                $changes['parent'] = $existing ? ['status' => 'changed', 'old' => $old, 'new' => $ref] : ['status' => 'new', 'new' => $ref];
            } elseif (!$settable) {
                $this->warnings[] = "term:{$taxonomy}:{$slug}: parent '{$ref}' isn't on this site or in the import — not set.";
            }
        }

        // Term meta outside TAW fields, as the source has it (URLs mapped).
        foreach (is_array($fields['meta'] ?? null) ? $fields['meta'] : [] as $metaKey => $value) {
            $new = $this->termMetaValue($value);
            $old = $existing ? get_term_meta($existing->term_id, (string) $metaKey, true) : '';
            if (self::valueKey($old) !== self::valueKey($new)) {
                $changes['meta.' . $metaKey] = ($existing && !self::isEmptyValue($old))
                    ? ['status' => 'changed', 'old' => $old, 'new' => $new]
                    : ['status' => 'new', 'new' => $new];
            }
        }

        // Term fieldsets (ADR-0008): same key rules and normalized diff as post fields.
        $registered = Metabox::fieldsFor('term', $taxonomy);
        foreach (is_array($fields['fields'] ?? null) ? $fields['fields'] : [] as $fieldKey => $newVal) {
            $target = FieldKeys::forKey((string) $fieldKey, $registered, self::bareConfigLookup());
            $oldDecoded = FieldCodec::decode($target['config'], $existing ? get_term_meta($existing->term_id, $target['meta_key'], true) : '');
            $newDecoded = FieldCodec::decode($target['config'], FieldCodec::rewriteRefs($target['config'], FieldCodec::decode($target['config'], $newVal), $this->refs()));
            if (self::valueKey($oldDecoded) === self::valueKey($newDecoded)) {
                continue;
            }
            $changes['fields.' . $fieldKey] = ($existing && !self::isEmptyValue($oldDecoded))
                ? ['status' => 'changed', 'old' => $oldDecoded, 'new' => $newDecoded]
                : ['status' => 'new', 'new' => $newDecoded];
        }

        return ['kind' => 'term', 'type' => $taxonomy, 'slug' => $slug,
                'op' => $existing ? 'update' : 'create', 'changes' => $changes];
    }

    private function termParentSlug(\WP_Term $term): string
    {
        $parent = $term->parent ? get_term((int) $term->parent, $term->taxonomy) : null;

        return $parent instanceof \WP_Term ? (string) $parent->slug : '';
    }

    /**
     * A term meta value as it's stored here: unserialized (1.5 exports
     * values unserialized; older snapshots carry the serialized string,
     * which is decoded without objects, or kept as it came when it holds
     * one), with the source's URLs mapped.
     */
    private function termMetaValue(mixed $value): mixed
    {
        if (is_string($value) && is_serialized($value) && !preg_match('/(?:^|[;{])[OC]:\d+:"/', $value)) {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            if ($decoded !== false || $value === serialize(false)) {
                $value = $decoded;
            }
        }

        return FieldCodec::rewriteStrings($value, $this->refs());
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

            // User fieldsets (ADR-0008): same key rules and normalized diff as post fields.
            $registered = Metabox::fieldsFor('user');
            foreach (is_array($fields['fields'] ?? null) ? $fields['fields'] : [] as $fieldKey => $newVal) {
                $target = FieldKeys::forKey((string) $fieldKey, $registered, self::bareConfigLookup());
                $oldDecoded = FieldCodec::decode($target['config'], get_user_meta($existing, $target['meta_key'], true));
                $newDecoded = FieldCodec::decode($target['config'], FieldCodec::rewriteRefs($target['config'], FieldCodec::decode($target['config'], $newVal), $this->refs()));
                if (self::valueKey($oldDecoded) !== self::valueKey($newDecoded)) {
                    $changes['fields.' . $fieldKey] = self::isEmptyValue($oldDecoded)
                        ? ['status' => 'new', 'new' => $newDecoded]
                        : ['status' => 'changed', 'old' => $oldDecoded, 'new' => $newDecoded];
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
        $postId = $this->resolveCommentPostId($fields);

        if ($postId === 0) {
            return ['kind' => 'comment', 'key' => $key, 'op' => 'skip',
                    'note' => "post '" . (string) ($fields['post_ref'] ?? '') . "' not matched", 'changes' => []];
        }

        $exists = $this->findExistingComment($postId, $fields) > 0;
        if (($op['op'] ?? 'update') === 'delete') {
            return ['kind' => 'comment', 'key' => $key, 'op' => $exists ? 'would-delete' : 'skip', 'changes' => []];
        }

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
        $path = (string) ($op['target']['path'] ?? '');
        $label = "{$type}:" . ($path !== '' ? $path : ($slug !== '' ? $slug : "(draft {$matchKey})"));
        $explicitOp = $op['op'] ?? 'update';

        $existing = $this->findPost($type, $slug, $matchKey, $incoming, $path);

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
            'post_excerpt' => BlockRefs::rewrite((string) ($incoming['excerpt'] ?? ''), $this->refs()),
            'post_content' => BlockRefs::rewrite((string) ($incoming['content'] ?? ''), $this->refs()),
            'menu_order'   => (int) ($incoming['menu_order'] ?? 0),
        ];

        foreach (['comment_status', 'ping_status'] as $prop) {
            if (array_key_exists($prop, $incoming)) {
                $postArr[$prop] = (string) $incoming[$prop];
            }
        }
        if (array_key_exists('password', $incoming)) {
            $postArr['post_password'] = (string) $incoming['password'];
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

        // Both columns: wp_update_post keeps the old post_date otherwise, and
        // the local and GMT dates drift apart.
        $date = self::realDate($incoming['date'] ?? null);
        if ($date !== '') {
            $postArr['post_date_gmt'] = $date;
            $postArr['post_date'] = get_date_from_gmt($date);
            $postArr['edit_date'] = true;
        }
        // Parents run before their children (see sortByDependencyOrder), so
        // one this run creates is already here. None at the source clears it.
        if (array_key_exists('parent', $incoming)) {
            $ref = (string) ($incoming['parent'] ?? '');
            $parent = $ref === '' ? null : $this->findParent($type, $ref);
            if ($ref === '' || $parent !== null) {
                $postArr['post_parent'] = $parent !== null ? (int) $parent->ID : 0;
            } else {
                $this->warnings[] = "{$label}: parent '{$ref}' not found — " . ($existing ? 'parent left as it was.' : 'left unparented.');
            }
        }

        if ($existing) {
            $postArr['ID'] = $existing->ID;
            $result = wp_update_post(wp_slash($postArr), true);
            $bucket = 'updated';
        } else {
            $result = wp_insert_post(wp_slash($postArr), true);
            $bucket = 'created';
        }

        // A WP_Error cast to int is 1: never write fields onto post 1.
        if (is_wp_error($result)) {
            $this->warnings[] = "{$label}: write failed: " . $result->get_error_message();
            return ['bucket' => 'skipped', 'label' => $label];
        }
        $postId = (int) $result;
        $this->claimSlug($postId, $slug, $label);

        if (array_key_exists('template', $incoming)) {
            $tpl = (string) ($incoming['template'] ?? '');
            $tpl === '' ? delete_post_meta($postId, '_wp_page_template') : update_post_meta($postId, '_wp_page_template', $tpl);
        }

        $this->writePostFields($postId, $type, is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [], $idMap);
        $this->writePostTerms($postId, is_array($incoming['terms'] ?? null) ? $incoming['terms'] : []);
        if (array_key_exists('featured_media', $incoming) && empty($incoming['featured_media'])) {
            // No featured image at the source: remove this site's.
            if ((int) get_post_thumbnail_id($postId) > 0) {
                delete_post_thumbnail($postId);
            }
        } else {
            $this->assignFeaturedMedia($postId, $incoming['featured_media'] ?? null, $idMap);
        }

        // A reference to a post this run creates later resolves on a second pass.
        $unresolved = $this->refs()->takeUnresolved();
        if (array_intersect_key(array_flip($unresolved), $this->incomingPosts) !== []) {
            $this->secondPass[] = ['op' => $op, 'id' => $postId, 'label' => $label];
        } elseif ($unresolved !== []) {
            $this->warnings[] = "{$label}: references " . implode(', ', $unresolved) . " — not on this site, dropped.";
        }

        return ['bucket' => $bucket, 'label' => $label];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, int>      $idMap
     */
    private function writePostFields(int $postId, string $postType, array $fields, array $idMap): void
    {
        $registered = Metabox::fieldsFor('post', $postType);
        foreach ($fields as $fieldId => $value) {
            // Format 1.2 keys (ADR-0008): a bare id is a `_taw_` field, a full
            // meta key is a field with another prefix; 1.0/1.1 keys are bare ids.
            $config = FieldKeys::forKey((string) $fieldId, $registered, self::bareConfigLookup())['config'];
            if (!empty($config['unregistered'])) {
                $this->writeUnregistered('update_post_meta', $postId, $config, $value, "{$postType} field '{$fieldId}'");
                continue;
            }
            $value = FieldCodec::rewriteRefs($config, FieldCodec::decode($config, $value), $this->refs());
            Metabox::writeMeta($postId, $config, $value);
        }
    }

    /**
     * @return callable(string): (array<string, mixed>|null)
     */
    private static function bareConfigLookup(): callable
    {
        return static fn (string $id): ?array => Metabox::get_field_config($id);
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
        $found = ($this->featuredIds[(string) $ref] ?? 0) ?: MediaResolver::findByFilename((string) $ref);
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
            $missing = $this->unresolvedPostRefs($key, $value);
            if ($stored === 0 && $missing !== []) {
                $this->warnings[] = "option:{$key}: '{$missing[0]}' not found — kept as it is.";
                return ['bucket' => 'skipped', 'label' => "option:{$key}"];
            }
        } elseif (in_array($key, self::POST_ID_LIST_OPTIONS, true)) {
            $stored = array_values(array_filter(array_map(
                fn ($ref): int => $this->resolveLocalPostId($ref),
                is_array($value) ? $value : []
            )));
        } else {
            $stored = $this->encodeOptionForStorage($key, $this->rewriteOption($key, $value));
        }

        update_option($key, $stored);

        return ['bucket' => $exists ? 'updated' : 'created', 'label' => "option:{$key}"];
    }

    /**
     * @param array<string, mixed> $op
     * @param array<int, int>      $idMap
     * @return array{bucket: string, label: string}
     */
    private function applyTerm(array $op, string $policy, array $idMap = []): array
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
        // Parents run before their children (see sortByDependencyOrder).
        // None at the source clears it.
        if (array_key_exists('parent', $fields)) {
            $ref = (string) ($fields['parent'] ?? '');
            $parent = $ref === '' ? false : get_term_by('slug', $ref, $taxonomy);
            if ($ref === '' || $parent instanceof \WP_Term) {
                $args['parent'] = $parent instanceof \WP_Term ? (int) $parent->term_id : 0;
            } else {
                $this->warnings[] = "{$label}: parent '{$ref}' not found — " . ($existing ? 'parent left as it was.' : 'left unparented.');
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

        $termId = $existing ? (int) $existing->term_id : (int) $created['term_id'];
        foreach (is_array($fields['meta'] ?? null) ? $fields['meta'] : [] as $metaKey => $value) {
            update_term_meta($termId, (string) $metaKey, wp_slash($this->termMetaValue($value)));
        }
        $registered = Metabox::fieldsFor('term', $taxonomy);
        foreach (is_array($fields['fields'] ?? null) ? $fields['fields'] : [] as $fieldKey => $value) {
            $config = FieldKeys::forKey((string) $fieldKey, $registered, self::bareConfigLookup())['config'];
            if (!empty($config['unregistered'])) {
                $this->writeUnregistered('update_term_meta', $termId, $config, $value, "{$taxonomy} term field '{$fieldKey}'");
                continue;
            }
            Metabox::writeTo(new TermMetaStore(), $termId, $config, FieldCodec::rewriteRefs($config, FieldCodec::decode($config, $value), $this->refs()));
        }

        return ['bucket' => $bucket, 'label' => $label];
    }

    /**
     * @param array<string, mixed> $op
     * @param array<int, int>      $idMap
     * @return array{bucket: string, label: string}
     */
    private function applyUser(array $op, string $policy, array $idMap = []): array
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
        ];
        // No role this site defines: an existing user keeps theirs (an empty
        // role would strip it); a new one gets the site's default role.
        if ($roles !== []) {
            $userData['role'] = $roles[0];
        } elseif ($existingId === 0) {
            $userData['role'] = (string) get_option('default_role', 'subscriber');
        }

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

        $registered = Metabox::fieldsFor('user');
        foreach (is_array($fields['fields'] ?? null) ? $fields['fields'] : [] as $fieldKey => $value) {
            $config = FieldKeys::forKey((string) $fieldKey, $registered, self::bareConfigLookup())['config'];
            if (!empty($config['unregistered'])) {
                $this->writeUnregistered('update_user_meta', $userId, $config, $value, "user field '{$fieldKey}'");
                continue;
            }
            Metabox::writeTo(new UserMetaStore(), $userId, $config, FieldCodec::rewriteRefs($config, FieldCodec::decode($config, $value), $this->refs()));
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
        $explicitOp = $op['op'] ?? 'update';

        if ($explicitOp === 'skip' || $policy === 'skip') {
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $postId = $this->resolveCommentPostId($fields);
        if ($postId === 0) {
            $this->warnings[] = "{$label}: target post not matched — comment skipped.";
            return ['bucket' => 'skipped', 'label' => $label];
        }

        $existing = $this->findExistingComment($postId, $fields);
        if ($explicitOp === 'delete') {
            if ($existing > 0) {
                wp_delete_comment($existing, true);
                $this->touchedCommentPosts[$postId] = true;
                return ['bucket' => 'deleted', 'label' => $label];
            }
            return ['bucket' => 'skipped', 'label' => $label];
        }
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
            'comment_date'         => ($fields['date_gmt'] ?? '') !== '' ? get_date_from_gmt((string) $fields['date_gmt']) : current_time('mysql'),
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
        $wanted = (string) ($fields['content'] ?? '');
        $wantedDate = (string) ($fields['date_gmt'] ?? '');
        $query = [
            'post_id'      => $postId,
            'author_email' => (string) ($fields['author_email'] ?? ''),
            'number'       => 50,
            'status'       => 'all',
        ];
        // Around the comment's own date (a relative '-1 second' would mean
        // "now", and nothing would ever match).
        $at = $wantedDate !== '' ? strtotime($wantedDate . ' UTC') : false;
        if ($at !== false) {
            $query['date_query'] = [[
                'column'    => 'comment_date_gmt',
                'after'     => gmdate('Y-m-d H:i:s', $at - 1),
                'before'    => gmdate('Y-m-d H:i:s', $at + 1),
                'inclusive' => true,
            ]];
        }
        $matches = get_comments($query);
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
        $types = array_values(array_diff(get_post_types(['public' => true]), ['attachment']));
        $found = get_posts([
            'post_name__in'    => [$slug],
            'post_type'        => $types !== [] ? $types : 'any',
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);
        return ($found[0] ?? null) instanceof \WP_Post ? (int) $found[0]->ID : 0;
    }

    /**
     * The local post a comment belongs to: by `(post_type, post_ref)` when
     * the snapshot says the type (1.3+), else by slug across public types.
     *
     * @param array<string, mixed> $fields
     */
    private function resolveCommentPostId(array $fields): int
    {
        $slug = (string) ($fields['post_ref'] ?? '');
        $type = (string) ($fields['post_type'] ?? '');
        if ($type !== '' && $slug !== '') {
            $post = $this->findPost($type, $slug);
            return $post ? (int) $post->ID : 0;
        }
        return $this->resolveAnyPostId($slug);
    }

    /**
     * Give the post the slug the snapshot says. Media is sideloaded first,
     * and an attachment titled like a page takes its slug ("about"), so the
     * page would become "about-2" and a new copy would appear on every run.
     * The attachment gets another slug instead.
     */
    private function claimSlug(int $postId, string $slug, string $label): void
    {
        $current = (string) get_post_field('post_name', $postId);
        if ($slug === '' || $current === '' || $current === $slug) {
            return;
        }
        $holder = get_posts([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'post_name__in'    => [$slug],
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ]);
        if ($holder === []) {
            $this->warnings[] = "{$label}: saved as '{$current}' (the slug is taken).";
            return;
        }
        wp_update_post(['ID' => (int) $holder[0], 'post_name' => $slug . '-media']);
        wp_update_post(['ID' => $postId, 'post_name' => $slug]);
    }

    /**
     * A field this site doesn't register: written as the snapshot has it
     * (the exporter kept it raw), never through a guessed field type that
     * would mangle it.
     *
     * @param callable(int, string, mixed): mixed $update update_{post,term,user}_meta
     * @param array<string, mixed>                $config
     */
    private function writeUnregistered(callable $update, int $id, array $config, mixed $value, string $what): void
    {
        $update($id, (string) $config['meta_key'], wp_slash(FieldCodec::rewriteStrings($value, $this->refs())));
        $this->warnings[] = "{$what} isn't registered on this site — written as it came (URLs mapped).";
    }

    /**
     * A real date, or '' for an empty or zero one (drafts have no GMT date).
     */
    private static function realDate(mixed $date): string
    {
        $date = is_string($date) ? trim($date) : '';
        return ($date === '' || str_starts_with($date, '0000-00-00')) ? '' : $date;
    }

    /**
     * @param array<string, mixed> $incoming The full incoming post record (for the slug-less composite match).
     * @param string               $path     A hierarchical post's path (1.5): matched first.
     */
    private function findPost(string $type, string $slug, string $matchKey = '', array $incoming = [], string $path = ''): ?\WP_Post
    {
        if ($type === '') {
            return null;
        }

        if ($path !== '' && is_post_type_hierarchical($type)) {
            $found = get_page_by_path($path, 'OBJECT', $type);
            if ($found instanceof \WP_Post) {
                return $found;
            }
            // A page of the input that moved: paired up front, for the
            // whole input at once (see matchMovedPosts()).
            if (isset($this->incomingPaths[$type . ':' . $path])) {
                $moved = $this->movedPosts[$type . ':' . $path] ?? 0;
                $post = $moved > 0 ? get_post($moved) : null;
                return $post instanceof \WP_Post && $post->post_type === $type ? $post : null;
            }
            // A reference outside the input (a parent, a `refs` entry, an
            // older snapshot's slug): the one post this site has under the
            // slug, unless it's another record of the input.
            $candidates = $slug === '' ? [] : get_posts([
                'post_type'        => $type,
                'post_name__in'    => [$slug],
                'post_status'      => 'any',
                'posts_per_page'   => 2,
                'suppress_filters' => false,
                'no_found_rows'    => true,
            ]);
            if (count($candidates) === 1 && !isset($this->incomingPaths[$type . ':' . get_page_uri($candidates[0])])) {
                return $candidates[0];
            }
            return null;
        }

        if ($slug !== '') {
            // `post_name__in`, not `name`: a `name` query is singular, and
            // WP_Query drops a draft/private post from a singular result when
            // the current user can't edit it — bin/taw content:import runs
            // with no user, so such posts never matched and were re-created.
            $matches = get_posts([
                'post_type'        => $type,
                'post_name__in'    => [$slug],
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
        $dateGmt = self::realDate($incoming['date'] ?? null);
        foreach (get_posts([
            'post_type'        => $type,
            'post_status'      => ['draft', 'pending', 'auto-draft'],
            'title'            => $title,
            'posts_per_page'   => 20,
            'suppress_filters' => false,
            'no_found_rows'    => true,
        ]) as $candidate) {
            // 1.3 keys a draft by its local date (drafts have no GMT date);
            // older snapshots used the empty GMT date.
            $keys = [Exporter::draftKey($type, (string) $candidate->post_title, (string) $candidate->post_date),
                     sha1($type . '|' . $candidate->post_title . '|' . $candidate->post_date_gmt)];
            if (in_array($matchKey, $keys, true) || ($title !== '' && $dateGmt !== '' && $candidate->post_title === $title && $candidate->post_date_gmt === $dateGmt)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * A post's parent by its reference: the parent's path (1.5), or its
     * slug (older snapshots, and non-hierarchical types).
     */
    private function findParent(string $type, string $ref): ?\WP_Post
    {
        $slug = ($pos = strrpos($ref, '/')) === false ? $ref : substr($ref, $pos + 1);

        return $this->findPost($type, $slug, '', [], $ref);
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
            'parent'         => $post->post_parent && ($parent = get_post($post->post_parent)) instanceof \WP_Post
                ? (is_post_type_hierarchical((string) $post->post_type) ? (string) get_page_uri($parent) : (string) $parent->post_name)
                : '',
            'password'       => (string) $post->post_password,
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
            'post_name__in'    => [(string) $ref],
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
    /**
     * The post references of a post-ID option (`page_on_front`,
     * `sticky_posts`…) that don't resolve on this site; with $orIncoming,
     * those the import brings count as resolved (posts run before options).
     *
     * @return list<string>
     */
    private function unresolvedPostRefs(string $key, mixed $value, bool $orIncoming = false): array
    {
        if (in_array($key, self::POST_ID_OPTIONS, true)) {
            $refs = [$value];
        } elseif (in_array($key, self::POST_ID_LIST_OPTIONS, true)) {
            $refs = is_array($value) ? $value : [];
        } else {
            return [];
        }
        $missing = [];
        foreach ($refs as $ref) {
            if ($ref === null || $ref === '' || $ref === 0 || $ref === '0' || !is_scalar($ref) || $this->resolveLocalPostId($ref) > 0) {
                continue;
            }
            $ref = (string) $ref;
            if ($orIncoming && (isset($this->incomingPosts["page:{$ref}"]) || isset($this->incomingPosts["post:{$ref}"]))) {
                continue;
            }
            $missing[] = $ref;
        }

        return $missing;
    }

    private function optionCompareKey(string $key, mixed $value): string
    {
        if (in_array($key, self::POST_ID_OPTIONS, true)) {
            return 'pid:' . $this->resolveLocalPostId($value);
        }

        if (in_array($key, self::POST_ID_LIST_OPTIONS, true)) {
            $ids = array_values(array_filter(array_map(fn ($ref): int => $this->resolveLocalPostId($ref), is_array($value) ? $value : [])));
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
        // Not a registered TAW option: store it as the source had it (an
        // array option stays an array, not a JSON string).
        return $value;
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

    /** The private folder for rollback snapshots and journals ('' when it can't be made). */
    private static function privateDir(): string
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit((string) $uploads['basedir']) . 'taw-private';

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return '';
        }
        foreach (['.htaccess' => "Require all denied\nDeny from all\n", 'index.php' => "<?php\n// Silence is golden.\n"] as $guard => $body) {
            $path = $dir . '/' . $guard;
            if (!file_exists($path)) {
                file_put_contents($path, $body);
            }
        }

        return $dir;
    }

    /** The rollback snapshot's path, or null when it couldn't be written. */
    private function writeRollbackSnapshot(): ?string
    {
        $dir = self::privateDir();
        if ($dir === '') {
            return null;
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

        $json = wp_json_encode((new Exporter())->snapshot($rollbackScope), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $path = $dir . '/rollback-' . gmdate('Ymd-His') . '.json';

        return is_string($json) && @file_put_contents($path, $json) !== false ? $path : null;
    }

    /** A new journal file (proving the folder is writable), or null. */
    private function openJournal(): ?string
    {
        $dir = self::privateDir();
        if ($dir === '') {
            return null;
        }
        $path = $dir . '/' . self::JOURNAL_PREFIX . gmdate('Ymd-His') . '.json';
        for ($n = 2; file_exists($path); $n++) {
            $path = $dir . '/' . self::JOURNAL_PREFIX . gmdate('Ymd-His') . "-{$n}.json";
        }

        return @file_put_contents($path, '{"entries":[]}') !== false ? $path : null;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed>       $input
     */
    private function writeJournal(string $path, array $entries, array $input): ?string
    {
        $json = wp_json_encode([
            'taw_import_journal' => 1,
            'created_at'         => gmdate('c'),
            'source'             => $input['meta']['source']['url'] ?? ($input['taw_changeset']['target']['url'] ?? null),
            'entries'            => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) && @file_put_contents($path, $json) !== false ? $path : null;
    }

    public const JOURNAL_PREFIX = 'import-';

    /**
     * The newest import journal not yet undone, as `{path, entries, created_at, source}`.
     *
     * @return array{path: string, entries: list<array<string, mixed>>, created_at: string, source: ?string}|null
     */
    public static function latestJournal(): ?array
    {
        $dir = self::privateDir();
        $files = $dir === '' ? [] : (glob($dir . '/' . self::JOURNAL_PREFIX . '*.json') ?: []);
        rsort($files);

        return $files === [] ? null : self::readJournal($files[0]);
    }

    /**
     * @return array{path: string, entries: list<array<string, mixed>>, created_at: string, source: ?string}|null
     */
    public static function readJournal(string $journal): ?array
    {
        $dir = realpath(self::privateDir());
        $candidate = str_contains($journal, '/') ? $journal : self::privateDir() . '/' . $journal;
        $real = realpath($candidate);
        if ($dir === false || $real === false || dirname($real) !== $dir || !str_starts_with(basename($real), self::JOURNAL_PREFIX)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($real), true);
        if (!is_array($data) || !is_array($data['entries'] ?? null)) {
            return null;
        }

        return ['path' => $real, 'entries' => array_values($data['entries']), 'created_at' => (string) ($data['created_at'] ?? ''), 'source' => $data['source'] ?? null];
    }

    /**
     * Reverse an import from its journal (the newest when none is named).
     * Values edited since are kept; the journal is renamed `undone-…`.
     *
     * @return array{restored: int, deleted: int, recreated: int, kept: list<string>, journal: ?string, error: ?string}
     */
    public function undo(string $journal = ''): array
    {
        $found = $journal === '' ? self::latestJournal() : self::readJournal($journal);
        if ($found === null) {
            return ['restored' => 0, 'deleted' => 0, 'recreated' => 0, 'kept' => [], 'journal' => null,
                'error' => $journal === '' ? 'No import to undo.' : "That isn't an import journal in uploads/taw-private: {$journal}"];
        }

        $kses = function_exists('kses_remove_filters') && has_filter('content_save_pre', 'wp_filter_post_kses') !== false;
        if ($kses) {
            kses_remove_filters();
        }
        try {
            $result = ImportJournal::undo($found['entries'], new WpRecords());
        } finally {
            if ($kses) {
                kses_init_filters();
            }
        }
        rename($found['path'], dirname($found['path']) . '/undone-' . basename($found['path']));

        return $result + ['journal' => $found['path'], 'error' => null];
    }
}
