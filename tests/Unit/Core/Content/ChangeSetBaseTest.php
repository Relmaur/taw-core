<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use PHPUnit\Framework\TestCase;
use TAW\Core\Content\ChangeSet;

/** `ChangeSet::between()` with `with_base` (1.8): each update and delete carries its base record. */
final class ChangeSetBaseTest extends TestCase
{
    /** @return array<string, mixed> */
    private function snapshot(string $title, string $phone, array $extraPosts = []): array
    {
        return [
            'meta'       => ['schema' => '1.8', 'source' => ['url' => 'https://x.test']],
            'posts'      => array_merge([['type' => 'page', 'slug' => 'about', 'title' => $title]], $extraPosts),
            'options'    => ['_taw_phone' => $phone],
            'terms'      => ['category' => [['slug' => 'news', 'name' => $title]]],
            'menus'      => [['slug' => 'main', 'name' => 'Main', 'items' => [['title' => $title]]]],
            'theme_mods' => ['custom_logo' => $phone === '1' ? 3 : 4],
        ];
    }

    public function test_updates_and_deletes_carry_the_base_record(): void
    {
        $base = $this->snapshot('About', '1', [['type' => 'page', 'slug' => 'gone', 'title' => 'Gone']]);
        $target = $this->snapshot('About us', '2');

        $set = ChangeSet::between($base, $target, ['with_base' => true]);
        $byKind = [];
        foreach ($set['operations'] as $op) {
            $byKind[$op['target']['kind'] . ':' . $op['op']][] = $op;
        }

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $set['taw_changeset']['base_fingerprint']);
        $this->assertSame('About', $byKind['post:update'][0]['base']['title']);
        $this->assertSame('About us', $byKind['post:update'][0]['post']['title']);
        $this->assertSame('Gone', $byKind['post:delete'][0]['base']['title'], 'a delete carries what it deletes');
        $this->assertSame(['value' => '1'], $byKind['option:update'][0]['base']);
        $this->assertSame('About', $byKind['term:update'][0]['base']['name']);
        $this->assertSame('About', $byKind['menu:update'][0]['base']['items'][0]['title']);
        $this->assertSame(['value' => 3], $byKind['theme_mod:update'][0]['base']);
    }

    public function test_without_the_option_nothing_changes(): void
    {
        $set = ChangeSet::between($this->snapshot('About', '1'), $this->snapshot('About us', '2'));

        $this->assertArrayNotHasKey('base_fingerprint', $set['taw_changeset']);
        foreach ($set['operations'] as $op) {
            $this->assertArrayNotHasKey('base', $op);
        }
        // and the option doesn't leak into the next call
        ChangeSet::between($this->snapshot('A', '1'), $this->snapshot('B', '2'), ['with_base' => true]);
        $this->assertArrayNotHasKey('base', ChangeSet::between($this->snapshot('A', '1'), $this->snapshot('B', '2'))['operations'][0]);
    }

    public function test_a_create_has_no_base(): void
    {
        $set = ChangeSet::between($this->snapshot('About', '1'), $this->snapshot('About', '1', [['type' => 'page', 'slug' => 'new', 'title' => 'New']]), ['with_base' => true]);

        $this->assertCount(1, $set['operations']);
        $this->assertSame('create', $set['operations'][0]['op']);
        $this->assertArrayNotHasKey('base', $set['operations'][0]);
    }
}
