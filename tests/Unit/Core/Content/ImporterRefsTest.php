<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use Brain\Monkey\Functions;
use TAW\Core\Content\ChangeSet;
use TAW\Core\Content\Importer;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * Content Interchange fidelity, phases 2+3, through the importer: a
 * snapshot from another site (other IDs, another origin) imports with its
 * references mapped, and re-planning it reports nothing to change.
 */
final class ImporterRefsTest extends TestCase
{
    /** @var array<int, \WP_Post> this fake site's posts */
    private array $posts = [];

    /** @var array<string, mixed> "id|key" => value */
    private array $meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private int $nextId = 100;

    /** @var list<int> */
    private array $thumbnailsRemoved = [];

    /** @var array<int, string> this site's attachments: id => path under uploads */
    private array $attachments = [];

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->alias(static fn (string $t): bool => in_array($t, ['page', 'post'], true));
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page']);
        Functions\when('add_action')->justReturn(true);
        Functions\when('home_url')->justReturn('http://site.local');
        Functions\when('wp_json_encode')->alias(static fn ($v, int $f = 0) => json_encode($v, $f));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('absint')->alias(static fn ($v): int => abs((int) $v));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('get_page_template_slug')->justReturn('');
        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('get_date_from_gmt')->returnArg(1);
        Functions\when('get_post_field')->justReturn('');
        Functions\when('get_post_thumbnail_id')->alias(fn ($p): int => (int) ($this->meta[(is_object($p) ? $p->ID : $p) . '|_thumbnail_id'] ?? 0));
        Functions\when('delete_post_thumbnail')->alias(function (int $id): bool {
            $this->thumbnailsRemoved[] = $id;
            unset($this->meta["{$id}|_thumbnail_id"]);
            return true;
        });
        Functions\when('get_post')->alias(fn ($id) => $this->posts[(int) $id] ?? null);
        Functions\when('wp_basename')->alias(static fn (string $p): string => basename($p));
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('wp_get_attachment_url')->alias(fn (int $id): string => 'http://site.local/wp-content/uploads/' . ($this->attachments[$id] ?? ''));
        Functions\when('get_posts')->alias(function (array $q): array {
            if (($q['post_type'] ?? '') === 'attachment') {
                $want = (string) ($q['meta_query'][0]['value'] ?? '');
                $exact = ($q['meta_query'][0]['compare'] ?? '=') === '=';
                return array_keys(array_filter($this->attachments, static fn (string $file): bool => $exact
                    ? $file === $want : str_contains('/' . $file, $want)));
            }
            $slugs = $q['post_name__in'] ?? null;
            return array_values(array_filter($this->posts, static fn (\WP_Post $p): bool => is_array($slugs)
                && in_array($p->post_name, $slugs, true) && in_array($p->post_type, (array) ($q['post_type'] ?? []), true)));
        });
        Functions\when('get_post_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->meta["{$id}|{$key}"] ?? '');
        Functions\when('update_post_meta')->alias(function (int $id, string $key, $value): bool {
            $this->meta["{$id}|{$key}"] = $value;
            return true;
        });
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('wp_insert_post')->alias(function (array $a): int {
            $id = $this->nextId++;
            $this->posts[$id] = new \WP_Post(['ID' => $id, 'post_type' => $a['post_type'], 'post_name' => $a['post_name'],
                'post_title' => $a['post_title'] ?? '', 'post_content' => $a['post_content'] ?? '', 'post_excerpt' => $a['post_excerpt'] ?? '',
                'post_status' => $a['post_status'] ?? 'publish']);
            return $id;
        });
        Functions\when('wp_update_post')->alias(function (array $a): int {
            foreach (['post_content', 'post_excerpt', 'post_title'] as $k) {
                if (array_key_exists($k, $a)) {
                    $this->posts[$a['ID']]->{$k} = $a[$k];
                }
            }
            return (int) $a['ID'];
        });
        Functions\when('get_option')->alias(fn (string $k, $d = false) => array_key_exists($k, $this->options) ? $this->options[$k] : $d);
        Functions\when('update_option')->alias(function (string $k, $v): bool {
            $this->options[$k] = $v;
            return true;
        });

        new Metabox(['id' => 'taw_home', 'title' => 'Home', 'screens' => ['page'], 'fields' => [
            ['id' => 'featured_post', 'type' => 'post_select'],
            ['id' => 'cta_url', 'type' => 'url'],
        ]]);
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        parent::tearDown();
    }

    private function sitePost(int $id, string $type, string $slug, string $content = ''): void
    {
        $this->posts[$id] = new \WP_Post(['ID' => $id, 'post_type' => $type, 'post_name' => $slug, 'post_title' => ucfirst($slug),
            'post_content' => $content, 'post_excerpt' => '', 'post_status' => 'publish', 'menu_order' => 0,
            'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_parent' => 0]);
    }

    /** A snapshot of the production site: other IDs, another origin. */
    private function production(array $posts): array
    {
        return [
            'meta'  => ['schema' => '1.4', 'source' => ['url' => 'https://prod.test']],
            'refs'  => ['posts' => [135 => ['type' => 'post', 'slug' => 'studio-pipeline'], 150 => ['type' => 'post', 'slug' => 'later']]],
            'posts' => $posts,
        ];
    }

    public function test_a_cross_site_snapshot_already_in_place_reports_no_changes(): void
    {
        $this->sitePost(78, 'post', 'studio-pipeline');
        $this->sitePost(5, 'page', 'home', '<a href="http://site.local/about/">About</a>');
        $this->meta['5|_taw_featured_post'] = '78';
        $this->meta['5|_taw_cta_url'] = 'http://site.local/contacto/';

        $plan = (new Importer())->plan($this->production([[
            'type' => 'page', 'slug' => 'home', 'title' => 'Home',
            'content' => '<a href="https://prod.test/about/">About</a>',
            'fields' => ['featured_post' => 135, 'cta_url' => 'https://prod.test/contacto/'],
        ]]));

        $this->assertSame([], $plan['records'][0]['changes'], "prod's 135 is this site's 78, and its URLs are this site's");
    }

    public function test_an_empty_discussion_status_reads_as_the_default_wordpress_stores(): void
    {
        $this->sitePost(5, 'post', 'news');
        Functions\when('get_default_comment_status')->justReturn('closed');

        $plan = (new Importer())->plan($this->production([[
            'type' => 'post', 'slug' => 'news', 'title' => 'News', 'content' => '', 'ping_status' => '', 'comment_status' => '',
        ]]));

        $this->assertSame([], $plan['records'][0]['changes'], "WordPress saves '' as the default, so a pull would never settle");
    }

    public function test_the_import_maps_ids_and_urls(): void
    {
        $this->sitePost(78, 'post', 'studio-pipeline');
        $this->sitePost(5, 'page', 'home');

        (new Importer())->apply($this->production([[
            'type' => 'page', 'slug' => 'home', 'title' => 'Home',
            'content' => '<a href="https://prod.test/about/">About</a>',
            'fields' => ['featured_post' => 135, 'cta_url' => 'https://prod.test/contacto/'],
        ]]), ['rollback' => false]);

        $this->assertSame('78', $this->meta['5|_taw_featured_post']);
        $this->assertSame('http://site.local/contacto/', $this->meta['5|_taw_cta_url']);
        $this->assertSame('<a href="http://site.local/about/">About</a>', $this->posts[5]->post_content);
    }

    public function test_a_post_created_later_in_the_run_is_linked_on_a_second_pass(): void
    {
        $report = (new Importer())->apply($this->production([
            ['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'fields' => ['featured_post' => 150]],
            ['type' => 'post', 'slug' => 'later', 'title' => 'Later'],
        ]), ['rollback' => false]);

        $later = array_values(array_filter($this->posts, static fn ($p) => $p->post_name === 'later'))[0];
        $home = array_values(array_filter($this->posts, static fn ($p) => $p->post_name === 'home'))[0];
        $this->assertSame((string) $later->ID, $this->meta["{$home->ID}|_taw_featured_post"]);
        $this->assertStringNotContainsString('dropped', implode("\n", $report['warnings']));
    }

    public function test_a_page_already_here_is_linked_to_a_post_the_import_creates(): void
    {
        $this->sitePost(5, 'page', 'home');

        (new Importer())->apply($this->production([
            ['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'fields' => ['featured_post' => 150]],
            ['type' => 'post', 'slug' => 'later', 'title' => 'Later'],
        ]), ['rollback' => false]);

        $later = array_values(array_filter($this->posts, static fn ($p) => $p->post_name === 'later'))[0];
        $this->assertSame((string) $later->ID, $this->meta['5|_taw_featured_post'] ?? null,
            "planned before 'later' existed, the link read as empty = unchanged, and the page was skipped");
    }

    public function test_a_reference_this_site_lacks_is_dropped_with_a_warning(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->meta['5|_taw_featured_post'] = '42';

        $report = (new Importer())->apply($this->production([
            ['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'fields' => ['featured_post' => 135]],
        ]), ['rollback' => false]);

        $this->assertSame('', $this->meta['5|_taw_featured_post'], 'cleared, not left pointing at an unrelated post');
        $this->assertStringContainsString('post:studio-pipeline — not on this site, dropped', implode("\n", $report['warnings']));
    }

    public function test_no_featured_image_at_the_source_removes_this_sites(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->meta['5|_thumbnail_id'] = 9;
        $this->meta['9|_wp_attached_file'] = '2024/05/hero.jpg';
        Functions\when('wp_basename')->alias(static fn (string $p): string => basename($p));

        $plan = (new Importer())->plan($this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => null]]));
        $this->assertArrayHasKey('featured_media', $plan['records'][0]['changes']);

        (new Importer())->apply($this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => null]]), ['rollback' => false]);
        $this->assertSame([5], $this->thumbnailsRemoved);
    }

    public function test_option_urls_are_mapped(): void
    {
        (new Importer())->apply([
            'meta'    => ['schema' => '1.4', 'source' => ['url' => 'https://prod.test']],
            'options' => ['_taw_footer_link' => 'https://prod.test/aviso/', 'blogname' => 'https://prod.test is my site'],
        ], ['rollback' => false]);

        $this->assertSame('http://site.local/aviso/', $this->options['_taw_footer_link']);
        $this->assertSame('https://prod.test is my site', $this->options['blogname'], 'core options pass through');
    }

    /** An attachment of this fake site, with its metadata. */
    private function siteAttachment(int $id, string $path, string $alt = '', string $caption = ''): void
    {
        $this->attachments[$id] = $path;
        $this->meta["{$id}|_wp_attached_file"] = $path;
        $this->meta["{$id}|_wp_attachment_image_alt"] = $alt;
        $this->posts[$id] = new \WP_Post(['ID' => $id, 'post_type' => 'attachment', 'post_name' => basename($path), 'post_title' => basename($path),
            'post_excerpt' => $caption, 'post_content' => '', 'post_status' => 'inherit']);
    }

    public function test_the_plan_lists_media_to_download_missing_and_changed(): void
    {
        $this->siteAttachment(900, '2024/05/hero.jpg', 'Old alt');
        $plan = (new Importer())->plan([
            'meta'  => ['schema' => '1.4', 'source' => ['url' => 'https://prod.test']],
            'media' => [
                ['id' => 11, 'filename' => 'hero.jpg', 'url' => 'https://prod.test/wp-content/uploads/2024/05/hero.jpg', 'title' => 'hero.jpg', 'alt' => 'A field at dawn', 'caption' => 'Photo: <a href="https://x.test">Ana</a>'],
                ['id' => 12, 'filename' => 'new.jpg', 'url' => 'https://prod.test/wp-content/uploads/2026/10/new.jpg'],
                ['id' => 13, 'filename' => 'gone.jpg'],
            ],
        ]);

        $media = array_values(array_filter($plan['records'], static fn (array $r): bool => $r['kind'] === 'media'));
        $this->assertSame(['update', 'create', 'missing'], array_column($media, 'op'));
        $this->assertSame(['caption', 'alt'], array_keys($media[0]['changes']), 'the title already matches');
        $this->assertSame('Photo: <a href="https://x.test">Ana</a>', $media[0]['changes']['caption']['new'], 'the caption keeps its link');
        $this->assertSame('2026/10/new.jpg', $media[1]['key']);
        $this->assertSame('missing', $media[2]['changes']['file']['status']);
    }

    public function test_apply_updates_media_metadata_and_clears_what_points_at_a_missing_file(): void
    {
        $this->siteAttachment(900, '2024/05/hero.jpg', 'Old alt');
        $this->sitePost(5, 'page', 'home');
        new Metabox(['id' => 'taw_media_test', 'title' => 'Media', 'screens' => ['page'], 'fields' => [['id' => 'hero_image', 'type' => 'image']]]);
        $this->meta['5|_taw_hero_image'] = '777';
        Functions\when('parse_blocks')->justReturn([['blockName' => 'core/image', 'attrs' => ['id' => 13], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]]);
        Functions\when('serialize_blocks')->alias(static fn (array $b): string => '<!-- wp:image ' . json_encode($b[0]['attrs'] ?: new \stdClass()) . ' -->');

        $report = (new Importer())->apply([
            'meta'  => ['schema' => '1.4', 'source' => ['url' => 'https://prod.test']],
            'media' => [
                ['id' => 11, 'filename' => 'hero.jpg', 'url' => 'https://prod.test/wp-content/uploads/2024/05/hero.jpg', 'alt' => 'A field at dawn'],
                ['id' => 13, 'filename' => 'gone.jpg'],
            ],
            'posts' => [['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'fields' => ['hero_image' => 13],
                'content' => '<!-- wp:image {"id":13} --><figure><img class="wp-image-13"/></figure><!-- /wp:image -->']],
        ], ['rollback' => false]);

        $this->assertSame('A field at dawn', $this->meta['900|_wp_attachment_image_alt']);
        $this->assertContains('media:2024/05/hero.jpg', $report['updated']);
        $this->assertSame('0', (string) $this->meta['5|_taw_hero_image'], 'cleared, not left pointing at an unrelated attachment 777 or 13');
        $this->assertSame('<!-- wp:image {} -->', $this->posts[5]->post_content, "the image block's id is dropped");
        $this->assertStringContainsString('gone.jpg', implode("\n", $report['warnings']));
    }

    public function test_a_front_page_this_site_lacks_is_never_written_as_zero(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->options['page_on_front'] = 5;
        $input = $this->production([]) + ['options' => ['page_on_front' => 'gone']];

        $plan = (new Importer())->plan($input);
        $this->assertSame([], $plan['records'][0]['changes']);
        $this->assertSame(["option:page_on_front: 'gone' isn't on this site or in the import — kept as it is."], $plan['warnings']);

        (new Importer())->apply($input, ['rollback' => false]);
        $this->assertSame(5, $this->options['page_on_front']);
    }

    public function test_a_front_page_the_import_brings_is_set(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->options['page_on_front'] = 5;
        $input = $this->production([['type' => 'page', 'slug' => 'start', 'title' => 'Start']]) + ['options' => ['page_on_front' => 'start']];

        $records = (new Importer())->plan($input)['records'];
        $this->assertSame('changed', end($records)['changes']['value']['status']);

        (new Importer())->apply($input, ['rollback' => false]);
        $start = array_values(array_filter($this->posts, static fn ($p) => $p->post_name === 'start'))[0];
        $this->assertSame($start->ID, $this->options['page_on_front']);
    }

    public function test_a_site_without_a_front_page_gets_the_one_the_import_brings(): void
    {
        $this->options['page_on_front'] = '0';
        $this->options['sticky_posts'] = [];
        $input = $this->production([['type' => 'page', 'slug' => 'start', 'title' => 'Start'], ['type' => 'post', 'slug' => 'pinned', 'title' => 'Pinned']])
            + ['options' => ['page_on_front' => 'start', 'sticky_posts' => ['pinned']]];

        (new Importer())->apply($input, ['rollback' => false, 'include_settings' => true]); // sticky_posts is a setting

        $ids = array_column(array_map(static fn ($p) => ['slug' => $p->post_name, 'id' => $p->ID], $this->posts), 'id', 'slug');
        $this->assertSame($ids['start'], $this->options['page_on_front'], "'start' resolved to 0 before it existed, which read as unchanged");
        $this->assertSame([$ids['pinned']], $this->options['sticky_posts']);
    }

    public function test_an_option_the_source_never_set_is_not_created(): void
    {
        $plan = (new Importer())->plan($this->production([]) + ['options' => ['WPLANG' => false]]);

        $this->assertSame([], $plan['records'][0]['changes'], 'get_option() returned false at the source: nothing to write');
    }

    public function test_sticky_posts_this_site_lacks_are_left_out_without_a_change_forever(): void
    {
        $this->sitePost(78, 'post', 'studio-pipeline');
        $this->options['sticky_posts'] = [78];
        $plan = (new Importer())->plan($this->production([]) + ['options' => ['sticky_posts' => ['studio-pipeline', 'gone']]]);

        $this->assertSame([], $plan['records'][0]['changes']);
        $this->assertSame(["option:sticky_posts: 'gone' isn't on this site or in the import — left out."], $plan['warnings']);
    }

    public function test_a_change_set_maps_references_like_a_snapshot(): void
    {
        $this->sitePost(78, 'post', 'studio-pipeline');
        $this->sitePost(5, 'page', 'home');
        $base = ['meta' => ['schema' => '1.6'], 'posts' => [['type' => 'page', 'slug' => 'home', 'title' => 'Home']]];
        $target = $this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home',
            'fields' => ['featured_post' => 135, 'cta_url' => 'https://prod.test/contacto/']]]);

        (new Importer())->apply(ChangeSet::between($base, $target), ['rollback' => false]);

        $this->assertSame('78', $this->meta['5|_taw_featured_post'], "the source's 135 is this site's 78");
        $this->assertSame('http://site.local/contacto/', $this->meta['5|_taw_cta_url']);
    }

    public function test_a_featured_image_whose_copy_was_renamed_is_found_through_the_media_map(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->attachments[31] = '2026/10/photo-1.jpg';
        $input = $this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => 'photo.jpg']])
            + ['media' => [['id' => 50, 'filename' => 'photo.jpg', 'url' => 'https://prod.test/wp-content/uploads/2026/10/photo-1.jpg']]];
        Functions\when('set_post_thumbnail')->alias(function (int $post, int $att): bool {
            $this->meta["{$post}|_thumbnail_id"] = $att;
            return true;
        });

        $report = (new Importer())->apply($input, ['rollback' => false]);

        $this->assertSame(31, $this->meta['5|_thumbnail_id']);
        $this->assertSame([], $report['warnings']);
        $this->assertSame([], (new Importer())->plan($input)['records'][0]['changes'] ?? [], 'and the next preview has nothing to change');
    }

    public function test_a_record_that_fails_is_reported_and_the_rest_carry_on(): void
    {
        Functions\when('wp_insert_post')->alias(function (array $a): int {
            if ($a['post_name'] === 'broken') {
                throw new \RuntimeException('database went away');
            }
            $id = $this->nextId++;
            $this->posts[$id] = new \WP_Post(['ID' => $id, 'post_type' => $a['post_type'], 'post_name' => $a['post_name'], 'post_title' => $a['post_title'] ?? '',
                'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish']);
            return $id;
        });

        $report = (new Importer())->apply($this->production([
            ['type' => 'page', 'slug' => 'broken', 'title' => 'Broken'],
            ['type' => 'page', 'slug' => 'fine', 'title' => 'Fine'],
        ]), ['rollback' => false]);

        $this->assertSame(['post:page:broken: database went away'], $report['failed']);
        $this->assertSame(['page:fine'], $report['created']);
    }

    public function test_nothing_is_imported_without_a_rollback_snapshot(): void
    {
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/nonexistent/uploads']);
        Functions\when('trailingslashit')->alias(static fn (string $p): string => rtrim($p, '/') . '/');
        Functions\when('wp_mkdir_p')->justReturn(false);
        Functions\when('wp_insert_post')->alias(static function (): int {
            throw new \LogicException('nothing may be written');
        });

        $report = (new Importer())->apply($this->production([['type' => 'page', 'slug' => 'new', 'title' => 'New']]));

        $this->assertStringContainsString("Couldn't write the rollback snapshot or the import journal", (string) $report['error']);
        $this->assertSame([], $report['created']);
        $this->assertNull($report['journal']);
    }

    // ── Two files with one name (1.9): a featured image goes by its path ───────

    /**
     * Production's two `chc.webp`, in the order that once picked the wrong
     * one: a bare-name lookup took whichever entry came last.
     *
     * @return list<array<string, mixed>>
     */
    private static function twoFilesNamedAlike(): array
    {
        return [
            ['id' => 5716, 'filename' => 'chc.webp', 'url' => 'https://prod.test/wp-content/uploads/2026/06/chc.webp'],
            ['id' => 5888, 'filename' => 'chc.webp', 'url' => 'https://prod.test/wp-content/uploads/2026/08/chc.webp'],
        ];
    }

    private function recordThumbnails(): void
    {
        Functions\when('set_post_thumbnail')->alias(function (int $post, int $att): bool {
            $this->meta["{$post}|_thumbnail_id"] = $att;
            return true;
        });
    }

    public function test_a_featured_image_resolves_by_its_path_when_two_files_share_its_name(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->siteAttachment(61, '2026/06/chc.webp');
        $this->siteAttachment(62, '2026/08/chc.webp');
        $this->recordThumbnails();
        foreach (['2026/06/chc.webp' => 61, '2026/08/chc.webp' => 62] as $path => $expected) {
            $input = $this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => $path]])
                + ['media' => self::twoFilesNamedAlike()];

            $report = (new Importer())->apply($input, ['rollback' => false]);

            $this->assertSame($expected, $this->meta['5|_thumbnail_id'], "{$path} is attachment {$expected}, whatever the entries' order");
            $this->assertSame([], $report['warnings']);
            $this->assertSame([], (new Importer())->plan($input)['records'][0]['changes'] ?? [], 'and the next preview has nothing to change');
        }
    }

    public function test_the_same_featured_image_on_both_sides_is_no_change(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->siteAttachment(61, '2026/06/chc.webp');
        $this->siteAttachment(62, '2026/08/chc.webp');
        $this->meta['5|_thumbnail_id'] = 61;

        $plan = (new Importer())->plan($this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => '2026/06/chc.webp']])
            + ['media' => self::twoFilesNamedAlike()]);
        $this->assertArrayNotHasKey('featured_media', $plan['records'][0]['changes'] ?? []);

        $plan = (new Importer())->plan($this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => '2026/08/chc.webp']])
            + ['media' => self::twoFilesNamedAlike()]);
        $this->assertSame(['status' => 'changed', 'old' => '2026/06/chc.webp', 'new' => '2026/08/chc.webp'],
            array_intersect_key($plan['records'][0]['changes']['featured_media'], array_flip(['status', 'old', 'new'])),
            'the other file of that name is a change, shown by path');
    }

    public function test_a_bare_name_that_names_two_files_warns_and_picks_neither(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->siteAttachment(61, '2026/06/chc.webp');
        $this->siteAttachment(62, '2026/08/chc.webp');
        $this->recordThumbnails();
        // A snapshot from before 1.9 names the file only.
        $input = $this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => 'chc.webp']])
            + ['media' => self::twoFilesNamedAlike()];

        $report = (new Importer())->apply($input, ['rollback' => false]);

        $this->assertArrayNotHasKey('5|_thumbnail_id', $this->meta, 'no silent pick');
        $this->assertStringContainsString("Featured image 'chc.webp' names more than one file", implode("\n", $report['warnings']));

        $this->meta['5|_thumbnail_id'] = 61;
        $this->assertArrayNotHasKey('featured_media', (new Importer())->plan($input)['records'][0]['changes'] ?? [],
            'a post already showing a file of that name has nothing to change');
        $report = (new Importer())->apply($input, ['rollback' => false]);
        $this->assertSame(61, $this->meta['5|_thumbnail_id']);
        $this->assertSame([], $report['warnings'], 'and nothing to warn about');
    }

    public function test_a_bare_name_that_names_one_file_still_resolves(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->siteAttachment(61, '2026/06/chc.webp');
        $this->recordThumbnails();

        $report = (new Importer())->apply($this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => 'chc.webp']]),
            ['rollback' => false]);

        $this->assertSame(61, $this->meta['5|_thumbnail_id']);
        $this->assertSame([], $report['warnings']);
    }

    public function test_setting_a_featured_image_leaves_one_thumbnail_row(): void
    {
        $this->sitePost(5, 'page', 'home');
        $this->siteAttachment(61, '2026/06/chc.webp');
        $this->siteAttachment(62, '2026/08/chc.webp');
        // An old migration left four rows; update_post_meta() alone rewrites all four.
        $rows = [62, 62, 62, 62];
        Functions\when('get_post_meta')->alias(function (int $id, string $key = '', bool $single = false) use (&$rows) {
            if ($id === 5 && $key === '_thumbnail_id') {
                return $single ? ($rows[0] ?? '') : $rows;
            }
            return $this->meta["{$id}|{$key}"] ?? '';
        });
        Functions\when('get_post_thumbnail_id')->alias(static fn () => $rows[0] ?? 0);
        Functions\when('delete_post_meta')->alias(static function (int $id, string $key) use (&$rows): bool {
            if ($id === 5 && $key === '_thumbnail_id') {
                $rows = [];
            }
            return true;
        });
        Functions\when('set_post_thumbnail')->alias(static function (int $post, int $att) use (&$rows): bool {
            $rows = $rows === [] ? [$att] : array_fill(0, count($rows), $att);
            return true;
        });

        (new Importer())->apply($this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'featured_media' => '2026/06/chc.webp']])
            + ['media' => self::twoFilesNamedAlike()], ['rollback' => false]);

        $this->assertSame([61], $rows);
    }

    public function test_an_author_this_site_lacks_leaves_an_existing_posts_author(): void
    {
        $this->sitePost(5, 'page', 'home');
        Functions\when('get_user_by')->justReturn(false);
        $written = [];
        Functions\when('wp_update_post')->alias(static function (array $a) use (&$written): int {
            $written = $a;
            return (int) $a['ID'];
        });

        $report = (new Importer())->apply($this->production([['type' => 'page', 'slug' => 'home', 'title' => 'Home', 'content' => 'New',
            'author' => ['login' => 'ch-capital', 'email' => 'ch@prod.test']]]), ['rollback' => false]);

        $this->assertArrayNotHasKey('post_author', $written, 'not handed to whoever ran the import');
        $this->assertStringContainsString("author 'ch-capital' not found on this site — the post keeps its author", implode("\n", $report['warnings']));
    }
}

