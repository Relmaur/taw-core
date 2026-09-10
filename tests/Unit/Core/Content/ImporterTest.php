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
