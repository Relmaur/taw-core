<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use Brain\Monkey\Functions;
use TAW\Core\Content\Importer;
use TAW\Tests\TestCase;

/**
 * What a publish needs from the importer (1.98): labels, the base and the
 * conflicts it reveals, the keep-list, and a hash that follows the values.
 */
final class ImporterPublishTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('is_post_type_hierarchical')->justReturn(false);
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page', 'attachment' => 'attachment']);
        Functions\when('get_post_field')->justReturn('');
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('wp_get_object_terms')->justReturn([]);
    }

    protected function tearDown(): void
    {
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();
        \TAW\Core\Metabox\Metabox::forgetInstances();
        parent::tearDown();
    }

    /** Production now: its title was edited since the base; one field too. */
    private function production(): void
    {
        Functions\when('get_posts')->justReturn([new \WP_Post(['ID' => 7, 'post_type' => 'post', 'post_name' => 'news',
            'post_title' => 'News, edited on production', 'post_status' => 'publish'])]);
        Functions\when('get_post_meta')->alias(static fn (int $id, string $key) => [
            '_taw_hero_heading' => 'Hi',          // = base: only Local changed it
            '_taw_subtitle'     => 'From the client', // ≠ base, and Local changed it too
        ][$key] ?? '');
    }

    /** @return array<string, mixed> */
    private function changeSet(array $local = []): array
    {
        $base = ['type' => 'post', 'slug' => 'news', 'title' => 'News', 'status' => 'publish',
            'fields' => ['hero_heading' => 'Hi', 'subtitle' => 'Before']];
        $local += ['type' => 'post', 'slug' => 'news', 'title' => 'News', 'status' => 'publish',
            'fields' => ['hero_heading' => 'Hello', 'subtitle' => 'From Local']];

        return ['taw_changeset' => ['schema' => '1.8', 'base_fingerprint' => str_repeat('a', 64)],
            'operations' => [['op' => 'update', 'target' => ['kind' => 'post', 'type' => 'post', 'slug' => 'news'], 'post' => $local, 'base' => $base]]];
    }

    public function test_a_change_set_with_a_base_says_what_this_site_changed_since(): void
    {
        $this->production();
        $record = (new Importer())->plan($this->changeSet())['records'][0];

        $this->assertSame('post:post:news', $record['record_key']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record['hash']);
        $this->assertSame('News', $record['title']);
        $this->assertArrayHasKey('edit_url', $record, "where it's edited here ('' without WordPress)");
        $this->assertTrue($record['conflict']);

        $title = $record['changes']['title'];
        $this->assertSame('Title', $title['label']);
        $this->assertTrue($title['conflict'], 'production edited the title since the base');
        $this->assertFalse($title['local_changed'], 'Local still has the base title');
        $this->assertSame('News', $title['base']);

        $hero = $record['changes']['fields.hero_heading'];
        $this->assertFalse($hero['conflict']);
        $this->assertTrue($hero['local_changed']);

        $subtitle = $record['changes']['fields.subtitle'];
        $this->assertTrue($subtitle['conflict'], 'both sides changed it');
        $this->assertTrue($subtitle['local_changed']);
        $this->assertSame('Before', $subtitle['base']);
        $this->assertSame('From the client', $subtitle['old']);
        $this->assertSame('From Local', $subtitle['new']);
    }

    public function test_a_plain_snapshot_says_nothing_about_conflicts(): void
    {
        $this->production();
        $record = (new Importer())->plan(['posts' => [['type' => 'post', 'slug' => 'news', 'title' => 'News', 'fields' => ['hero_heading' => 'Hello']]]])['records'][0];

        $this->assertArrayNotHasKey('conflict', $record);
        $this->assertArrayNotHasKey('conflict', $record['changes']['title']);
        $this->assertSame('Title', $record['changes']['title']['label']);
    }

    public function test_a_change_set_without_its_base_record_cant_tell(): void
    {
        $this->production();
        $changeSet = $this->changeSet();
        unset($changeSet['operations'][0]['base']);
        $record = (new Importer())->plan($changeSet)['records'][0];

        $this->assertNull($record['changes']['title']['conflict']);
        $this->assertNull($record['conflict']);
    }

    public function test_a_page_new_here_conflicts_with_nothing(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_post_meta')->justReturn('');
        $record = (new Importer())->plan($this->changeSet())['records'][0];

        $this->assertSame('create', $record['op']);
        $this->assertFalse($record['changes']['title']['conflict']);
        $this->assertFalse($record['conflict']);
        $this->assertArrayNotHasKey('edit_url', $record);
    }

    public function test_kept_values_are_neither_listed_nor_written(): void
    {
        $this->production();
        $keep = ['post:post:news' => ['title', 'fields.subtitle']];

        $changes = (new Importer())->plan($this->changeSet(), ['keep' => $keep])['records'][0]['changes'];
        $this->assertArrayNotHasKey('title', $changes);
        $this->assertArrayNotHasKey('fields.subtitle', $changes);
        $this->assertArrayHasKey('fields.hero_heading', $changes);

        $written = null;
        Functions\when('wp_update_post')->alias(static function (array $a) use (&$written) { $written = $a; return 7; });
        $meta = [];
        Functions\when('update_post_meta')->alias(static function (int $id, string $key) use (&$meta) { $meta[] = $key; return true; });
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);
        (new Importer())->apply($this->changeSet(), ['rollback' => false, 'keep' => $keep]);

        $this->assertArrayNotHasKey('post_title', $written, "the title stays as production has it");
        $this->assertSame('publish', $written['post_status']);
        $this->assertContains('_taw_hero_heading', $meta);
        $this->assertNotContains('_taw_subtitle', $meta, 'the client\'s subtitle stays');
    }

    public function test_a_record_whose_every_change_is_kept_is_skipped(): void
    {
        $this->production();
        Functions\expect('wp_update_post')->never();
        $report = (new Importer())->apply($this->changeSet(['fields' => ['hero_heading' => 'Hi', 'subtitle' => 'From Local']]), [
            'rollback' => false, 'keep' => ['post:post:news' => ['title', 'fields.subtitle']],
        ]);

        $this->assertSame(['post:post:news'], $report['skipped']);
    }

    public function test_the_hash_follows_the_values_not_the_labels(): void
    {
        $a = ['op' => 'update', 'changes' => ['title' => ['status' => 'changed', 'old' => 'A', 'new' => 'B', 'label' => 'Title']]];
        $b = ['op' => 'update', 'changes' => ['title' => ['status' => 'changed', 'old' => 'A', 'new' => 'B', 'label' => 'Titel', 'conflict' => true]]];
        $c = ['op' => 'update', 'changes' => ['title' => ['status' => 'changed', 'old' => 'A', 'new' => 'C']]];

        $this->assertSame(Importer::changesHash($a), Importer::changesHash($b));
        $this->assertNotSame(Importer::changesHash($a), Importer::changesHash($c));
    }

    public function test_an_option_conflict_compares_stored_values(): void
    {
        Functions\when('get_option')->alias(static fn (string $k, $d = null) => $k === '_taw_phone' ? '555-0200' : $d);
        $changeSet = ['taw_changeset' => ['schema' => '1.8', 'base_fingerprint' => 'x'], 'operations' => [
            ['op' => 'update', 'target' => ['kind' => 'option', 'key' => '_taw_phone'], 'fields' => ['value' => '555-0300'], 'base' => ['value' => '555-0100']],
        ]];

        $change = (new Importer())->plan($changeSet)['records'][0]['changes']['value'];

        $this->assertTrue($change['conflict'], 'production moved from the base');
        $this->assertTrue($change['local_changed']);
        $this->assertSame('555-0100', $change['base']);
        $this->assertSame('_taw_phone', $change['label'], 'no options page here: the option name');
    }
}
