<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use Brain\Monkey\Functions;
use TAW\Core\Content\Importer;
use TAW\Tests\TestCase;

/**
 * Import bugs that left wrong data behind (Content Interchange fidelity,
 * phase 1): each test is the failure the audit found, fixed.
 */
final class ImporterFidelityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page', 'attachment' => 'attachment']);
        Functions\when('get_post_field')->justReturn('');
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('set_post_thumbnail')->justReturn(true);
        Functions\when('get_date_from_gmt')->alias(static fn (string $d): string => $d . ' local');
        Functions\when('taxonomy_exists')->justReturn(true);
    }

    protected function tearDown(): void
    {
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();
        \TAW\Core\Metabox\Metabox::forgetInstances();
        parent::tearDown();
    }

    public function test_a_failed_write_never_lands_on_post_1(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('is_wp_error')->alias(static fn ($v): bool => $v instanceof \WP_Error);
        Functions\when('wp_insert_post')->justReturn(new \WP_Error('db', 'Database error'));
        Functions\expect('update_post_meta')->never();

        $report = (new Importer())->apply(['posts' => [[
            'type' => 'page', 'slug' => 'about', 'title' => 'About', 'fields' => ['heading' => 'Hi'],
        ]]], ['rollback' => false]);

        $this->assertSame(['page:about'], $report['skipped']);
        $this->assertStringContainsString('write failed: Database error', implode("\n", $report['warnings']));
    }

    public function test_content_is_written_with_kses_lifted_and_restored(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('has_filter')->justReturn(10);
        $calls = [];
        Functions\when('kses_remove_filters')->alias(static function () use (&$calls) { $calls[] = 'remove'; });
        Functions\when('kses_init_filters')->alias(static function () use (&$calls) { $calls[] = 'init'; });
        Functions\when('wp_insert_post')->alias(static function () use (&$calls) { $calls[] = 'insert'; return 9; });

        (new Importer())->apply(['posts' => [[
            'type' => 'page', 'slug' => 'map', 'content' => '<iframe src="https://maps.example/?a=1&b=2"></iframe>',
        ]]], ['rollback' => false]);

        $this->assertSame(['remove', 'insert', 'init'], $calls);
    }

    public function test_a_page_takes_its_slug_back_from_a_sideloaded_attachment(): void
    {
        Functions\when('get_post_field')->justReturn('about-2');
        Functions\when('wp_insert_post')->justReturn(40);
        Functions\when('get_posts')->alias(static fn (array $q): array => ($q['post_type'] ?? '') === 'attachment' ? [77] : []);
        $updates = [];
        Functions\when('wp_update_post')->alias(static function (array $a) use (&$updates) { $updates[] = $a; return $a['ID']; });

        (new Importer())->apply(['posts' => [['type' => 'page', 'slug' => 'about', 'title' => 'About']]], ['rollback' => false]);

        $this->assertSame([['ID' => 77, 'post_name' => 'about-media'], ['ID' => 40, 'post_name' => 'about']], $updates);
    }

    public function test_password_date_and_terms_are_compared_and_written(): void
    {
        $existing = new \WP_Post(['ID' => 7, 'post_type' => 'post', 'post_name' => 'news', 'post_password' => '',
            'post_date_gmt' => '2026-01-01 10:00:00', 'post_title' => 'News']);
        Functions\when('get_posts')->justReturn([$existing]);
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_object_terms')->justReturn(['old-tag']);

        $incoming = ['type' => 'post', 'slug' => 'news', 'title' => 'News', 'password' => 's3cret',
            'date' => '2026-02-03 04:05:06', 'terms' => ['post_tag' => ['new-tag'], 'category' => []]];
        $changes = (new Importer())->plan(['posts' => [$incoming]])['records'][0]['changes'];

        $this->assertSame('s3cret', $changes['password']['new']);
        $this->assertSame('2026-02-03 04:05:06', $changes['date']['new']);
        $this->assertSame(['new-tag'], $changes['terms.post_tag']['new']);
        $this->assertSame([], $changes['terms.category']['new'], 'an empty list clears the taxonomy');

        $written = null;
        Functions\when('wp_update_post')->alias(static function (array $a) use (&$written) { $written = $a; return 7; });
        (new Importer())->apply(['posts' => [$incoming]], ['rollback' => false]);

        $this->assertSame('s3cret', $written['post_password']);
        $this->assertSame('2026-02-03 04:05:06', $written['post_date_gmt']);
        $this->assertSame('2026-02-03 04:05:06 local', $written['post_date'], 'both columns, or they drift apart');
        $this->assertTrue($written['edit_date']);
    }

    public function test_a_draft_date_is_not_a_date(): void
    {
        $existing = new \WP_Post(['ID' => 7, 'post_type' => 'post', 'post_name' => 'n', 'post_date_gmt' => '0000-00-00 00:00:00']);
        Functions\when('get_posts')->justReturn([$existing]);
        Functions\when('get_post_thumbnail_id')->justReturn(0);

        $changes = (new Importer())->plan(['posts' => [['type' => 'post', 'slug' => 'n', 'date' => '0000-00-00 00:00:00']]])['records'][0]['changes'];

        $this->assertArrayNotHasKey('date', $changes);
    }

    public function test_comments_match_on_their_own_date_and_keep_it(): void
    {
        Functions\when('get_posts')->justReturn([new \WP_Post(['ID' => 3, 'post_type' => 'post', 'post_name' => 'hello'])]);
        $query = null;
        Functions\when('get_comments')->alias(static function (array $q) use (&$query) { $query = $q; return []; });
        $inserted = null;
        Functions\when('wp_insert_comment')->alias(static function (array $c) use (&$inserted) { $inserted = $c; return 12; });
        Functions\when('wp_update_comment_count')->justReturn(true);

        (new Importer())->apply(['comments' => [[
            'ref' => '5', 'post_ref' => 'hello', 'post_type' => 'post', 'author_email' => 'a@x.test',
            'content' => 'Nice', 'date_gmt' => '2025-06-01 08:00:00',
        ]]], ['rollback' => false]);

        $this->assertSame('2025-06-01 07:59:59', $query['date_query'][0]['after'], 'around the comment, not around now');
        $this->assertSame('2025-06-01 08:00:01', $query['date_query'][0]['before']);
        $this->assertSame('2025-06-01 08:00:00 local', $inserted['comment_date']);
    }

    public function test_a_comment_delete_deletes_and_is_previewed(): void
    {
        Functions\when('get_posts')->justReturn([new \WP_Post(['ID' => 3, 'post_type' => 'post', 'post_name' => 'hello'])]);
        Functions\when('get_comments')->justReturn([(object) ['comment_ID' => 21, 'comment_content' => 'Spam', 'comment_date_gmt' => '2025-06-01 08:00:00']]);
        Functions\when('wp_update_comment_count')->justReturn(true);
        Functions\expect('wp_insert_comment')->never();
        Functions\expect('wp_delete_comment')->once()->with(21, true)->andReturn(true);

        $changeset = ['taw_changeset' => ['schema' => '1.3'], 'operations' => [[
            'op' => 'delete', 'target' => ['kind' => 'comment', 'key' => '5'],
            'fields' => ['post_ref' => 'hello', 'post_type' => 'post', 'content' => 'Spam', 'date_gmt' => '2025-06-01 08:00:00'],
        ]]];

        $this->assertSame('would-delete', (new Importer())->plan($changeset)['records'][0]['op']);
        $report = (new Importer())->apply($changeset, ['rollback' => false]);
        $this->assertCount(1, $report['deleted']);
    }

    public function test_option_and_term_deletes_are_previewed(): void
    {
        Functions\when('get_option')->justReturn('x');
        Functions\when('get_term_by')->justReturn((object) ['term_id' => 4]);

        $records = (new Importer())->plan(['taw_changeset' => ['schema' => '1.3'], 'operations' => [
            ['op' => 'delete', 'target' => ['kind' => 'option', 'key' => '_taw_old']],
            ['op' => 'delete', 'target' => ['kind' => 'term', 'type' => 'category', 'slug' => 'old']],
        ]])['records'];

        $this->assertSame(['would-delete', 'would-delete'], array_column($records, 'op'));
    }

    public function test_a_user_never_loses_their_role(): void
    {
        Functions\when('get_user_by')->justReturn((object) ['ID' => 8]);
        Functions\when('get_userdata')->justReturn((object) ['display_name' => 'Old name', 'roles' => ['editor']]);
        Functions\when('wp_roles')->justReturn(new class {
            /** @return array<string, string> */
            public function get_names(): array { return ['administrator' => 'Administrator', 'editor' => 'Editor']; }
        });
        Functions\when('is_wp_error')->justReturn(false);
        $data = null;
        Functions\when('wp_update_user')->alias(static function (array $u) use (&$data) { $data = $u; return 8; });

        (new Importer())->apply(['users' => [['login' => 'shop', 'email' => 'shop@x.test', 'display_name' => 'Shop', 'roles' => ['shop_manager']]]], ['rollback' => false]);

        $this->assertArrayNotHasKey('role', $data, "a role this site doesn't define would strip the user's role");
    }

    public function test_unregistered_values_are_written_as_they_came(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('wp_insert_post')->justReturn(5);
        $meta = [];
        Functions\when('update_post_meta')->alias(static function (int $id, string $k, $v) use (&$meta) { $meta[$k] = $v; return true; });
        $options = [];
        Functions\when('get_option')->justReturn(null);
        Functions\when('update_option')->alias(static function (string $k, $v) use (&$options) { $options[$k] = $v; return true; });

        $report = (new Importer())->apply([
            'posts'   => [['type' => 'page', 'slug' => 'kb', 'fields' => ['sources' => ['a' => 1, 'b' => [2, 3]]]]],
            'options' => ['_taw_rag_knowledge_bases' => [['id' => 'kb1']]],
        ], ['rollback' => false]);

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $meta['_taw_sources'], 'not run through text, which wipes arrays');
        $this->assertSame([['id' => 'kb1']], $options['_taw_rag_knowledge_bases'], 'an array option stays an array');
        $this->assertStringContainsString("isn't registered on this site", implode("\n", $report['warnings']));
    }

    public function test_a_newer_minor_schema_is_flagged(): void
    {
        $plan = (new Importer())->plan(['meta' => ['schema' => '1.9'], 'posts' => []]);

        $this->assertStringContainsString("schema '1.9' is newer", implode("\n", $plan['warnings']));
    }
}
