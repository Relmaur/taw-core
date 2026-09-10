<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use TAW\Core\Content\ChangeSet;
use TAW\Tests\TestCase;

/**
 * ChangeSet::between() is pure — it turns two snapshots into a list of
 * create/update/delete operations, matching records by natural key
 * (posts by type+slug, options by key, terms by taxonomy+slug).
 */
final class ChangeSetTest extends TestCase
{
    /**
     * @param array<string, mixed> $posts
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function snapshot(array $posts = [], array $options = [], array $terms = []): array
    {
        return ['meta' => ['schema' => '1.0'], 'posts' => $posts, 'options' => $options, 'terms' => $terms];
    }

    public function test_detects_created_updated_and_deleted_posts(): void
    {
        $base = $this->snapshot([
            ['type' => 'page', 'slug' => 'about', 'title' => 'About', 'fields' => []],
            ['type' => 'page', 'slug' => 'gone', 'title' => 'Gone', 'fields' => []],
        ]);
        $target = $this->snapshot([
            ['type' => 'page', 'slug' => 'about', 'title' => 'About Us', 'fields' => []],
            ['type' => 'page', 'slug' => 'new', 'title' => 'New', 'fields' => []],
        ]);

        $ops = ChangeSet::between($base, $target)['operations'];

        $byKey = [];
        foreach ($ops as $op) {
            $byKey[$op['target']['slug'] ?? $op['target']['key'] ?? ''] = $op['op'];
        }

        $this->assertSame('update', $byKey['about']);
        $this->assertSame('create', $byKey['new']);
        $this->assertSame('delete', $byKey['gone']);
    }

    public function test_unchanged_records_produce_no_operations(): void
    {
        $snap = $this->snapshot(
            [['type' => 'page', 'slug' => 'about', 'title' => 'About', 'fields' => ['x' => [1, 2]]]],
            ['_taw_phone' => '555']
        );

        $this->assertSame([], ChangeSet::between($snap, $snap)['operations']);
    }

    public function test_comparison_is_key_order_insensitive(): void
    {
        $base = $this->snapshot([['type' => 'page', 'slug' => 'a', 'fields' => ['one' => 1, 'two' => 2]]]);
        $target = $this->snapshot([['type' => 'page', 'slug' => 'a', 'fields' => ['two' => 2, 'one' => 1]]]);

        $this->assertSame([], ChangeSet::between($base, $target)['operations']);
    }

    public function test_option_create_and_update(): void
    {
        $base = $this->snapshot([], ['_taw_a' => '1']);
        $target = $this->snapshot([], ['_taw_a' => '2', '_taw_b' => 'new']);

        $ops = ChangeSet::between($base, $target)['operations'];
        $this->assertCount(2, $ops);

        $map = [];
        foreach ($ops as $op) {
            $map[$op['target']['key']] = [$op['op'], $op['fields']['value'] ?? null];
        }
        $this->assertSame(['update', '2'], $map['_taw_a']);
        $this->assertSame(['create', 'new'], $map['_taw_b']);
    }

    public function test_diffs_the_users_section(): void
    {
        $base = ['meta' => ['schema' => '1.1'], 'users' => [
            ['login' => 'ada', 'email' => 'ada@x.test', 'display_name' => 'Ada'],
        ]];
        $target = ['meta' => ['schema' => '1.1'], 'users' => [
            ['login' => 'ada', 'email' => 'ada@x.test', 'display_name' => 'Ada Lovelace'],
            ['login' => 'grace', 'email' => 'grace@x.test', 'display_name' => 'Grace'],
        ]];

        $ops = ChangeSet::between($base, $target)['operations'];
        $map = [];
        foreach ($ops as $op) {
            $map[$op['target']['key']] = $op['op'];
        }

        $this->assertSame('update', $map['ada']);
        $this->assertSame('create', $map['grace']);
        $this->assertSame('user', $ops[0]['target']['kind']);
    }

    public function test_diffs_the_comments_section(): void
    {
        $c = static fn (string $body): array => ['post_ref' => 'about', 'author_email' => 'x@x.test', 'date_gmt' => '2026-01-01 00:00:00', 'content' => $body];
        $base = ['meta' => ['schema' => '1.1'], 'comments' => [$c('hi')]];
        $target = ['meta' => ['schema' => '1.1'], 'comments' => [$c('hi'), $c('second')]];

        $ops = ChangeSet::between($base, $target)['operations'];

        $this->assertCount(1, $ops);
        $this->assertSame('create', $ops[0]['op']);
        $this->assertSame('comment', $ops[0]['target']['kind']);
    }

    public function test_slugless_posts_match_on_the_composite_key(): void
    {
        $draft = ['type' => 'post', 'slug' => '', 'match_key' => 'abc123', 'title' => 'Note', 'fields' => []];
        $snap = ['meta' => ['schema' => '1.1'], 'posts' => [$draft], 'options' => [], 'terms' => []];

        $this->assertSame([], ChangeSet::between($snap, $snap)['operations'], 'a slug-less post keyed on match_key is stable');
    }

    public function test_term_diff_by_taxonomy_and_slug(): void
    {
        $base = $this->snapshot([], [], ['category' => [['slug' => 'news', 'name' => 'News']]]);
        $target = $this->snapshot([], [], ['category' => [['slug' => 'news', 'name' => 'Latest News']]]);

        $ops = ChangeSet::between($base, $target)['operations'];

        $this->assertCount(1, $ops);
        $this->assertSame('update', $ops[0]['op']);
        $this->assertSame('term', $ops[0]['target']['kind']);
        $this->assertSame('category', $ops[0]['target']['type']);
        $this->assertSame('news', $ops[0]['target']['slug']);
    }
}
