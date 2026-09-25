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
    protected function setUp(): void
    {
        parent::setUp();
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->alias(static fn (string $t): bool => in_array($t, ['page', 'post', 'book'], true));
    }

    protected function tearDown(): void
    {
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();
        \TAW\Core\Metabox\Metabox::forgetInstances();
        parent::tearDown();
    }

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

    public function test_operations_from_emits_kinds_in_dependency_order(): void
    {
        $snapshot = [
            'meta'     => ['schema' => '1.1'],
            'users'    => [['login' => 'ada', 'email' => 'a@x.test']],
            'terms'    => ['category' => [['slug' => 'news', 'name' => 'News']]],
            'posts'    => [['type' => 'page', 'slug' => 'home', 'fields' => []]],
            'options'  => ['_taw_phone' => '5', 'permalink_structure' => '/%postname%/'],
            'comments' => [['post_ref' => 'home', 'content' => 'hi', 'author_email' => 'c@x.test', 'date_gmt' => '2026-01-01 00:00:00']],
        ];

        $kinds = [];
        foreach (Importer::operationsFrom($snapshot) as $op) {
            $k = $op['target']['kind'];
            $kinds[] = $k === 'option' && ($op['target']['key'] ?? '') === 'permalink_structure' ? 'settings' : $k;
        }

        $this->assertSame(['user', 'term', 'post', 'option', 'comment', 'settings'], $kinds);
    }

    public function test_plan_warns_on_an_unknown_schema_major_but_not_on_1_x(): void
    {
        Functions\when('get_posts')->justReturn([]);

        $this->assertSame([], (new Importer())->plan(['meta' => ['schema' => '1.0'], 'posts' => []])['warnings']);
        $this->assertSame([], (new Importer())->plan(['meta' => ['schema' => '1.1'], 'posts' => []])['warnings']);
        $this->assertNotSame([], (new Importer())->plan(['meta' => ['schema' => '2.0'], 'posts' => []])['warnings']);
    }

    public function test_author_round_trips_without_a_change(): void
    {
        $existing = new \WP_Post(['ID' => 7, 'post_type' => 'page', 'post_name' => 'about', 'post_author' => 4]);
        Functions\when('get_posts')->justReturn([$existing]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_user_by')->alias(static fn (string $by, $val) => $val === 'ada' || $val === 'ada@x.test'
            ? (object) ['ID' => 4] : false);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));

        $plan = (new Importer())->plan(['posts' => [[
            'type' => 'page', 'slug' => 'about',
            'author' => ['login' => 'ada', 'email' => 'ada@x.test'],
        ]]]);

        $this->assertSame([], $plan['records'][0]['changes']);
    }

    public function test_apply_falls_back_to_the_current_user_when_the_author_is_absent(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_user_by')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('set_post_thumbnail')->justReturn(true);

        $captured = null;
        Functions\when('wp_insert_post')->alias(static function ($arr) use (&$captured) {
            $captured = $arr;
            return 55;
        });

        $importer = new Importer();
        $report = $importer->apply(['posts' => [[
            'type' => 'page', 'slug' => 'new-page', 'title' => 'New',
            'author' => ['login' => 'ghost', 'email' => 'ghost@x.test'], 'fields' => [],
        ]]], ['rollback' => false]);

        $this->assertSame(1, $captured['post_author']);
        $this->assertNotSame([], $report['warnings']);
    }

    public function test_apply_writes_each_field_key_form_to_its_own_meta_key(): void
    {
        new \TAW\Core\Metabox\Metabox([
            'id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'prefix' => '_book_',
            'fields' => [['id' => 'author', 'type' => 'text']],
        ]);
        new \TAW\Core\Metabox\Metabox([
            'id' => 'hero', 'title' => 'Hero', 'screens' => ['book'],
            'fields' => [
                ['id' => 'heading', 'type' => 'text'],
                ['id' => 'cta', 'type' => 'group', 'fields' => [['id' => 'text', 'type' => 'text']]],
            ],
        ]);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('wp_insert_post')->justReturn(55);
        $written = [];
        Functions\when('update_post_meta')->alias(static function (int $id, string $key, $value) use (&$written): bool {
            $written[$key] = $value;
            return true;
        });

        (new Importer())->apply(['meta' => ['schema' => '1.2'], 'posts' => [[
            'type' => 'book', 'slug' => 'dune', 'title' => 'Dune',
            'fields' => ['_book_author' => 'Frank Herbert', 'heading' => 'Arrakis', 'cta_text' => 'Read'],
        ]]], ['rollback' => false]);

        $this->assertSame(
            ['_book_author' => 'Frank Herbert', '_taw_heading' => 'Arrakis', '_taw_cta_text' => 'Read'],
            $written,
            '1.2 meta key, 1.1 bare id, and a group sub-field under its compound key (was _taw_text)'
        );
    }

    public function test_slug_lookups_never_use_a_singular_name_query(): void
    {
        // A singular `name` query hides drafts/private posts from a user-less
        // CLI import, so an existing draft with a slug was created again.
        $queries = [];
        Functions\when('get_posts')->alias(static function (array $args) use (&$queries): array {
            $queries[] = $args;
            return [];
        });
        Functions\when('get_option')->justReturn(false);

        $importer = new Importer();
        $importer->plan(['posts' => [
            ['type' => 'page', 'slug' => 'privacy-policy', 'status' => 'draft', 'fields' => []],
        ]]);
        $this->callMethod($importer, 'resolveLocalPostId', 'about');
        $this->callMethod($importer, 'resolveAnyPostId', 'hello');

        $this->assertNotSame([], $queries);
        foreach ($queries as $args) {
            $this->assertArrayNotHasKey('name', $args);
        }
        $this->assertContains(['privacy-policy'], array_column($queries, 'post_name__in'));
        $this->assertContains(['about'], array_column($queries, 'post_name__in'), 'parent / page_on_front refs');
        $this->assertContains(['hello'], array_column($queries, 'post_name__in'), 'comment post_refs');
    }

    public function test_term_fields_diff_and_write_through_term_meta(): void
    {
        new \TAW\Core\Metabox\Metabox([
            'id' => 'genre_details', 'title' => 'Genre', 'screens' => ['term:genre'],
            'fields' => [['id' => 'genre_rank', 'type' => 'number'], ['id' => 'genre_tagline', 'type' => 'text']],
        ]);
        $existing = new \WP_Term(['term_id' => 5, 'taxonomy' => 'genre', 'slug' => 'fantasy', 'name' => 'Fantasy', 'description' => '']);
        Functions\when('get_term_by')->justReturn($existing);
        Functions\when('get_term_meta')->alias(static fn (int $id, string $key) => ['_taw_genre_rank' => '3', '_taw_genre_tagline' => 'Old'][$key] ?? '');
        $input = ['meta' => ['schema' => '1.2'], 'terms' => ['genre' => [
            ['slug' => 'fantasy', 'name' => 'Fantasy', 'description' => '', 'fields' => ['genre_rank' => 3, 'genre_tagline' => 'New']],
        ]]];

        $changes = (new Importer())->plan($input)['records'][0]['changes'];
        $this->assertSame(['fields.genre_tagline' => ['status' => 'changed', 'old' => 'Old', 'new' => 'New']], $changes, '3 ↔ "3" is unchanged');

        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\when('wp_update_term')->justReturn(['term_id' => 5]);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        $written = [];
        Functions\when('update_term_meta')->alias(static function (int $id, string $key, $value) use (&$written): bool {
            $written["{$id}|{$key}"] = $value;
            return true;
        });

        (new Importer())->apply($input, ['rollback' => false]);

        $this->assertSame('New', $written['5|_taw_genre_tagline']);
        $this->assertArrayHasKey('5|_taw_genre_rank', $written);
    }

    public function test_user_fields_diff_and_write_through_user_meta(): void
    {
        new \TAW\Core\Metabox\Metabox(['id' => 'author_details', 'title' => 'Author', 'screens' => ['user'], 'fields' => [
            ['id' => 'author_rank', 'type' => 'number'], ['id' => 'author_twitter', 'type' => 'text'],
        ]]);
        Functions\when('get_user_by')->alias(static fn (string $f, $v) => $f === 'login' ? (object) ['ID' => 3] : false);
        Functions\when('get_userdata')->justReturn((object) ['ID' => 3, 'display_name' => 'Ada', 'roles' => ['author']]);
        Functions\when('get_user_meta')->alias(static fn (int $id, string $key) => ['_taw_author_rank' => '2', '_taw_author_twitter' => 'old'][$key] ?? '');
        $input = ['meta' => ['schema' => '1.2'], 'users' => [
            ['login' => 'ada', 'email' => 'ada@x.test', 'display_name' => 'Ada', 'roles' => ['author'], 'meta' => [], 'fields' => ['author_rank' => 2, 'author_twitter' => 'new']],
        ]];

        $changes = (new Importer())->plan($input)['records'][0]['changes'];
        $this->assertSame(['fields.author_twitter' => ['status' => 'changed', 'old' => 'old', 'new' => 'new']], $changes, '2 ↔ "2" is unchanged');

        Functions\when('wp_roles')->justReturn(new class {
            /** @return array<string, string> */
            public function get_names(): array
            {
                return ['author' => 'Author'];
            }
        });
        Functions\when('wp_update_user')->justReturn(3);
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        $written = [];
        Functions\when('update_user_meta')->alias(static function (int $id, string $key, $value) use (&$written): bool {
            $written["{$id}|{$key}"] = $value;
            return true;
        });

        (new Importer())->apply($input, ['rollback' => false]);

        $this->assertSame('new', $written['3|_taw_author_twitter'] ?? null);
    }

    public function test_with_users_sanitizes_roles_against_the_target_and_writes_no_password(): void
    {
        Functions\when('get_user_by')->justReturn(false);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('wp_roles')->justReturn(new class {
            /** @return array<string, string> */
            public function get_names(): array
            {
                return ['administrator' => 'Administrator', 'editor' => 'Editor'];
            }
        });
        Functions\when('wp_generate_password')->justReturn('generated');
        Functions\when('update_user_meta')->justReturn(true);

        $captured = null;
        Functions\when('wp_insert_user')->alias(static function ($data) use (&$captured) {
            $captured = $data;
            return 9;
        });

        $importer = new Importer();
        $report = $importer->apply([
            'users' => [[
                'login' => 'sam', 'email' => 'sam@x.test', 'display_name' => 'Sam',
                'roles' => ['administrator', 'shop_manager'], 'meta' => [],
            ]],
        ], ['rollback' => false]);

        $this->assertContains('user:sam', $report['created']);
        $this->assertSame('administrator', $captured['role']);
        // A default export carries no hash → the new account gets a random password.
        $this->assertSame('generated', $captured['user_pass']);
        // shop_manager isn't defined on the target → a warning, never granted.
        $this->assertNotSame([], array_filter($report['warnings'], static fn ($w) => str_contains($w, 'shop_manager')));
    }

    public function test_sticky_posts_setting_is_skipped_without_the_flag_and_remapped_with_it(): void
    {
        $sticky = new \WP_Post(['ID' => 12, 'post_type' => 'post', 'post_name' => 'featured']);
        Functions\when('get_option')->alias(static fn (string $k, $d = null) => $k === 'sticky_posts' ? [] : $d);
        Functions\when('get_posts')->justReturn([$sticky]);
        Functions\when('get_post')->justReturn($sticky);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);

        $withoutFlag = (new Importer())->apply(['options' => ['sticky_posts' => ['featured']]], ['rollback' => false]);
        $this->assertNotSame([], array_filter($withoutFlag['skipped'], static fn ($s) => str_contains($s, 'sticky_posts')));
        $this->assertSame([], $withoutFlag['created']);
        $this->assertSame([], $withoutFlag['updated']);

        Functions\expect('update_option')->once()->with('sticky_posts', [12]);
        $withFlag = (new Importer())->apply(['options' => ['sticky_posts' => ['featured']]], ['rollback' => false, 'include_settings' => true]);
        $this->assertContains('option:sticky_posts', $withFlag['updated']);
    }

    public function test_slugless_draft_re_import_is_idempotent(): void
    {
        $draft = new \WP_Post([
            'ID' => 30, 'post_type' => 'post', 'post_name' => '', 'post_title' => 'Note',
            'post_date_gmt' => '2026-02-02 12:00:00', 'post_author' => 0,
        ]);
        Functions\when('get_posts')->justReturn([$draft]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));

        $record = [
            'type' => 'post', 'slug' => '', 'title' => 'Note', 'date' => '2026-02-02 12:00:00',
            'match_key' => sha1('post|Note|2026-02-02 12:00:00'), 'fields' => [],
        ];

        $plan = (new Importer())->plan(['posts' => [$record]]);

        $this->assertSame('update', $plan['records'][0]['op'], 'the slug-less draft is matched, not recreated');
        $this->assertSame([], $plan['records'][0]['changes']);
    }

    public function test_migrate_scope_snapshot_plans_zero_changes_against_the_same_site(): void
    {
        // The top-level acceptance criterion: export --migrate then re-plan
        // against the unchanged site → nothing would be written.
        $this->forbidAllWrites();

        $about = new \WP_Post([
            'ID' => 7, 'post_type' => 'page', 'post_name' => 'about', 'post_author' => 4,
            'post_title' => 'About', 'comment_status' => 'open', 'ping_status' => 'open',
        ]);
        Functions\when('get_posts')->justReturn([$about]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_user_by')->alias(static fn (string $by, $v) => in_array($v, ['ada', 'ada@x.test'], true) ? (object) ['ID' => 4] : false);
        Functions\when('get_userdata')->justReturn((object) [
            'display_name' => 'Ada', 'roles' => ['administrator'],
        ]);
        Functions\when('get_user_meta')->justReturn('');
        Functions\when('get_option')->alias(static fn (string $k, $d = null) => match ($k) {
            'blogname'            => 'Site',
            'permalink_structure' => '/%postname%/',
            default               => $d,
        });
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);

        $snapshot = [
            'meta'    => ['schema' => '1.1'],
            'users'   => [['login' => 'ada', 'email' => 'ada@x.test', 'display_name' => 'Ada', 'roles' => ['administrator'], 'meta' => []]],
            'options' => ['blogname' => 'Site', 'permalink_structure' => '/%postname%/'],
            'posts'   => [[
                'type' => 'page', 'slug' => 'about', 'title' => 'About',
                'author' => ['login' => 'ada', 'email' => 'ada@x.test'],
                'comment_status' => 'open', 'ping_status' => 'open', 'fields' => [],
            ]],
        ];

        $records = (new Importer())->plan($snapshot)['records'];

        foreach ($records as $record) {
            $this->assertSame([], $record['changes'] ?? [], "{$record['kind']} record should be unchanged");
        }
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
