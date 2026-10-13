<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: see Exporter.

/**
 * Publishing content to this site from another (taw-fleet's Local → production),
 * in steps a person reviews:
 *
 * 1. {@see self::plan()} takes a change-set (what changed at the source since
 *    the base, with the base: {@see ChangeSet::between()} `with_base`), keeps
 *    only content (no users, comments or environment settings), and answers
 *    the field-level plan, the media files this site hasn't got, and a
 *    one-time token. Nothing is written but the pending folder.
 * 2. {@see self::stage()} takes each missing file's bytes; a file is accepted
 *    only when its sha256 is one the plan asked for, and is stored under it.
 * 3. {@see self::apply()} takes the token, the records approved with the hash
 *    each had in the plan, and per conflicting value which side wins. It plans
 *    the approved records again and refuses if any moved since the review,
 *    if a conflict wasn't decided or a file is missing; then imports them
 *    (rollback snapshot and journal first) and spends the token.
 * 4. {@see self::undo()} reverses an import from its journal; values edited
 *    since are kept.
 *
 * The pending folder is `uploads/taw-private/publish-<id>/`, renamed
 * `published-<id>/` once applied; pending ones expire after the TTL.
 * Every step is logged (`content.publish.*`).
 */
final class Publish
{
    public const PENDING = 'publish-';
    public const DONE = 'published-';

    /** Defaults; a caller (the companion) may lower or raise them. */
    public const LIMITS = [
        'max_file_bytes'  => 32 * 1024 * 1024,
        'max_total_bytes' => 512 * 1024 * 1024,
        'max_files'       => 500,
        'ttl'             => 3600,
    ];

    /** A lock older than this is a run that died. */
    private const LOCK_STALE = 900;

    /** @var array{max_file_bytes: int, max_total_bytes: int, max_files: int, ttl: int} */
    private array $limits;

    private string $dir;

    /** @var \Closure(): int */
    private \Closure $now;

    /** @var \Closure(): Importer */
    private \Closure $importer;

    /**
     * @param array<string, int>       $limits   see {@see self::LIMITS}
     * @param string|null              $dir      the private folder (default: uploads/taw-private)
     * @param (\Closure(): Importer)|null $importer
     * @param (\Closure(): int)|null   $now
     */
    public function __construct(array $limits = [], ?string $dir = null, ?\Closure $importer = null, ?\Closure $now = null)
    {
        $merged = self::LIMITS;
        foreach (self::LIMITS as $key => $default) {
            if (isset($limits[$key]) && (int) $limits[$key] > 0) {
                $merged[$key] = (int) $limits[$key];
            }
        }
        $this->limits = $merged;
        $this->dir = $dir ?? Importer::privateDir();
        $this->importer = $importer ?? static fn (): Importer => new Importer();
        $this->now = $now ?? static fn (): int => time();
    }

    /** @return array{max_file_bytes: int, max_total_bytes: int, max_files: int, ttl: int} */
    public function limits(): array
    {
        return $this->limits;
    }

    /* -----------------------------------------------------------------
     * 1. Plan
     * ----------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $changeSet
     * @return array<string, mixed> `{publish_id, token, expires_at, plan, media: {needed, present}, limits, warnings}`
     */
    public function plan(array $changeSet): array
    {
        if (!is_array($changeSet['taw_changeset'] ?? null) || !is_array($changeSet['operations'] ?? null)) {
            throw new PublishError('publish_invalid', 'A publish takes a change-set (content:diff output), not a snapshot.');
        }
        if ($this->dir === '') {
            throw new PublishError('publish_failed', "uploads/taw-private can't be written.", 500);
        }
        $this->sweep();
        $this->assertNotBusy();

        [$input, $left] = self::contentOnly($changeSet);
        $plan = ($this->importer)()->plan($input);
        $records = array_values(array_filter($plan['records'], static fn (array $r): bool => self::matters($r)));

        $needed = [];
        $total = 0;
        foreach ($records as $record) {
            if (($record['kind'] ?? '') === 'media' && ($record['op'] ?? '') === 'create' && ($record['sha256'] ?? '') !== '') {
                $sha = (string) $record['sha256'];
                if (!isset($needed[$sha])) {
                    $needed[$sha] = ['file' => (string) ($record['file'] ?? $record['key'] ?? ''), 'sha256' => $sha,
                        'bytes' => (int) ($record['bytes'] ?? 0), 'mime' => (string) ($record['mime'] ?? '')];
                    $total += (int) ($record['bytes'] ?? 0);
                }
            }
        }
        if (count($needed) > $this->limits['max_files'] || $total > $this->limits['max_total_bytes']) {
            throw new PublishError('publish_too_large', sprintf(
                'This publish needs %d files (%s); the limit is %d files and %s. Publish fewer pages at a time.',
                count($needed), self::size($total), $this->limits['max_files'], self::size($this->limits['max_total_bytes'])
            ), 413, ['files' => count($needed), 'bytes' => $total]);
        }
        foreach ($needed as $file) {
            if ($file['bytes'] > $this->limits['max_file_bytes']) {
                throw new PublishError('publish_too_large', "{$file['file']} is " . self::size($file['bytes']) . '; the limit per file is ' . self::size($this->limits['max_file_bytes']) . '.', 413, ['file' => $file['file']]);
            }
        }

        $id = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(16));
        $now = ($this->now)();
        $folder = $this->folder($id);
        if (!@mkdir($folder . '/media', 0700, true)) {
            throw new PublishError('publish_failed', "Couldn't make the publish folder in uploads/taw-private.", 500);
        }
        $state = [
            'id'           => $id,
            'token_sha256' => hash('sha256', $token),
            'created_at'   => $now,
            'expires_at'   => $now + $this->limits['ttl'],
            'source'       => $changeSet['taw_changeset']['target']['url'] ?? ($changeSet['meta']['source']['url'] ?? null),
            'hashes'       => array_column(array_map(static fn (array $r): array => [(string) ($r['record_key'] ?? ''), (string) ($r['hash'] ?? '')], $records), 1, 0),
            'needed'       => $needed,
        ];
        self::writeJson($folder . '/input.json', $input);
        self::writeJson($folder . '/plan.json', $state);

        $warnings = array_merge($left, $plan['warnings']);
        self::log('info', 'content.publish.planned', 'Publish planned', ['id' => $id, 'records' => count($records), 'media_needed' => count($needed), 'source' => $state['source']]);

        return [
            'publish_id' => $id,
            'token'      => $token,
            'expires_at' => gmdate('c', $state['expires_at']),
            'plan'       => ['registry_drift' => $plan['registry_drift'], 'records' => $records, 'warnings' => $warnings],
            'media'      => ['needed' => array_values($needed)],
            'limits'     => $this->limits,
        ];
    }

    /**
     * The change-set with only content in it: no users, comments or
     * environment settings ever travel by a publish. Also what was left out.
     *
     * @param array<string, mixed> $changeSet
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function contentOnly(array $changeSet): array
    {
        $kept = [];
        $left = [];
        foreach ($changeSet['operations'] as $op) {
            if (!is_array($op)) {
                continue;
            }
            $kind = (string) ($op['target']['kind'] ?? '');
            $key = (string) ($op['target']['key'] ?? '');
            if (in_array($kind, ['user', 'comment'], true) || ($kind === 'option' && in_array($key, Exporter::SETTINGS_OPTION_ALLOWLIST, true))) {
                $left[$kind] = true;
                continue;
            }
            $kept[] = $op;
        }
        $changeSet['operations'] = $kept;
        $notes = [];
        foreach (array_keys($left) as $kind) {
            $notes[] = match ($kind) {
                'user'    => 'Users are never published: they were left out.',
                'comment' => 'Comments are never published: they were left out.',
                default   => 'Site settings (permalinks, time zone, …) are never published: they were left out.',
            };
        }

        return [$changeSet, $notes];
    }

    /**
     * Whether a plan record is worth showing: it changes something, or
     * would delete a record, or is a file to upload or one missing.
     *
     * @param array<string, mixed> $record
     */
    private static function matters(array $record): bool
    {
        return !empty($record['changes']) || in_array($record['op'] ?? '', ['would-delete', 'missing'], true);
    }

    /* -----------------------------------------------------------------
     * 2. Stage a file
     * ----------------------------------------------------------------- */

    /**
     * @return array{staged: array{file: string, sha256: string, bytes: int}, remaining: int}
     */
    public function stage(string $id, string $bytes): array
    {
        $state = $this->pending($id);
        if (strlen($bytes) > $this->limits['max_file_bytes']) {
            throw new PublishError('publish_too_large', 'That file is ' . self::size(strlen($bytes)) . '; the limit per file is ' . self::size($this->limits['max_file_bytes']) . '.', 413);
        }
        $sha = hash('sha256', $bytes);
        if (!isset($state['needed'][$sha])) {
            throw new PublishError('publish_media_unexpected', "That file isn't one this publish needs (sha256 {$sha}).", 400, ['sha256' => $sha]);
        }
        $path = $this->folder($id) . '/media/' . $sha;
        if (!is_file($path) && @file_put_contents($path, $bytes) === false) {
            throw new PublishError('publish_failed', "Couldn't store the file in uploads/taw-private.", 500);
        }
        @chmod($path, 0600);
        $file = (string) $state['needed'][$sha]['file'];
        self::log('info', 'content.publish.media_staged', 'Publish file uploaded', ['id' => $id, 'file' => $file, 'bytes' => strlen($bytes)]);

        return ['staged' => ['file' => $file, 'sha256' => $sha, 'bytes' => strlen($bytes)], 'remaining' => count($this->missingFiles($id, $state))];
    }

    /* -----------------------------------------------------------------
     * 3. Apply
     * ----------------------------------------------------------------- */

    /**
     * @param list<array{key?: mixed, hash?: mixed}> $approved the records to publish, each with the hash it had in the plan
     * @param array<string, list<string>>           $keep     record key => values left as this site has them
     * @param array<string, list<string>>           $take     record key => conflicting values the incoming side wins
     * @return array{report: array<string, mixed>, applied: list<string>}
     */
    public function apply(string $id, string $token, array $approved, array $keep = [], array $take = []): array
    {
        $state = $this->pending($id);
        if (($state['token_sha256'] ?? '') === '' || !hash_equals((string) $state['token_sha256'], hash('sha256', $token))) {
            self::log('warning', 'content.publish.refused', 'Publish refused: wrong or spent token', ['id' => $id, 'reason' => 'token']);
            throw new PublishError('publish_token_invalid', 'That token is wrong or already used: plan the publish again.', 403);
        }
        $lock = $this->lock();
        try {
            $wanted = [];
            foreach ($approved as $entry) {
                $key = (string) ($entry['key'] ?? '');
                if ($key !== '') {
                    $wanted[$key] = (string) ($entry['hash'] ?? '');
                }
            }
            if ($wanted === []) {
                throw new PublishError('publish_invalid', 'Nothing was approved.');
            }
            $input = json_decode((string) @file_get_contents($this->folder($id) . '/input.json'), true);
            if (!is_array($input)) {
                throw new PublishError('publish_unknown', "That publish's files are gone: plan it again.", 404);
            }
            $input['operations'] = array_values(array_filter(
                is_array($input['operations'] ?? null) ? $input['operations'] : [],
                static fn (mixed $op): bool => is_array($op) && isset($wanted[Importer::keyOf($op)])
            ));

            // What the review saw must be what's written.
            $replan = ($this->importer)()->plan($input);
            $byKey = [];
            foreach ($replan['records'] as $record) {
                $byKey[(string) ($record['record_key'] ?? '')] = $record;
            }
            $moved = [];
            foreach ($wanted as $key => $hash) {
                $now = isset($byKey[$key]) ? (string) ($byKey[$key]['hash'] ?? '') : '';
                if ($now !== $hash) {
                    $moved[] = ['key' => $key, 'was' => $hash, 'now' => $now];
                }
            }
            if ($moved !== []) {
                self::log('notice', 'content.publish.refused', 'Publish refused: records changed since the review', ['id' => $id, 'reason' => 'moved', 'keys' => array_column($moved, 'key')]);
                throw new PublishError('publish_moved', count($moved) . ' of the records changed since the review: plan the publish again.', 409, ['moved' => $moved]);
            }

            [$keep, $unresolved] = self::decide($replan['records'], $keep, $take);
            if ($unresolved !== []) {
                throw new PublishError('publish_conflicts_unresolved', count($unresolved) . ' changed on this site too: say which side wins for each.', 409, ['conflicts' => $unresolved]);
            }
            $missing = $this->missingFiles($id, $state);
            if ($missing !== []) {
                throw new PublishError('publish_media_missing', count($missing) . ' files still have to be uploaded.', 409, ['missing' => $missing]);
            }

            // Spent before anything is written: a second call can't import again.
            $state['token_sha256'] = '';
            self::writeJson($this->folder($id) . '/plan.json', $state);

            $staged = [];
            foreach ($state['needed'] as $sha => $file) {
                $staged[(string) $file['file']] = $this->folder($id) . '/media/' . $sha;
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            $report = ($this->importer)()->apply($input, ['policy' => 'update', 'keep' => $keep, 'media' => $staged]);

            $done = $this->dir . '/' . self::DONE . $id;
            self::writeJson($this->folder($id) . '/report.json', $report + ['applied' => array_keys($wanted), 'keep' => $keep]);
            foreach (glob($this->folder($id) . '/media/*') ?: [] as $file) {
                @unlink($file); // in the media library now
            }
            @rmdir($this->folder($id) . '/media');
            @rename($this->folder($id), $done);

            $failed = ($report['error'] ?? null) !== null;
            self::log($failed ? 'error' : 'info', $failed ? 'content.publish.failed' : 'content.publish.applied', $failed ? 'Publish stopped' : 'Publish applied', [
                'id' => $id, 'created' => count((array) ($report['created'] ?? [])), 'updated' => count((array) ($report['updated'] ?? [])),
                'deleted' => count((array) ($report['deleted'] ?? [])), 'failed' => count((array) ($report['failed'] ?? [])),
                'journal' => isset($report['journal']) ? basename((string) $report['journal']) : null, 'error' => $report['error'] ?? null,
            ]);

            return ['report' => $report, 'applied' => array_keys($wanted)];
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * Which side wins for each conflicting value. A value this site changed
     * since the base while the incoming one didn't stays as this site has it
     * unless `take` says otherwise; a value both changed (or that the base
     * can't tell about) must be in `keep` or `take`.
     *
     * @param list<array<string, mixed>>  $records
     * @param array<string, list<string>> $keep
     * @param array<string, list<string>> $take
     * @return array{0: array<string, list<string>>, 1: list<array{key: string, change: string}>}
     */
    public static function decide(array $records, array $keep, array $take): array
    {
        $unresolved = [];
        foreach ($records as $record) {
            $key = (string) ($record['record_key'] ?? '');
            $kept = array_flip(array_map('strval', $keep[$key] ?? []));
            $taken = array_flip(array_map('strval', $take[$key] ?? []));
            foreach (is_array($record['changes'] ?? null) ? $record['changes'] : [] as $change => $delta) {
                if (!is_array($delta) || !array_key_exists('conflict', $delta) || $delta['conflict'] === false) {
                    continue;
                }
                $change = (string) $change;
                if (isset($kept[$change]) || isset($taken[$change])) {
                    continue;
                }
                if ($delta['conflict'] === true && ($delta['local_changed'] ?? true) === false) {
                    $keep[$key][] = $change; // only this site changed it: it stays
                    continue;
                }
                $unresolved[] = ['key' => $key, 'change' => $change];
            }
        }

        return [$keep, $unresolved];
    }

    /* -----------------------------------------------------------------
     * 4. Undo
     * ----------------------------------------------------------------- */

    /** @return list<array<string, mixed>> */
    public function journals(): array
    {
        return Importer::journals();
    }

    /** @return array<string, mixed> */
    public function undo(string $journal, bool $dryRun): array
    {
        $importer = ($this->importer)();
        $result = $dryRun ? $importer->undoPreview($journal) : $importer->undo($journal);
        if (($result['error'] ?? null) !== null) {
            throw new PublishError('publish_journal_unknown', (string) $result['error'], 404);
        }
        if (!$dryRun) {
            self::log('info', 'content.publish.undone', 'Publish undone', ['journal' => basename((string) $result['journal']),
                'restored' => $result['restored'], 'deleted' => $result['deleted'], 'kept' => count($result['kept'])]);
        }

        return $result;
    }

    /* -----------------------------------------------------------------
     * The pending folder
     * ----------------------------------------------------------------- */

    /** Pending publishes past their time are removed (with their files). */
    public function sweep(): void
    {
        $swept = [];
        foreach (glob($this->dir . '/' . self::PENDING . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            $state = json_decode((string) @file_get_contents($folder . '/plan.json'), true);
            $expires = is_array($state) ? (int) ($state['expires_at'] ?? 0) : 0;
            if ($expires < ($this->now)()) {
                self::removeTree($folder);
                $swept[] = substr(basename($folder), strlen(self::PENDING));
            }
        }
        if ($swept !== []) {
            self::log('info', 'content.publish.swept', 'Expired publishes removed', ['ids' => $swept]);
        }
    }

    /** @return array<string, mixed> */
    private function pending(string $id): array
    {
        if (preg_match('/^[a-f0-9]{16}$/', $id) !== 1) {
            throw new PublishError('publish_unknown', 'No such publish.', 404);
        }
        if (is_dir($this->dir . '/' . self::DONE . $id)) {
            throw new PublishError('publish_token_invalid', 'That publish was applied already: plan a new one.', 403);
        }
        $state = json_decode((string) @file_get_contents($this->folder($id) . '/plan.json'), true);
        if (!is_array($state)) {
            throw new PublishError('publish_unknown', 'No such publish (it may have expired): plan it again.', 404);
        }
        if ((int) ($state['expires_at'] ?? 0) < ($this->now)()) {
            throw new PublishError('publish_expired', 'That publish expired: plan it again.', 410);
        }
        $state['needed'] = is_array($state['needed'] ?? null) ? $state['needed'] : [];

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return list<string> the files not uploaded yet
     */
    private function missingFiles(string $id, array $state): array
    {
        $missing = [];
        foreach ((array) $state['needed'] as $sha => $file) {
            if (!is_file($this->folder($id) . '/media/' . $sha)) {
                $missing[] = (string) (is_array($file) ? ($file['file'] ?? $sha) : $sha);
            }
        }

        return $missing;
    }

    private function folder(string $id): string
    {
        return $this->dir . '/' . self::PENDING . $id;
    }

    private function assertNotBusy(): void
    {
        $lock = $this->dir . '/publish.lock';
        if (is_file($lock) && ($this->now)() - (int) @filemtime($lock) < self::LOCK_STALE) {
            throw new PublishError('publish_busy', 'Another publish is being applied: try again in a minute.', 423);
        }
    }

    private function lock(): string
    {
        $this->assertNotBusy();
        $lock = $this->dir . '/publish.lock';
        @file_put_contents($lock, (string) ($this->now)());
        @touch($lock, ($this->now)());

        return $lock;
    }

    private function unlock(string $lock): void
    {
        @unlink($lock);
    }

    private static function removeTree(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $path) {
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @param array<mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || @file_put_contents($path, $json) === false) {
            throw new PublishError('publish_failed', "Couldn't write " . basename($path) . ' in uploads/taw-private.', 500);
        }
        @chmod($path, 0600);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? round($bytes / 1024 / 1024, 1) . ' MB' : (int) ceil($bytes / 1024) . ' KB';
    }

    /** @param array<string, mixed> $context */
    private static function log(string $level, string $code, string $message, array $context): void
    {
        if (class_exists(\TAW\Core\Log\Logger::class)) {
            \TAW\Core\Log\Logger::log($level, $code, $message, $context);
        }
    }
}
