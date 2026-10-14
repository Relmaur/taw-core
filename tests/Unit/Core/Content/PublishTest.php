<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use TAW\Core\Content\Importer;
use TAW\Core\Content\Publish;
use TAW\Core\Content\PublishError;
use TAW\Core\Log\Logger;
use TAW\Core\Log\LogSinkInterface;
use TAW\Tests\TestCase;

/**
 * The publish steps (plan, stage, apply, undo) around a fake importer: the
 * token, the review's hashes, conflicts, files and the pending folder.
 */
final class PublishTest extends TestCase
{
    private string $dir;
    private int $now = 1_000_000;
    private FakePublishImporter $importer;

    /** @var list<string> */
    private array $logged = [];

    private const IMAGE = 'pretend these are the bytes of hero.jpg';

    private const FAVICON = 'pretend these are the bytes of favicon.avif';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-publish-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        $this->importer = new FakePublishImporter($this->records());
        $logged = &$this->logged;
        Logger::setSinks(new class ($logged) implements LogSinkInterface {
            /** @param list<string> $codes */
            public function __construct(private array &$codes)
            {
            }

            public function write(array $entry): void
            {
                $this->codes[] = (string) $entry['code'];
            }
        });
    }

    protected function tearDown(): void
    {
        Logger::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function publish(array $limits = []): Publish
    {
        return new Publish($limits, $this->dir, fn (): Importer => $this->importer, fn (): int => $this->now);
    }

    /** @return list<array<string, mixed>> */
    private function records(string $heroNew = 'Hello'): array
    {
        $hero = ['op' => 'update', 'changes' => [
            'fields.hero_heading' => ['status' => 'changed', 'old' => 'Hi', 'new' => $heroNew, 'conflict' => false, 'local_changed' => true],
            'title'               => ['status' => 'changed', 'old' => 'News, edited', 'new' => 'News', 'conflict' => true, 'local_changed' => false],
            'fields.subtitle'     => ['status' => 'changed', 'old' => 'Client', 'new' => 'Local', 'conflict' => true, 'local_changed' => true],
        ]];

        return [
            ['kind' => 'media', 'key' => '2026/10/hero.jpg', 'op' => 'create', 'file' => '2026/10/hero.jpg', 'sha256' => hash('sha256', self::IMAGE),
                'bytes' => strlen(self::IMAGE), 'mime' => 'image/jpeg', 'changes' => ['file' => ['status' => 'new', 'new' => 'http://x.local/hero.jpg']],
                'record_key' => 'media:2026/10/hero.jpg', 'hash' => 'm'],
            $hero + ['kind' => 'post', 'type' => 'post', 'slug' => 'news', 'record_key' => 'post:post:news', 'hash' => Importer::changesHash($hero)],
            ['kind' => 'post', 'type' => 'post', 'slug' => 'same', 'op' => 'update', 'changes' => [], 'record_key' => 'post:post:same', 'hash' => 'x'],
            ['kind' => 'post', 'type' => 'post', 'slug' => 'other', 'op' => 'update', 'changes' => ['title' => ['status' => 'changed', 'old' => 'O', 'new' => 'Other']],
                'record_key' => 'post:post:other', 'hash' => 'o'],
            ['kind' => 'option', 'key' => 'site_icon', 'op' => 'update', 'changes' => ['value' => ['status' => 'changed', 'old' => '', 'new' => 'favicon.avif']],
                'record_key' => 'option:site_icon', 'hash' => 'i'],
            ['kind' => 'media', 'key' => '2026/09/favicon.avif', 'op' => 'create', 'file' => '2026/09/favicon.avif', 'sha256' => hash('sha256', self::FAVICON),
                'bytes' => strlen(self::FAVICON), 'mime' => 'image/avif', 'changes' => ['file' => ['status' => 'new', 'new' => 'http://x.local/favicon.avif']],
                'record_key' => 'media:2026/09/favicon.avif', 'hash' => 'f'],
            ['kind' => 'media', 'key' => '2026/09/shot.webp', 'op' => 'update', 'changes' => ['alt' => ['status' => 'new', 'old' => '', 'new' => 'A screenshot']],
                'record_key' => 'media:2026/09/shot.webp', 'hash' => 's'],
        ];
    }

    /** @return array<string, mixed> a media entry as content:diff carries it */
    private static function mediaEntry(string $path, string $bytes = ''): array
    {
        return ['filename' => basename($path), 'url' => 'http://source.local/wp-content/uploads/' . $path]
            + ($bytes !== '' ? ['file' => $path, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)] : []);
    }

    /** @return array<string, mixed> */
    private function changeSet(): array
    {
        return ['taw_changeset' => ['schema' => '1.8', 'base_fingerprint' => 'b', 'target' => ['url' => 'http://x.local']], 'operations' => [
            ['op' => 'update', 'target' => ['kind' => 'post', 'type' => 'post', 'slug' => 'news'], 'post' => ['featured_media' => 'hero.jpg']],
            ['op' => 'update', 'target' => ['kind' => 'post', 'type' => 'post', 'slug' => 'other'], 'post' => ['content' => '<img src="http://source.local/wp-content/uploads/2026/09/shot.webp">']],
            ['op' => 'update', 'target' => ['kind' => 'user', 'key' => 'ada'], 'fields' => []],
            ['op' => 'update', 'target' => ['kind' => 'option', 'key' => 'permalink_structure'], 'fields' => ['value' => '/%postname%/']],
            ['op' => 'update', 'target' => ['kind' => 'option', 'key' => '_taw_phone'], 'fields' => ['value' => '1']],
            ['op' => 'update', 'target' => ['kind' => 'option', 'key' => 'site_icon'], 'fields' => ['value' => 'favicon.avif']],
        ], 'media' => [
            self::mediaEntry('2026/10/hero.jpg', self::IMAGE),
            self::mediaEntry('2026/09/favicon.avif', self::FAVICON),
            self::mediaEntry('2026/09/shot.webp'),
        ]];
    }

    private function hashOf(array $plan, string $key): string
    {
        foreach ($plan['plan']['records'] as $record) {
            if ($record['record_key'] === $key) {
                return $record['hash'];
            }
        }
        $this->fail("no {$key}");
    }

    public function test_plan_keeps_content_only_and_asks_for_the_missing_files(): void
    {
        $plan = $this->publish()->plan($this->changeSet());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $plan['publish_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $plan['token']);
        $kinds = array_map(static fn (array $op): string => $op['target']['kind'] . ':' . ($op['target']['key'] ?? $op['target']['slug']), $this->importer->planned[0]['operations']);
        $this->assertSame(['post:news', 'post:other', 'option:_taw_phone', 'option:site_icon'], $kinds, 'no users, no site settings');
        $this->assertStringContainsString('Users are never published', implode(' ', $plan['plan']['warnings']));
        $this->assertSame(['media:2026/10/hero.jpg', 'post:post:news', 'post:post:other', 'option:site_icon', 'media:2026/09/favicon.avif', 'media:2026/09/shot.webp'], array_column($plan['plan']['records'], 'record_key'), 'unchanged records left out');
        $this->assertSame(['2026/10/hero.jpg', '2026/09/favicon.avif'], array_column($plan['media']['needed'], 'file'));

        $state = (string) file_get_contents($this->dir . '/publish-' . $plan['publish_id'] . '/plan.json');
        $this->assertStringNotContainsString($plan['token'], $state, 'only the token\'s hash is kept');
        $this->assertContains('content.publish.planned', $this->logged);
    }

    public function test_a_snapshot_is_not_a_publish(): void
    {
        try {
            $this->publish()->plan(['meta' => [], 'posts' => []]);
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_invalid', $e->errorCode);
        }
    }

    public function test_limits_refuse_a_publish_too_large(): void
    {
        try {
            $this->publish(['max_file_bytes' => 10])->plan($this->changeSet());
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_too_large', $e->errorCode);
            $this->assertSame(413, $e->status);
        }
    }

    public function test_only_the_files_asked_for_are_accepted(): void
    {
        $plan = $this->publish()->plan($this->changeSet());
        try {
            $this->publish()->stage($plan['publish_id'], 'something else');
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_media_unexpected', $e->errorCode);
        }

        $staged = $this->publish()->stage($plan['publish_id'], self::IMAGE);

        $this->assertSame('2026/10/hero.jpg', $staged['staged']['file']);
        $this->assertSame(1, $staged['remaining'], 'the favicon, until a review leaves site_icon out');
        $this->assertFileExists($this->dir . '/publish-' . $plan['publish_id'] . '/media/' . hash('sha256', self::IMAGE));
    }

    public function test_apply_imports_what_was_approved_once(): void
    {
        $publish = $this->publish();
        $plan = $publish->plan($this->changeSet());
        $publish->stage($plan['publish_id'], self::IMAGE);
        $approved = [['key' => 'post:post:news', 'hash' => $this->hashOf($plan, 'post:post:news')]];

        $result = $publish->apply($plan['publish_id'], $plan['token'], $approved, [], ['post:post:news' => ['fields.subtitle']]);

        $this->assertSame(['post:post:news', 'media:2026/10/hero.jpg'], $result['applied'], 'the file the approved post uses goes with it');
        [$input, $options] = $this->importer->applied[0];
        $this->assertSame(['news'], array_map(static fn (array $op): string => $op['target']['slug'], $input['operations']), 'only what was approved');
        $this->assertSame(['hero.jpg'], array_column($input['media'], 'filename'), 'only the media the approved records use');
        $this->assertSame(['post:post:news' => ['title']], $options['keep'], "production's own edit of the title stays");
        $this->assertSame('update', $options['policy']);
        $this->assertSame([hash('sha256', self::IMAGE)], array_map('basename', array_values($options['media'])));
        $this->assertDirectoryExists($this->dir . '/published-' . $plan['publish_id']);
        $this->assertContains('content.publish.applied', $this->logged);

        try {
            $publish->apply($plan['publish_id'], $plan['token'], $approved);
            $this->fail('applied twice');
        } catch (PublishError $e) {
            $this->assertSame('publish_token_invalid', $e->errorCode);
        }
    }

    public function test_a_wrong_token_is_refused_and_writes_nothing(): void
    {
        $plan = $this->publish()->plan($this->changeSet());

        try {
            $this->publish()->apply($plan['publish_id'], str_repeat('0', 32), [['key' => 'post:post:news', 'hash' => 'h']]);
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_token_invalid', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
        $this->assertSame([], $this->importer->applied);
    }

    public function test_a_record_changed_since_the_review_is_refused(): void
    {
        $publish = $this->publish();
        $plan = $publish->plan($this->changeSet());
        $publish->stage($plan['publish_id'], self::IMAGE);
        $this->importer->records = $this->records('Hello again'); // production or the input moved

        try {
            $publish->apply($plan['publish_id'], $plan['token'], [['key' => 'post:post:news', 'hash' => $this->hashOf($plan, 'post:post:news')]], [], ['post:post:news' => ['fields.subtitle']]);
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_moved', $e->errorCode);
            $this->assertSame('post:post:news', $e->data['moved'][0]['key']);
        }
        $this->assertSame([], $this->importer->applied);

        // The token wasn't spent: with the review redone it goes through.
        $this->importer->records = $this->records();
        $publish->apply($plan['publish_id'], $plan['token'], [['key' => 'post:post:news', 'hash' => $this->hashOf($plan, 'post:post:news')]], [], ['post:post:news' => ['fields.subtitle']]);
        $this->assertCount(1, $this->importer->applied);
    }

    public function test_a_value_both_sides_changed_must_be_decided(): void
    {
        $publish = $this->publish();
        $plan = $publish->plan($this->changeSet());
        $publish->stage($plan['publish_id'], self::IMAGE);

        try {
            $publish->apply($plan['publish_id'], $plan['token'], [['key' => 'post:post:news', 'hash' => $this->hashOf($plan, 'post:post:news')]]);
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_conflicts_unresolved', $e->errorCode);
            $this->assertSame([['key' => 'post:post:news', 'change' => 'fields.subtitle']], $e->data['conflicts']);
        }
    }

    public function test_every_file_must_be_uploaded_first(): void
    {
        $publish = $this->publish();
        $plan = $publish->plan($this->changeSet());

        try {
            $publish->apply($plan['publish_id'], $plan['token'], [['key' => 'post:post:news', 'hash' => $this->hashOf($plan, 'post:post:news')]], ['post:post:news' => ['fields.subtitle']]);
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_media_missing', $e->errorCode);
            $this->assertSame(['2026/10/hero.jpg'], $e->data['missing']);
        }
    }

    public function test_a_publish_expires_and_is_swept(): void
    {
        $plan = $this->publish()->plan($this->changeSet());
        $this->now += 3601;

        try {
            $this->publish()->stage($plan['publish_id'], self::IMAGE);
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_expired', $e->errorCode);
        }
        $this->publish()->sweep();
        $this->assertDirectoryDoesNotExist($this->dir . '/publish-' . $plan['publish_id']);
        $this->assertContains('content.publish.swept', $this->logged);
    }

    public function test_an_id_is_never_a_path(): void
    {
        try {
            $this->publish()->stage('../../wp-config', 'x');
            $this->fail('accepted');
        } catch (PublishError $e) {
            $this->assertSame('publish_unknown', $e->errorCode);
        }
    }

    /** #215: emelambda's publish of one post also wrote 13 media updates and site_icon's file. */
    public function test_media_nobody_approved_stays_out(): void
    {
        $publish = $this->publish();
        $plan = $publish->plan($this->changeSet());
        $publish->stage($plan['publish_id'], self::IMAGE); // the favicon is never uploaded

        $result = $publish->apply($plan['publish_id'], $plan['token'], [['key' => 'post:post:news', 'hash' => $this->hashOf($plan, 'post:post:news')]], [], ['post:post:news' => ['fields.subtitle']]);

        [$input, $options] = $this->importer->applied[0];
        $this->assertNotContains('favicon.avif', array_column($input['media'], 'filename'), "site_icon wasn't approved: its file doesn't go");
        $this->assertNotContains('shot.webp', array_column($input['media'], 'filename'));
        $this->assertSame([hash('sha256', self::IMAGE)], array_map('basename', array_values($options['media'])), 'only the files written are asked for');
        $this->assertNotContains('media:2026/09/favicon.avif', $result['applied']);
    }

    public function test_media_a_record_uses_keeps_its_metadata_unless_approved(): void
    {
        $publish = $this->publish();
        $plan = $publish->plan($this->changeSet());
        $other = [['key' => 'post:post:other', 'hash' => $this->hashOf($plan, 'post:post:other')]];

        $publish->apply($plan['publish_id'], $plan['token'], $other);

        [$input, $options] = $this->importer->applied[0];
        $this->assertSame(['shot.webp'], array_column($input['media'], 'filename'), 'the file other links to maps through');
        $this->assertSame(['alt'], $options['keep']['media:2026/09/shot.webp'] ?? null, 'its alt text is not part of the approval');

        $this->importer->applied = [];
        $again = $this->publish()->plan($this->changeSet());
        $this->publish()->apply($again['publish_id'], $again['token'], [['key' => 'media:2026/09/shot.webp', 'hash' => $this->hashOf($again, 'media:2026/09/shot.webp')]]);
        [$input, $options] = $this->importer->applied[0];
        $this->assertSame([], $input['operations']);
        $this->assertSame(['shot.webp'], array_column($input['media'], 'filename'), 'a media record approved on its own');
        $this->assertArrayNotHasKey('media:2026/09/shot.webp', $options['keep']);
    }

    public function test_decide_keeps_what_only_this_site_changed(): void
    {
        $records = [['record_key' => 'r', 'changes' => [
            'a' => ['conflict' => true, 'local_changed' => false],
            'b' => ['conflict' => true, 'local_changed' => true],
            'c' => ['conflict' => null, 'local_changed' => true],
            'd' => ['conflict' => false, 'local_changed' => true],
            'e' => ['status' => 'new'],
        ]]];

        $this->assertSame([['r' => ['a']], [['key' => 'r', 'change' => 'b'], ['key' => 'r', 'change' => 'c']]], Publish::decide($records, [], []));
        $this->assertSame([['r' => ['b']], []], Publish::decide($records, ['r' => ['b']], ['r' => ['a', 'c']]), 'taking the incoming value is a decision too');
    }
}

/** An importer that answers a canned plan and records what it's asked to apply. */
final class FakePublishImporter extends Importer
{
    /** @var list<array<string, mixed>> */
    public array $planned = [];

    /** @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public array $applied = [];

    /** @param list<array<string, mixed>> $records */
    public function __construct(public array $records)
    {
    }

    public function plan(array $input, array $options = []): array
    {
        $this->planned[] = $input;
        $paths = array_map(static fn (array $m): string => \TAW\Core\Content\MediaResolver::uploadsPath((string) $m['url']), is_array($input['media'] ?? null) ? $input['media'] : []);
        $keys = array_map(static fn (array $op): string => Importer::keyOf($op), is_array($input['operations'] ?? null) ? $input['operations'] : []);
        $records = array_values(array_filter($this->records, static fn (array $r): bool => ($r['kind'] ?? '') === 'media'
            ? in_array($r['key'], $paths, true)
            : in_array($r['record_key'], $keys, true) || $r['changes'] === []));

        return ['registry_drift' => [], 'records' => $records, 'warnings' => []];
    }

    public function apply(array $input, array $options = []): array
    {
        $this->applied[] = [$input, $options];

        return ['created' => [], 'updated' => ['post:news'], 'skipped' => [], 'deleted' => [], 'failed' => [], 'media_sideloaded' => 1,
            'warnings' => [], 'rollback_path' => '/tmp/rollback.json', 'journal' => '/tmp/import-1.json', 'error' => null];
    }
}
