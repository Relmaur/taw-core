<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Seo;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Seo\SeoMeta;
use TAW\Tests\TestCase;

/**
 * The two SeoMeta extension points: `taw_seo_meta_screens` (post types the
 * "SEO & Social" box shows on; default page + post) and
 * `taw_seo_meta_context` (the resolved head values, re-checked after the
 * filter so a theme can't break the tags' shape).
 */
final class SeoMetaFiltersTest extends TestCase
{
    /** @var list<string> */
    private array $postTypes = ['page', 'post'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postTypes = ['page', 'post'];

        Functions\when('__')->returnArg(1);
        Functions\when('post_type_exists')->alias(fn (string $type) => in_array($type, $this->postTypes, true));
        Functions\when('get_option')->alias(fn (string $key, mixed $default = false) => $default);
        Functions\when('get_post_meta')->justReturn('');
    }

    protected function tearDown(): void
    {
        Metabox::resetRegistryForTests();
        Metabox::forgetInstances();
        parent::tearDown();
    }

    /* ── taw_seo_meta_screens ─────────────────────────────────────────── */

    public function test_the_default_screens_stay_page_and_post(): void
    {
        $this->assertSame(['page', 'post'], SeoMeta::screens());
    }

    public function test_the_filter_adds_registered_post_types_and_drops_the_rest(): void
    {
        $this->postTypes[] = 'guide';
        Filters\expectApplied('taw_seo_meta_screens')
            ->once()
            ->with(['page', 'post'])
            ->andReturn(['page', 'post', 'guide', 'guide', '', 42, null, 'not_registered']);

        $this->assertSame(['page', 'post', 'guide'], SeoMeta::screens());
    }

    public function test_a_non_array_answer_keeps_the_default(): void
    {
        Filters\expectApplied('taw_seo_meta_screens')->andReturn('guide');

        $this->assertSame(['page', 'post'], SeoMeta::screens());
    }

    public function test_the_box_registers_on_the_filtered_post_types(): void
    {
        $this->postTypes[] = 'guide';
        Filters\expectApplied('taw_seo_meta_screens')->andReturn(['guide']);

        $seo = $this->makeSeoMeta();
        $seo->registerMetabox();

        $this->assertSame(['guide'], $this->seoBoxScreens());
    }

    public function test_an_empty_answer_registers_no_box(): void
    {
        Filters\expectApplied('taw_seo_meta_screens')->andReturn([]);

        $this->makeSeoMeta()->registerMetabox();

        $this->assertNull($this->seoBoxScreens());
    }

    /**
     * A theme's post type often registers on init at the default priority
     * after SeoMeta hooked registerMetabox(): the box waits until init:19
     * (before FieldMetaRegistrar at 20) once, then keeps what's registered.
     */
    public function test_a_post_type_registered_later_on_init_is_waited_for_once(): void
    {
        Filters\expectApplied('taw_seo_meta_screens')->andReturn(['page', 'guide']);
        Functions\when('doing_action')->alias(fn (string $hook) => $hook === 'init');
        $seo = $this->makeSeoMeta();
        Actions\expectAdded('init')->once()->with([$seo, 'registerMetabox'], 19);

        $seo->registerMetabox();
        $this->assertNull($this->seoBoxScreens(), 'nothing registers before the post type exists');

        $this->postTypes[] = 'guide';
        $seo->registerMetabox();
        $this->assertSame(['page', 'guide'], $this->seoBoxScreens());
    }

    public function test_the_default_never_waits(): void
    {
        Functions\when('doing_action')->alias(fn (string $hook) => $hook === 'init');
        $seo = $this->makeSeoMeta();
        Actions\expectAdded('init')->never()->with([$seo, 'registerMetabox'], 19);

        $seo->registerMetabox();

        $this->assertSame(['page', 'post'], $this->seoBoxScreens());
    }

    /* ── taw_seo_meta_context ─────────────────────────────────────────── */

    public function test_the_context_filter_changes_the_head_values(): void
    {
        $this->stubFrontPage();
        Filters\expectApplied('taw_seo_meta_context')
            ->once()
            ->with(\Mockery::type('array'), 0)
            ->andReturnUsing(fn (array $context) => ['description' => 'A site-wide fallback.', 'title' => 'Social title'] + $context);

        $html = $this->renderHead();

        $this->assertStringContainsString('<meta name="description" content="A site-wide fallback.">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="A site-wide fallback.">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Social title">', $html);
        $this->assertStringContainsString('<meta name="twitter:title" content="Social title">', $html);
    }

    public function test_singular_views_pass_the_post_id(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_the_ID')->justReturn(42);
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('get_post_type')->justReturn('guide');
        Functions\when('get_the_title')->justReturn('A guide');
        Functions\when('wp_get_canonical_url')->justReturn('https://example.com/guide/');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        $this->stubHeadOutput();
        $seen = null;
        Filters\expectApplied('taw_seo_meta_context')->once()->andReturnUsing(function (array $context, int $postId) use (&$seen) {
            $seen = $postId;
            return ['description' => 'From the summary field.'] + $context;
        });

        $html = $this->renderHead();

        $this->assertSame(42, $seen);
        $this->assertStringContainsString('<meta name="description" content="From the summary field.">', $html);
    }

    public function test_the_filtered_context_keeps_its_shape(): void
    {
        $this->stubFrontPage();
        $resolved = $this->callMethod($this->makeSeoMeta(), 'resolveContext');
        Filters\expectApplied('taw_seo_meta_context')->andReturn([
            'title'       => ['not', 'a', 'string'],
            'description' => 155,
            'url'         => null,
            'image_id'    => -4,
            'og_type'     => 'article',
            'unknown'     => 'dropped',
        ]);

        $context = $this->callMethod($this->makeSeoMeta(), 'filterContext', $resolved, 0);

        $this->assertSame(array_keys($resolved), array_keys($context), 'unknown keys dropped, none lost');
        $this->assertSame($resolved['title'], $context['title'], 'a non-string keeps the resolved value');
        $this->assertSame('155', $context['description'], 'a number is cast to a string');
        $this->assertSame($resolved['url'], $context['url']);
        $this->assertSame(0, $context['image_id'], 'a negative image id keeps the resolved one');
        $this->assertSame('article', $context['og_type']);
    }

    public function test_an_image_id_must_be_a_non_negative_int(): void
    {
        $resolved = ['title' => 'T', 'description' => '', 'url' => '', 'og_type' => 'website', 'image_id' => 7, 'published' => '', 'modified' => ''];
        $seo = $this->makeSeoMeta();

        Filters\expectApplied('taw_seo_meta_context')->andReturn(['image_id' => '12'] + $resolved);
        $this->assertSame(12, $this->callMethod($seo, 'filterContext', $resolved, 0)['image_id']);
    }

    public function test_an_image_id_that_is_not_a_number_keeps_the_resolved_one(): void
    {
        $resolved = ['title' => 'T', 'description' => '', 'url' => '', 'og_type' => 'website', 'image_id' => 7, 'published' => '', 'modified' => ''];
        Filters\expectApplied('taw_seo_meta_context')->andReturn(['image_id' => 'hero.jpg'] + $resolved);

        $this->assertSame(7, $this->callMethod($this->makeSeoMeta(), 'filterContext', $resolved, 0)['image_id']);
    }

    public function test_a_non_array_answer_keeps_the_unfiltered_context(): void
    {
        $this->stubFrontPage();
        Filters\expectApplied('taw_seo_meta_context')->andReturn(false);

        $html = $this->renderHead();

        $this->assertStringContainsString('<meta name="description" content="My site tagline">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="My Site">', $html);
    }

    public function test_without_a_filter_the_head_is_unchanged(): void
    {
        $this->stubFrontPage();

        $this->assertSame(
            '<link rel="canonical" href="https://example.com/">' . "\n"
            . '<meta name="description" content="My site tagline">' . "\n"
            . '<meta property="og:site_name" content="My Site">' . "\n"
            . '<meta property="og:locale" content="en_US">' . "\n"
            . '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:title" content="My Site">' . "\n"
            . '<meta property="og:description" content="My site tagline">' . "\n"
            . '<meta property="og:url" content="https://example.com/">' . "\n"
            . '<meta name="twitter:card" content="summary">' . "\n"
            . '<meta name="twitter:title" content="My Site">' . "\n"
            . '<meta name="twitter:description" content="My site tagline">' . "\n",
            $this->renderHead(),
        );
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    private function makeSeoMeta(): SeoMeta
    {
        // add_action/add_filter/remove_action stay Brain Monkey's own, so
        // Actions\expectAdded() sees them.
        return new SeoMeta();
    }

    private function renderHead(): string
    {
        $seo = $this->makeSeoMeta();
        ob_start();
        try {
            $seo->renderHeadTags();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function stubFrontPage(): void
    {
        Functions\when('is_singular')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        Functions\when('is_home')->justReturn(false);
        $this->stubHeadOutput();
    }

    private function stubHeadOutput(): void
    {
        Functions\when('get_bloginfo')->alias(
            fn (string $key) => ['name' => 'My Site', 'description' => 'My site tagline'][$key] ?? ''
        );
        Functions\when('home_url')->alias(fn (string $path = '') => 'https://example.com' . $path);
        Functions\when('get_locale')->justReturn('en_US');
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
    }

    /** @return list<string>|null the "SEO & Social" box's screens, or null when it isn't registered */
    private function seoBoxScreens(): ?array
    {
        foreach (Metabox::instances() as $box) {
            $config = (new \ReflectionProperty($box, 'id'))->getValue($box);
            if ($config === 'taw_seo_meta') {
                return array_values((array) (new \ReflectionProperty($box, 'screens'))->getValue($box));
            }
        }

        return null;
    }
}
