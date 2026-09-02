<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Metabox\MetaboxOrder;
use TAW\Tests\TestCase;

/**
 * Covers the WordPress template-hierarchy resolution shared by
 * Metabox::templateCandidatesForPost() and MetaboxOrder::orderForPost().
 *
 * The bug this guards against: pages on the `page-{slug}.php` (and `home.php`)
 * filename conventions write no `_wp_page_template` meta, so MetaboxOrder used
 * to leave their metaboxes in raw registration order while `front-page.php`
 * and explicitly-selected templates ordered correctly.
 *
 * No real WordPress install — `locate_template` is faked against the fixture
 * templates in ./fixtures, and the field registry is seeded directly.
 */
final class MetaboxOrderResolutionTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var string _wp_page_template meta for the post under test */
    private string $assignedTemplate = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            'show_on_front'  => 'posts',
            'page_on_front'  => 0,
            'page_for_posts' => 0,
        ];
        $this->assignedTemplate = '';

        Functions\when('get_option')->alias(
            fn(string $name, mixed $default = false): mixed => $this->options[$name] ?? $default
        );

        Functions\when('get_post_meta')->alias(
            fn(int $postId, string $key, bool $single = false): mixed =>
                $key === '_wp_page_template' ? $this->assignedTemplate : ''
        );

        // Real locate_template() returns the first existing file's full path,
        // or '' when none of the names resolve.
        Functions\when('locate_template')->alias(function (array $names): string {
            foreach ($names as $name) {
                $path = self::FIXTURES . '/' . $name;
                if (is_file($path)) {
                    return $path;
                }
            }
            return '';
        });

        $this->seedFieldRegistry([
            'hero_title'      => ['metabox_id' => 'statement_hero',  'block_id' => 'statement_hero'],
            'branches_json'   => ['metabox_id' => 'mission_branches', 'block_id' => 'mission_branches'],
            'florilegium_ref' => ['metabox_id' => 'florilegium',      'block_id' => 'florilegium'],
            'feed_count'      => ['metabox_id' => 'posts_feed',       'block_id' => 'posts_feed'],
            'newsletter_id'   => ['metabox_id' => 'newsletter_cta',   'block_id' => 'newsletter_cta'],
            'promo_heading'   => ['metabox_id' => 'landing_promo',    'block_id' => 'landing_promo'],
            'seo_title'       => ['metabox_id' => 'seo_meta',         'block_id' => null],
        ]);
    }

    protected function tearDown(): void
    {
        $this->seedFieldRegistry([]);
        parent::tearDown();
    }

    public function test_page_slug_template_resolves_by_slug_without_any_meta(): void
    {
        $post = new \WP_Post(['ID' => 42, 'post_name' => 'nuestra-mision']);

        $this->assertSame(
            ['statement_hero', 'mission_branches', 'florilegium'],
            $this->orderForPost($post)
        );
    }

    public function test_posts_page_resolves_to_home_php(): void
    {
        $this->options['show_on_front']  = 'page';
        $this->options['page_for_posts'] = 7;

        $post = new \WP_Post(['ID' => 7, 'post_name' => 'blog']);

        $this->assertSame(['posts_feed', 'newsletter_cta'], $this->orderForPost($post));
    }

    public function test_explicit_template_wins_over_a_matching_page_slug_file(): void
    {
        // Slug would otherwise resolve page-nuestra-mision.php.
        $this->assignedTemplate = 'my-template.php';

        $post = new \WP_Post(['ID' => 42, 'post_name' => 'nuestra-mision']);

        $this->assertSame(['landing_promo'], $this->orderForPost($post));
    }

    public function test_static_front_page_still_resolves_to_front_page_php(): void
    {
        $this->options['show_on_front'] = 'page';
        $this->options['page_on_front'] = 3;

        $post = new \WP_Post(['ID' => 3, 'post_name' => 'inicio']);

        $this->assertSame(['statement_hero', 'posts_feed'], $this->orderForPost($post));
    }

    public function test_post_matching_no_template_convention_is_left_unordered(): void
    {
        $post = new \WP_Post(['ID' => 99, 'post_name' => 'contacto']);

        $this->assertSame([], $this->orderForPost($post));
    }

    public function test_template_candidates_are_ordered_highest_priority_first(): void
    {
        $this->options['show_on_front'] = 'page';
        $this->options['page_on_front'] = 5;
        $this->assignedTemplate        = 'custom.php';

        $post = new \WP_Post(['ID' => 5, 'post_name' => 'home']);

        $this->assertSame(
            ['custom.php', 'front-page.php', 'page-home.php'],
            Metabox::templateCandidatesForPost($post)
        );
    }

    /** @param array<string, array<string, mixed>> $registry */
    private function seedFieldRegistry(array $registry): void
    {
        $ref = new \ReflectionProperty(Metabox::class, 'fieldRegistry');
        $ref->setAccessible(true);
        $ref->setValue(null, $registry);
    }

    /** @return string[] */
    private function orderForPost(\WP_Post $post): array
    {
        $ref = new \ReflectionMethod(MetaboxOrder::class, 'orderForPost');
        $ref->setAccessible(true);

        return $ref->invoke(null, $post);
    }
}
