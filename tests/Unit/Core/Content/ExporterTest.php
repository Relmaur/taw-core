<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use Brain\Monkey\Functions;
use TAW\Core\Content\Exporter;
use TAW\Tests\TestCase;

/**
 * Exercises the exporter end to end against a stubbed WordPress: one page
 * with a decoded repeater + an image field, one `_taw_` option, the core
 * option allowlist (with `page_on_front` resolved to a slug), and the
 * media[] entry synthesised from the referenced image.
 */
final class ExporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();

        global $wpdb;
        $wpdb = new class {
            public string $options = 'wp_options';
            /** @return list<string> */
            public function get_col(string $query): array
            {
                return ['_taw_company_phone'];
            }
        };

        Functions\when('home_url')->justReturn('https://example.test');
        Functions\when('get_stylesheet')->justReturn('taw-theme');
        Functions\when('apply_filters')->alias(static fn (string $h, $value = null) => $value);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('__')->returnArg(1);

        // Register the fields the exported post uses, so the exporter decodes
        // them by their real types (repeater → rows, image → int + media ref).
        new \TAW\Core\Metabox\Metabox([
            'id'      => 'taw_export_test',
            'title'   => 'Export Test',
            'screens' => 'page',
            'fields'  => [
                ['id' => 'team', 'type' => 'repeater', 'fields' => [['id' => 'name', 'type' => 'text']]],
                ['id' => 'hero', 'type' => 'image'],
            ],
        ]);
        Functions\when('wp_basename')->alias(static fn (string $p): string => basename($p));
        Functions\when('get_post_types')->justReturn(['page' => 'page', 'post' => 'post']);
        Functions\when('post_type_exists')->alias(static fn (string $t): bool => in_array($t, ['page', 'post'], true));
        Functions\when('get_userdata')->justReturn(false);
        Functions\when('get_taxonomies')->justReturn([]);
        Functions\when('get_object_taxonomies')->justReturn([]);
        Functions\when('get_page_template_slug')->justReturn('');

        $page = new \WP_Post([
            'ID' => 10, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish',
            'post_title' => 'Home', 'post_excerpt' => '', 'post_content' => '<p>Hi</p>',
            'post_parent' => 0, 'menu_order' => 0, 'post_date_gmt' => '2026-01-01 00:00:00',
        ]);

        Functions\when('get_posts')->justReturn([$page]);
        Functions\when('get_post_meta')->alias(function ($id, $key = '', $single = false) {
            if ($id === 10 && $key === '') {
                return [
                    '_taw_team'  => ['[{"name":"Ada"}]'],
                    '_taw_hero'  => ['77'],
                    '_wp_page_template' => ['default'],
                ];
            }
            if ($key === '_wp_attached_file') {
                return '2026/01/hero.jpg';
            }
            if ($key === '_wp_attachment_image_alt') {
                return 'Hero alt';
            }
            return $single ? '' : [];
        });
        Functions\when('get_post_thumbnail_id')->justReturn(0);

        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return match ($name) {
                '_taw_company_phone' => '555-1234',
                'blogname' => 'Example',
                'blogdescription' => 'Tagline',
                'show_on_front' => 'page',
                'page_on_front' => 42,
                'page_for_posts' => 0,
                default => $default,
            };
        });

        Functions\when('get_post')->alias(function ($id) {
            if ((int) $id === 42) {
                return new \WP_Post(['ID' => 42, 'post_type' => 'page', 'post_name' => 'front']);
            }
            if ((int) $id === 77) {
                return new \WP_Post(['ID' => 77, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'post_excerpt' => 'cap']);
            }
            return null;
        });
        Functions\when('wp_get_attachment_url')->justReturn('https://example.test/wp-content/uploads/2026/01/hero.jpg');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        \TAW\Core\Metabox\Metabox::resetRegistryForTests();
        \TAW\Core\Metabox\Metabox::forgetInstances();
        parent::tearDown();
    }

    public function test_snapshot_has_meta_posts_options_and_media(): void
    {
        $snapshot = (new Exporter())->snapshot();

        $this->assertSame('1.2', $snapshot['meta']['schema']);
        $this->assertSame('https://example.test', $snapshot['meta']['source']['url']);

        $post = $snapshot['posts'][0];
        $this->assertSame('home', $post['slug']);
        $this->assertSame([['name' => 'Ada']], $post['fields']['team']);
        $this->assertSame(77, $post['fields']['hero']);
    }

    public function test_fields_with_another_prefix_are_keyed_by_meta_key(): void
    {
        new \TAW\Core\Metabox\Metabox([
            'id' => 'page_extra', 'title' => 'Extra', 'screens' => 'page', 'prefix' => '_page_',
            'fields' => [['id' => 'featured', 'type' => 'checkbox'], ['id' => 'rank', 'type' => 'number']],
        ]);
        Functions\when('get_post_meta')->alias(function ($id, $key = '', $single = false) {
            if ($id === 10 && $key === '') {
                return [
                    '_taw_hero'     => ['77'],
                    '_page_featured' => ['1'],
                    '_page_rank'     => ['3'],
                    '_page_unknown'  => ['x'],
                    '_edit_lock'     => ['1:1'],
                ];
            }
            return $single ? '' : [];
        });

        $fields = (new Exporter())->snapshot(['include_media' => false])['posts'][0]['fields'];

        $keys = array_keys($fields);
        sort($keys);
        $this->assertSame(['_page_featured', '_page_rank', 'hero'], $keys, 'registered _page_ fields by meta key, _taw_ by bare id, nothing else');
        $this->assertTrue($fields['_page_featured'], 'decoded by its own type');
        $this->assertSame(77, $fields['hero']);
    }

    public function test_options_include_taw_and_resolved_core_allowlist(): void
    {
        $options = (new Exporter())->snapshot()['options'];

        $this->assertSame('555-1234', $options['_taw_company_phone']);
        $this->assertSame('Example', $options['blogname']);
        $this->assertSame('front', $options['page_on_front'], 'page_on_front resolves to the page slug');
        $this->assertNull($options['page_for_posts']);
    }

    public function test_referenced_image_becomes_a_media_entry(): void
    {
        $media = (new Exporter())->snapshot()['media'];

        $this->assertCount(1, $media);
        $this->assertSame(77, $media[0]['id']);
        $this->assertSame('hero.jpg', $media[0]['ref']);
        $this->assertSame('Hero alt', $media[0]['alt']);
        $this->assertSame('image/jpeg', $media[0]['mime']);
    }

    public function test_no_media_scope_omits_the_section(): void
    {
        $snapshot = (new Exporter())->snapshot(['include_media' => false]);

        $this->assertArrayNotHasKey('media', $snapshot);
    }

    public function test_post_record_carries_author_and_comment_status(): void
    {
        Functions\when('get_userdata')->alias(static fn ($id) => (int) $id === 5
            ? (object) ['user_login' => 'ada', 'user_email' => 'ada@example.test']
            : false);

        $page = new \WP_Post([
            'ID' => 10, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish',
            'post_title' => 'Home', 'post_excerpt' => '', 'post_content' => '', 'post_parent' => 0,
            'menu_order' => 0, 'post_date_gmt' => '2026-01-01 00:00:00', 'post_author' => 5,
            'comment_status' => 'closed', 'ping_status' => 'closed',
        ]);
        Functions\when('get_posts')->justReturn([$page]);

        $post = (new Exporter())->snapshot()['posts'][0];

        $this->assertSame(['login' => 'ada', 'email' => 'ada@example.test'], $post['author']);
        $this->assertSame('closed', $post['comment_status']);
        $this->assertSame('closed', $post['ping_status']);
    }

    public function test_with_users_adds_a_users_section_without_passwords(): void
    {
        Functions\when('get_users')->justReturn([
            (object) [
                'ID' => 1, 'user_login' => 'admin', 'user_email' => 'a@example.test',
                'display_name' => 'Admin', 'roles' => ['administrator'],
                'user_registered' => '2025-01-01 00:00:00', 'user_pass' => '$P$SECRET',
            ],
        ]);
        Functions\when('get_user_meta')->justReturn('');

        $snapshot = (new Exporter())->snapshot(['include_users' => true, 'include_media' => false]);

        $this->assertCount(1, $snapshot['users']);
        $this->assertSame('admin', $snapshot['users'][0]['login']);
        $this->assertSame(['administrator'], $snapshot['users'][0]['roles']);
        $this->assertArrayNotHasKey('password_hash', $snapshot['users'][0]);
    }

    public function test_with_user_passwords_includes_the_hash(): void
    {
        Functions\when('get_users')->justReturn([
            (object) [
                'ID' => 1, 'user_login' => 'admin', 'user_email' => 'a@example.test',
                'display_name' => 'Admin', 'roles' => ['administrator'],
                'user_registered' => '2025-01-01 00:00:00', 'user_pass' => '$P$SECRET',
            ],
        ]);
        Functions\when('get_user_meta')->justReturn('');

        $users = (new Exporter())->snapshot([
            'include_users' => true, 'include_user_passwords' => true, 'include_media' => false,
        ])['users'];

        $this->assertSame('$P$SECRET', $users[0]['password_hash']);
    }

    public function test_drafts_are_excluded_by_default_and_included_with_the_flag(): void
    {
        $captured = [];
        Functions\when('get_posts')->alias(static function (array $args) use (&$captured) {
            $captured[] = $args['post_status'] ?? null;
            return [];
        });

        (new Exporter())->snapshot(['include_media' => false]);
        (new Exporter())->snapshot(['include_media' => false, 'include_drafts' => true]);

        $this->assertNotContains('draft', $captured[0]);
        $this->assertContains('draft', $captured[1]);
    }

    public function test_a_non_public_cpt_with_a_metabox_is_still_exported(): void
    {
        // 'activity' is registered but public => false; a TAW Metabox targets it.
        Functions\when('post_type_exists')->alias(static fn (string $t): bool => in_array($t, ['page', 'post', 'activity'], true));
        new \TAW\Core\Metabox\Metabox([
            'id' => 'taw_activity', 'title' => 'Activity', 'screens' => 'activity',
            'fields' => [['id' => 'starts_at', 'type' => 'text']],
        ]);

        $captured = [];
        Functions\when('get_posts')->alias(static function (array $args) use (&$captured) {
            $captured = $args['post_type'] ?? [];
            return [];
        });

        (new Exporter())->snapshot(['include_media' => false]);

        $this->assertContains('activity', $captured);
    }

    public function test_slugless_post_gets_a_composite_match_key(): void
    {
        $draft = new \WP_Post([
            'ID' => 30, 'post_type' => 'post', 'post_name' => '', 'post_status' => 'draft',
            'post_title' => 'Untitled note', 'post_excerpt' => '', 'post_content' => '', 'post_parent' => 0,
            'menu_order' => 0, 'post_date_gmt' => '2026-02-02 12:00:00',
        ]);
        Functions\when('get_posts')->justReturn([$draft]);

        $post = (new Exporter())->snapshot(['include_drafts' => true, 'include_media' => false])['posts'][0];

        $this->assertSame('', $post['slug']);
        $this->assertSame(sha1('post|Untitled note|2026-02-02 12:00:00'), $post['match_key']);
    }
}
