<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use TAW\Core\Content\ChangeSet;
use TAW\Tests\TestCase;

/**
 * Content Interchange fidelity, phase 6: a change-set carries what an
 * import needs on another site (source, refs, the media its operations
 * reference), and deletes nothing a scoped snapshot didn't see.
 */
final class ChangeSetScopeTest extends TestCase
{
    private const SRC = 'https://local.test/wp-content/uploads';

    /** @return list<array<string, mixed>> */
    private function media(): array
    {
        $entry = static fn (int $id, string $path): array => [
            'id' => $id, 'ref' => basename($path), 'filename' => basename($path), 'url' => self::SRC . '/' . $path, 'alt' => '',
        ];

        return [
            $entry(77, '2026/01/hero.jpg'),
            $entry(78, '2026/01/inline.png'),
            $entry(79, '2026/01/cover.webp'),
            $entry(80, '2026/02/guide.pdf'),
            $entry(81, '2026/02/photo-scaled.jpg'),
            $entry(99, '2026/03/unrelated.jpg'),
            $entry(100, '2026/03/deleted.jpg'),
        ];
    }

    public function test_only_the_media_the_operations_reference(): void
    {
        $ops = [
            ['op' => 'update', 'target' => ['kind' => 'post'], 'post' => [
                'type' => 'page', 'slug' => 'home', 'featured_media' => 'cover.webp',
                'content' => '<!-- wp:image {"id":78} --><img class="wp-image-78" src="x"><!-- /wp:image -->'
                    . '<a href="' . self::SRC . '/2026/02/photo-1024x683.jpg">big</a>',
                'fields' => ['hero' => 77, 'title' => 'Hero 99 words', 'rows' => [['file' => self::SRC . '/2026/02/guide.pdf']]],
            ]],
            ['op' => 'delete', 'target' => ['kind' => 'post'], 'post' => ['fields' => ['hero' => 100]]],
        ];

        $ids = array_column(ChangeSet::referencedMedia($this->media(), $ops), 'id');

        $this->assertSame([77, 78, 79, 80, 81], $ids, 'field ID, block ID, featured filename, linked file, size variant of a -scaled upload; not a number inside text, not a delete');
    }

    public function test_a_change_set_carries_source_refs_and_media(): void
    {
        $base = ['meta' => ['schema' => '1.6'], 'posts' => [['type' => 'page', 'slug' => 'home', 'title' => 'Old']]];
        $target = [
            'meta'  => ['schema' => '1.6', 'source' => ['url' => 'https://local.test', 'uploads_url' => self::SRC]],
            'refs'  => ['posts' => ['135' => ['type' => 'post', 'slug' => 'studio']]],
            'media' => $this->media(),
            'posts' => [['type' => 'page', 'slug' => 'home', 'title' => 'New', 'fields' => ['hero' => 77, 'pick' => 135]]],
        ];

        $set = ChangeSet::between($base, $target);

        $this->assertSame('https://local.test', $set['meta']['source']['url']);
        $this->assertSame($target['refs'], $set['refs']);
        $this->assertSame([77], array_column($set['media'], 'id'));
        $this->assertCount(1, $set['operations']);
    }

    public function test_a_scoped_snapshot_deletes_nothing_it_did_not_see(): void
    {
        $full = [
            'meta'    => ['schema' => '1.6', 'scope' => ['posts' => 'all', 'terms' => 'all', 'options' => true]],
            'options' => ['blogname' => 'Site', '_taw_phone' => '555'],
            'terms'   => ['category' => [['slug' => 'news', 'name' => 'News'], ['slug' => 'events', 'name' => 'Events']]],
            'posts'   => [['type' => 'page', 'slug' => 'about', 'title' => 'About'], ['type' => 'page', 'slug' => 'team', 'title' => 'Team']],
            'users'   => [['login' => 'ana']],
        ];
        $scoped = [
            'meta'  => ['schema' => '1.6', 'scope' => ['posts' => 'partial', 'terms' => 'used', 'options' => false]],
            'terms' => ['category' => [['slug' => 'news', 'name' => 'News!']]],
            'posts' => [['type' => 'page', 'slug' => 'about', 'title' => 'About us']],
        ];

        $ops = ChangeSet::between($full, $scoped)['operations'];

        $this->assertSame([], array_values(array_filter($ops, static fn (array $op): bool => $op['op'] === 'delete')));
        $this->assertSame(['post', 'term'], array_column(array_column($ops, 'target'), 'kind'), 'the edits in scope still travel');
    }

    public function test_two_full_snapshots_still_delete(): void
    {
        $a = ['meta' => ['schema' => '1.5'], 'options' => ['x' => 1], 'posts' => [['type' => 'page', 'slug' => 'gone']], 'terms' => []];
        $b = ['meta' => ['schema' => '1.6', 'scope' => ['posts' => 'all', 'terms' => 'all', 'options' => true]], 'options' => [], 'posts' => [], 'terms' => []];

        $deleted = array_map(
            static fn (array $op): string => $op['target']['kind'],
            array_values(array_filter(ChangeSet::between($a, $b)['operations'], static fn (array $op): bool => $op['op'] === 'delete'))
        );
        sort($deleted);

        $this->assertSame(['option', 'post'], $deleted);
    }

    public function test_menus_and_theme_mods_diff_as_records(): void
    {
        $menu = static fn (string $label): array => ['slug' => 'primary-menu', 'name' => 'Primary', 'items' => [['key' => '1', 'type' => 'custom', 'url' => 'https://x.test/', 'title' => $label]]];
        $a = ['meta' => ['schema' => '1.7'], 'posts' => [], 'menus' => [$menu('Home'), ['slug' => 'old', 'name' => 'Old', 'items' => []]], 'theme_mods' => ['custom_logo' => 17, 'nav_menu_locations' => ['primary' => 'primary-menu']]];
        $b = ['meta' => ['schema' => '1.7'], 'posts' => [], 'menus' => [$menu('Start')], 'theme_mods' => ['custom_logo' => 18, 'nav_menu_locations' => ['primary' => 'primary-menu']]];

        $ops = array_map(static fn (array $op): string => $op['op'] . ' ' . $op['target']['kind'] . ':' . $op['target']['key'], ChangeSet::between($a, $b)['operations']);

        $this->assertSame(['update menu:primary-menu', 'delete menu:old', 'update theme_mod:custom_logo'], $ops);

        $scoped = $b;
        $scoped['meta']['scope'] = ['posts' => 'partial', 'terms' => 'used', 'options' => false];
        unset($scoped['menus'], $scoped['theme_mods']);
        $this->assertSame([], ChangeSet::between($a, $scoped)['operations'], 'a scoped export carries no menus, and deletes none');
    }
}
