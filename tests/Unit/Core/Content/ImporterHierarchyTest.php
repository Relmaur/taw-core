<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use Brain\Monkey\Functions;
use TAW\Core\Content\ChangeSet;
use TAW\Core\Content\Importer;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * Content Interchange fidelity, phase 5: pages keyed by their path, parents
 * set (and cleared) whatever order the source lists them in, term parents
 * and term meta.
 */
final class ImporterHierarchyTest extends TestCase
{
    /** @var array<int, \WP_Post> */
    private array $posts = [];

    /** @var array<int, \WP_Term> */
    private array $terms = [];

    /** @var array<string, mixed> "termId|key" => value */
    private array $termMeta = [];

    private int $nextId = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Metabox::resetRegistryForTests();
        Functions\when('post_type_exists')->justReturn(true);
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page']);
        Functions\when('is_post_type_hierarchical')->alias(static fn (string $t): bool => $t === 'page');
        Functions\when('add_action')->justReturn(true);
        Functions\when('home_url')->justReturn('http://site.local');
        Functions\when('wp_json_encode')->alias(static fn ($v, int $f = 0) => json_encode($v, $f));
        Functions\when('apply_filters')->alias(static fn (string $h, $v = null) => $v);
        Functions\when('wp_slash')->returnArg(1);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('get_page_template_slug')->justReturn('');
        Functions\when('taxonomy_exists')->justReturn(true);
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('get_date_from_gmt')->returnArg(1);
        Functions\when('get_post_field')->alias(fn (string $f, int $id) => $this->posts[$id]->{$f} ?? '');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post')->alias(fn ($id) => $this->posts[(int) $id] ?? null);
        Functions\when('get_page_uri')->alias(fn ($p): string => $this->uri(is_object($p) ? $p : $this->posts[(int) $p]));
        Functions\when('get_page_by_path')->alias(function (string $path, $output, string $type) {
            foreach ($this->posts as $post) {
                if ($post->post_type === $type && $this->uri($post) === $path) {
                    return $post;
                }
            }
            return null;
        });
        Functions\when('get_posts')->alias(function (array $q): array {
            $slugs = (array) ($q['post_name__in'] ?? []);
            $found = array_values(array_filter($this->posts, static fn (\WP_Post $p): bool => in_array($p->post_name, $slugs, true)
                && in_array($p->post_type, (array) ($q['post_type'] ?? []), true)));
            return array_slice($found, 0, (int) ($q['posts_per_page'] ?? 10));
        });
        Functions\when('wp_insert_post')->alias(function (array $a): int {
            $id = $this->nextId++;
            $this->posts[$id] = new \WP_Post(['ID' => $id, 'post_type' => $a['post_type'], 'post_name' => $a['post_name'],
                'post_title' => $a['post_title'] ?? '', 'post_content' => $a['post_content'] ?? '', 'post_excerpt' => '',
                'post_status' => $a['post_status'] ?? 'publish', 'menu_order' => 0, 'comment_status' => 'closed',
                'ping_status' => 'closed', 'post_password' => '', 'post_parent' => (int) ($a['post_parent'] ?? 0), 'post_date_gmt' => '']);
            return $id;
        });
        Functions\when('wp_update_post')->alias(function (array $a): int {
            foreach (['post_content', 'post_title', 'post_name', 'post_parent'] as $k) {
                if (array_key_exists($k, $a)) {
                    $this->posts[$a['ID']]->{$k} = $a[$k];
                }
            }
            return (int) $a['ID'];
        });

        Functions\when('get_term_by')->alias(function (string $field, string $slug, string $taxonomy) {
            foreach ($this->terms as $term) {
                if ($term->taxonomy === $taxonomy && $term->slug === $slug) {
                    return $term;
                }
            }
            return false;
        });
        Functions\when('get_term')->alias(fn ($id) => $this->terms[(int) $id] ?? null);
        Functions\when('wp_insert_term')->alias(function (string $name, string $taxonomy, array $args): array {
            $id = $this->nextId++;
            $this->terms[$id] = $this->term($id, $taxonomy, (string) $args['slug'], (int) ($args['parent'] ?? 0));
            return ['term_id' => $id, 'term_taxonomy_id' => $id];
        });
        Functions\when('wp_update_term')->alias(function (int $id, string $taxonomy, array $args): array {
            if (array_key_exists('parent', $args)) {
                $this->terms[$id]->parent = (int) $args['parent'];
            }
            return ['term_id' => $id];
        });
        Functions\when('get_term_meta')->alias(fn (int $id, string $key = '', bool $single = false) => $this->termMeta["{$id}|{$key}"] ?? '');
        Functions\when('update_term_meta')->alias(function (int $id, string $key, $value): bool {
            $this->termMeta["{$id}|{$key}"] = $value;
            return true;
        });
        Functions\when('is_serialized')->alias(static fn ($v): bool => is_string($v) && @unserialize($v) !== false);
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        parent::tearDown();
    }

    private function uri(\WP_Post $post): string
    {
        $parent = $this->posts[(int) $post->post_parent] ?? null;

        return ($parent ? $this->uri($parent) . '/' : '') . $post->post_name;
    }

    private function page(int $id, string $slug, int $parent = 0): void
    {
        $this->posts[$id] = new \WP_Post(['ID' => $id, 'post_type' => 'page', 'post_name' => $slug, 'post_title' => ucfirst($slug),
            'post_content' => '', 'post_excerpt' => '', 'post_status' => 'publish', 'menu_order' => 0, 'comment_status' => 'closed',
            'ping_status' => 'closed', 'post_password' => '', 'post_parent' => $parent, 'post_date_gmt' => '']);
    }

    private function term(int $id, string $taxonomy, string $slug, int $parent = 0): \WP_Term
    {
        return new \WP_Term(['term_id' => $id, 'taxonomy' => $taxonomy, 'slug' => $slug, 'name' => ucfirst($slug),
            'description' => '', 'parent' => $parent]);
    }

    /** @param list<array<string, mixed>> $posts */
    private function snapshot(array $posts, array $terms = [], string $schema = '1.5'): array
    {
        return ['meta' => ['schema' => $schema, 'source' => ['url' => 'http://site.local']], 'terms' => $terms, 'posts' => $posts];
    }

    /** A 1.5 page record. */
    private function rec(string $path, string $parent = ''): array
    {
        $slug = basename($path);

        return ['type' => 'page', 'slug' => $slug, 'path' => $path, 'title' => ucfirst($slug), 'parent' => $parent !== '' ? $parent : null];
    }

    private function find(string $path): ?\WP_Post
    {
        foreach ($this->posts as $post) {
            if ($this->uri($post) === $path) {
                return $post;
            }
        }
        return null;
    }

    /** @return array<string, array<string, mixed>> record key => changes, only records with changes */
    private function changes(array $input): array
    {
        $out = [];
        foreach ((new Importer())->plan($input)['records'] as $r) {
            if (!empty($r['changes'])) {
                $out[$r['kind'] . ':' . ($r['path'] ?? $r['slug'] ?? $r['key'] ?? '')] = $r['changes'];
            }
        }
        return $out;
    }

    public function test_two_pages_with_one_slug_stay_two_pages_whatever_the_order(): void
    {
        $input = $this->snapshot([
            $this->rec('about/team', 'about'),
            $this->rec('services/team', 'services'),
            $this->rec('about'),
            $this->rec('services'),
        ]);

        $report = (new Importer())->apply($input, ['rollback' => false]);

        $this->assertCount(4, $report['created']);
        $this->assertSame([], $report['warnings']);
        $this->assertNotNull($this->find('about/team'), 'children created after their parents, under them');
        $this->assertNotNull($this->find('services/team'));
        $this->assertSame([], $this->changes($input), 'a second import has nothing to do');
    }

    public function test_a_page_moved_to_the_top_level_loses_its_parent(): void
    {
        $this->page(1, 'about');
        $this->page(2, 'team', 1);
        $input = $this->snapshot([$this->rec('about'), $this->rec('team')]);

        $this->assertSame(['post:team' => ['parent' => ['status' => 'changed', 'old' => 'about', 'new' => '']]], $this->changes($input),
            'found by its slug (one page has it), and the parent shows as removed');

        (new Importer())->apply($input, ['rollback' => false]);

        $this->assertSame(0, $this->posts[2]->post_parent, 'the same page, now at the top level');
        $this->assertCount(2, $this->posts);
        $this->assertSame([], $this->changes($input));
    }

    public function test_a_page_moved_under_a_parent_is_matched_not_duplicated(): void
    {
        $this->page(1, 'about');
        $this->page(2, 'team');

        (new Importer())->apply($this->snapshot([$this->rec('about'), $this->rec('about/team', 'about')]), ['rollback' => false]);

        $this->assertSame(1, $this->posts[2]->post_parent);
        $this->assertCount(2, $this->posts);
    }

    public function test_the_slug_fallback_never_takes_another_records_page(): void
    {
        $this->page(1, 'about');
        $this->page(2, 'services');
        $this->page(3, 'team', 2);

        // services/team stays in the input: about/team is a new page.
        $report = (new Importer())->apply($this->snapshot([
            $this->rec('about'), $this->rec('services'), $this->rec('services/team', 'services'), $this->rec('about/team', 'about'),
        ]), ['rollback' => false]);

        $this->assertSame(['page:about/team'], $report['created']);
        $this->assertSame(2, $this->posts[3]->post_parent);
    }

    public function test_a_moved_page_is_never_claimed_by_its_namesake(): void
    {
        // Found on a real site: services/team moved to the top level here,
        // about/team deleted. Both input pages share the slug.
        $this->page(1, 'about');
        $this->page(2, 'services');
        $this->page(3, 'team');
        $this->posts[3]->post_title = 'Services team';
        $input = $this->snapshot([
            $this->rec('about'), $this->rec('services'),
            ['title' => 'About team'] + $this->rec('about/team', 'about'),
            ['title' => 'Services team'] + $this->rec('services/team', 'services'),
        ]);

        $changes = $this->changes($input);
        $this->assertSame('new', $changes['post:about/team']['title']['status'], 'about/team is created');
        $this->assertSame(['parent'], array_keys($changes['post:services/team']), 'the moved page, paired by its title');

        $report = (new Importer())->apply($input, ['rollback' => false]);

        $this->assertSame(['page:about/team'], $report['created']);
        $this->assertSame('services/team', $this->uri($this->posts[3]));
        $this->assertNotNull($this->find('about/team'));
        $this->assertSame([], $this->changes($input));
    }

    public function test_an_older_snapshot_names_parents_by_slug(): void
    {
        $this->page(1, 'company');
        $this->page(2, 'about', 1);
        $this->page(3, 'team', 2);

        $input = $this->snapshot([
            ['type' => 'page', 'slug' => 'about', 'title' => 'About', 'parent' => 'company'],
            ['type' => 'page', 'slug' => 'team', 'title' => 'Team', 'parent' => 'about'],
        ], [], '1.4');

        $this->assertSame([], $this->changes($input), "a nested parent's slug resolves to the same page");
    }

    public function test_a_parent_neither_here_nor_in_the_input_is_not_a_change_forever(): void
    {
        $this->page(2, 'team');
        $input = $this->snapshot([$this->rec('gone/team', 'gone')]);

        $this->assertSame([], $this->changes($input), "the preview doesn't promise what the import can't do");
        $this->assertSame(["page:gone/team: parent 'gone' isn't on this site or in the import — not set."], (new Importer())->plan($input)['warnings']);
    }

    public function test_term_parents_in_any_order_cleared_when_removed(): void
    {
        $this->terms[50] = $this->term(50, 'category', 'old-parent');
        $this->terms[51] = $this->term(51, 'category', 'orphan', 50);
        $input = $this->snapshot([], ['category' => [
            ['slug' => 'child', 'name' => 'Child', 'description' => '', 'parent' => 'parent', 'meta' => []],
            ['slug' => 'parent', 'name' => 'Parent', 'description' => '', 'parent' => null, 'meta' => []],
            ['slug' => 'orphan', 'name' => 'Orphan', 'description' => '', 'parent' => null, 'meta' => []],
            ['slug' => 'old-parent', 'name' => 'Old-parent', 'description' => '', 'parent' => null, 'meta' => []],
        ]]);

        $this->assertSame(['parent' => ['status' => 'changed', 'old' => 'old-parent', 'new' => '']], $this->changes($input)['term:orphan']);

        $report = (new Importer())->apply($input, ['rollback' => false]);

        $parent = array_values(array_filter($this->terms, static fn ($t) => $t->slug === 'parent'))[0];
        $child = array_values(array_filter($this->terms, static fn ($t) => $t->slug === 'child'))[0];
        $this->assertSame($parent->term_id, $child->parent, 'the parent was created first');
        $this->assertSame(0, $this->terms[51]->parent);
        $this->assertSame([], $report['warnings']);
        $this->assertSame([], $this->changes($input));
    }

    public function test_term_meta_is_written_unserialized_with_urls_mapped(): void
    {
        $this->terms[50] = $this->term(50, 'category', 'news');
        $input = [
            'meta'  => ['schema' => '1.5', 'source' => ['url' => 'https://prod.test']],
            'terms' => ['category' => [['slug' => 'news', 'name' => 'News', 'description' => '', 'parent' => null, 'meta' => [
                'color'  => '#c00',
                'links'  => ['https://prod.test/news/', 'x'],
                'legacy' => serialize(['a' => 1]),
                'object' => 'O:8:"stdClass":0:{}',
            ]]]],
            'posts' => [],
        ];

        $this->assertSame(['color', 'links', 'legacy', 'object'], array_map(
            static fn (string $k): string => substr($k, 5),
            array_keys($this->changes($input)['term:news'])
        ));

        (new Importer())->apply($input, ['rollback' => false]);

        $this->assertSame('#c00', $this->termMeta['50|color']);
        $this->assertSame(['http://site.local/news/', 'x'], $this->termMeta['50|links']);
        $this->assertSame(['a' => 1], $this->termMeta['50|legacy'], "an older snapshot's serialized string, stored once");
        $this->assertSame('O:8:"stdClass":0:{}', $this->termMeta['50|object'], 'a serialized object is never unserialized');
        $this->assertSame([], $this->changes($input));
    }

    public function test_a_term_field_can_point_at_a_page_the_same_import_creates(): void
    {
        Functions\when('absint')->alias(static fn ($v): int => abs((int) $v));
        Functions\when('sanitize_text_field')->returnArg(1);
        new Metabox(['id' => 'genre', 'title' => 'Genre', 'screens' => ['term:category'], 'fields' => [
            ['id' => 'landing', 'type' => 'post_select', 'post_type' => 'page'],
        ]]);
        $this->terms[50] = $this->term(50, 'category', 'news');
        $input = [
            'meta'  => ['schema' => '1.5', 'source' => ['url' => 'https://prod.test']],
            'refs'  => ['posts' => ['135' => ['type' => 'page', 'slug' => 'start', 'path' => 'start']]],
            'terms' => ['category' => [['slug' => 'news', 'name' => 'News', 'description' => '', 'parent' => null, 'fields' => ['landing' => 135]]]],
            'posts' => [$this->rec('start')],
        ];

        (new Importer())->apply($input, ['rollback' => false]);

        $start = $this->find('start');
        $this->assertNotNull($start);
        $this->assertSame((string) $start->ID, $this->termMeta['50|_taw_landing'], 'terms run before posts: written again once the page exists');
    }

    public function test_change_sets_key_pages_by_path(): void
    {
        $base = ['meta' => ['schema' => '1.5'], 'posts' => [['title' => 'A'] + $this->rec('about/team', 'about'), $this->rec('services/team', 'services')]];
        $target = ['meta' => ['schema' => '1.5'], 'posts' => [['title' => 'B'] + $this->rec('about/team', 'about'), $this->rec('services/team', 'services')]];

        $ops = ChangeSet::between($base, $target)['operations'];
        $this->assertCount(1, $ops);
        $this->assertSame('about/team', $ops[0]['target']['path']);

        $old = ['meta' => ['schema' => '1.4'], 'posts' => [['type' => 'page', 'slug' => 'team', 'title' => 'Team', 'parent' => null]]];
        $new = ['meta' => ['schema' => '1.5'], 'posts' => [$this->rec('team')]];
        $this->assertSame([], array_filter(ChangeSet::between($old, $new)['operations'], static fn ($op) => $op['op'] === 'delete'),
            'a 1.4 snapshot against a 1.5 one deletes nothing');
    }
}
