<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use Brain\Monkey\Functions;
use TAW\Core\Content\Importer;
use TAW\Tests\TestCase;

/**
 * Two things matter most about the importer and both are checked here:
 *   1. It normalizes either input shape (full snapshot / change-set) into
 *      the same flat operations list.
 *   2. plan() is a real dry run — it never calls a single write function.
 */
final class ImporterTest extends TestCase
{
    public function test_operations_from_passes_a_changeset_through(): void
    {
        $changeset = [
            'taw_changeset' => ['schema' => '1.0'],
            'operations' => [
                ['op' => 'update', 'target' => ['kind' => 'post', 'type' => 'page', 'slug' => 'about']],
            ],
        ];

        $ops = Importer::operationsFrom($changeset);

        $this->assertCount(1, $ops);
        $this->assertSame('about', $ops[0]['target']['slug']);
    }

    public function test_operations_from_expands_a_snapshot_into_upserts(): void
    {
        $snapshot = [
            'meta'    => ['schema' => '1.0'],
            'posts'   => [['type' => 'page', 'slug' => 'home', 'fields' => []]],
            'options' => ['_taw_phone' => '555'],
            'terms'   => ['category' => [['slug' => 'news', 'name' => 'News']]],
        ];

        $ops = Importer::operationsFrom($snapshot);

        $kinds = array_column(array_column($ops, 'target'), 'kind');
        sort($kinds);
        $this->assertSame(['option', 'post', 'term'], $kinds);
        foreach ($ops as $op) {
            $this->assertSame('update', $op['op']);
        }
    }

    public function test_plan_never_writes_for_a_new_post(): void
    {
        $this->forbidAllWrites();
        Functions\when('get_posts')->justReturn([]);

        $input = ['posts' => [[
            'type' => 'page', 'slug' => 'about', 'title' => 'About', 'content' => 'Hi', 'fields' => [],
        ]]];

        $plan = (new Importer())->plan($input);

        $this->assertSame('create', $plan['records'][0]['op']);
        $this->assertSame('new', $plan['records'][0]['changes']['title']['status']);
    }

    public function test_plan_never_writes_when_diffing_a_changed_field(): void
    {
        $this->forbidAllWrites();

        $existing = new \WP_Post(['ID' => 7, 'post_type' => 'page', 'post_name' => 'about']);
        Functions\when('get_posts')->justReturn([$existing]);
        Functions\when('get_post_meta')->justReturn('Old heading');
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));

        $input = ['posts' => [[
            'type' => 'page', 'slug' => 'about', 'fields' => ['hero_heading' => 'New heading'],
        ]]];

        $plan = (new Importer())->plan($input);

        $record = $plan['records'][0];
        $this->assertSame('update', $record['op']);
        $this->assertSame('changed', $record['changes']['fields.hero_heading']['status']);
        $this->assertSame('Old heading', $record['changes']['fields.hero_heading']['old']);
        $this->assertSame('New heading', $record['changes']['fields.hero_heading']['new']);
    }

    public function test_round_trip_reports_no_change_for_an_empty_field(): void
    {
        // Stored value absent (get_post_meta → '') and the snapshot carries
        // the same field as '' — Bug B: this must NOT report as 'new'.
        $existing = new \WP_Post(['ID' => 7, 'post_type' => 'page', 'post_name' => 'about']);
        Functions\when('get_posts')->justReturn([$existing]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));

        $input = ['posts' => [[
            'type' => 'page', 'slug' => 'about',
            'fields' => ['subtitle' => '', 'gallery' => [], 'featured' => false],
        ]]];

        $plan = (new Importer())->plan($input);

        $this->assertSame([], $plan['records'][0]['changes'], 'empty ↔ empty must be a no-op');
    }

    public function test_round_trip_reports_no_change_for_a_repeater_field(): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('__')->returnArg(1);
        new \TAW\Core\Metabox\Metabox([
            'id' => 'taw_it', 'title' => 'T', 'screens' => 'page',
            'fields' => [['id' => 'team', 'type' => 'repeater', 'fields' => [
                ['id' => 'name', 'type' => 'text'],
                ['id' => 'featured', 'type' => 'checkbox'],
            ]]],
        ]);

        $existing = new \WP_Post(['ID' => 7, 'post_type' => 'page', 'post_name' => 'about']);
        Functions\when('get_posts')->justReturn([$existing]);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        // Stored as a JSON string (how WP meta holds a repeater).
        Functions\when('get_post_meta')->justReturn('[{"name":"Ada","featured":"1"}]');

        // The snapshot carries the decoded form (array of rows, bool).
        $input = ['posts' => [[
            'type' => 'page', 'slug' => 'about',
            'fields' => ['team' => [['name' => 'Ada', 'featured' => true]]],
        ]]];

        $plan = (new Importer())->plan($input);

        $this->assertSame([], $plan['records'][0]['changes']);
    }

    public function test_page_on_front_slug_and_stored_id_compare_equal(): void
    {
        // Bug A: exporter renders page_on_front as a slug; the stored value
        // is the numeric ID. A round-trip must resolve the slug back and see
        // no change.
        $frontPage = new \WP_Post(['ID' => 42, 'post_type' => 'page', 'post_name' => 'inicio']);
        Functions\when('get_option')->alias(static fn (string $k, $d = false) => $k === 'page_on_front' ? '42' : $d);
        Functions\when('get_post')->alias(static fn ($id) => (int) $id === 42 ? $frontPage : null);
        Functions\when('get_posts')->justReturn([$frontPage]); // resolveLocalPostId('inicio')
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));

        $plan = (new Importer())->plan(['options' => ['page_on_front' => 'inicio']]);

        $this->assertSame([], $plan['records'][0]['changes'], 'slug ↔ stored ID must compare equal');
    }

    public function test_apply_writes_page_on_front_as_an_integer_id_not_a_slug(): void
    {
        // Incoming snapshot points the front page at a different page ('portada',
        // id 99); the site currently has id 42. A real change — apply must
        // write the resolved integer ID, never the slug string (Bug A).
        $newFront = new \WP_Post(['ID' => 99, 'post_type' => 'page', 'post_name' => 'portada']);
        Functions\when('get_option')->justReturn('42');
        Functions\when('get_post')->alias(static fn ($id) => (int) $id === 42
            ? new \WP_Post(['ID' => 42, 'post_type' => 'page', 'post_name' => 'inicio'])
            : null);
        Functions\when('get_posts')->justReturn([$newFront]); // resolveLocalPostId('portada') → 99
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $hook, $v = null) => $v);

        Functions\expect('update_option')->once()->with('page_on_front', 99);

        $report = (new Importer())->apply(['options' => ['page_on_front' => 'portada']], ['rollback' => false]);

        $this->assertContains('option:page_on_front', $report['updated']);
    }

    public function test_apply_skips_records_that_already_match(): void
    {
        // A clean round-trip: stored front-page ID resolves to the same page
        // the snapshot's slug points at → apply writes nothing for it.
        $front = new \WP_Post(['ID' => 42, 'post_type' => 'page', 'post_name' => 'inicio']);
        Functions\when('get_option')->justReturn('42');
        Functions\when('get_post')->alias(static fn ($id) => (int) $id === 42 ? $front : null);
        Functions\when('get_posts')->justReturn([$front]);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $hook, $v = null) => $v);

        Functions\expect('update_option')->never();

        $report = (new Importer())->apply(['options' => ['page_on_front' => 'inicio']], ['rollback' => false]);

        $this->assertContains('option:page_on_front', $report['skipped']);
        $this->assertSame([], $report['updated']);
    }

    /**
     * Any of these being called during plan() fails the test.
     */
    private function forbidAllWrites(): void
    {
        foreach ([
            'update_post_meta', 'delete_post_meta', 'wp_insert_post', 'wp_update_post',
            'wp_delete_post', 'update_option', 'delete_option', 'wp_insert_term',
            'wp_update_term', 'wp_delete_term', 'wp_set_object_terms', 'set_post_thumbnail',
        ] as $fn) {
            Functions\expect($fn)->never();
        }
    }
}
