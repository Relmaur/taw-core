<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Seo;

use Brain\Monkey\Functions;
use TAW\Core\Seo\SeoMeta;
use TAW\Tests\TestCase;

/**
 * filterDocumentTitle() (the real <title> tag) and filterRobots() (per-post
 * noindex) are the two WP-hook integration points added alongside
 * renderHeadTags()'s own <head> output — before this, the stored meta
 * title only ever reached og:title/twitter:title, and there was no way to
 * keep a page out of search results at all. Both are cheap to verify
 * without a real WordPress install: stub is_singular()/get_the_ID()/
 * get_post_meta() and assert the filtered value.
 *
 * Also covers the constructor's rel_canonical() dedup and singularContext()'s
 * front-page fallback (via the TestCase::callMethod() reflection helper,
 * since it's private) — a static front page is still is_singular(), so its
 * og:title/description need the same site-identity fallback the dedicated
 * is_front_page()/is_home() branch elsewhere in this class already has.
 */
final class SeoMetaTest extends TestCase
{
    /** @var array<string, string> */
    private array $postMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postMeta = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('get_post_meta')->alias(
            fn (int $postId, string $key, bool $single = false) => $this->postMeta[$key] ?? ''
        );
    }

    private function makeSeoMeta(): SeoMeta
    {
        return new SeoMeta();
    }

    public function test_document_title_untouched_when_not_singular(): void
    {
        Functions\when('is_singular')->justReturn(false);

        $parts = $this->makeSeoMeta()->filterDocumentTitle(['title' => 'Original', 'site' => 'My Site']);

        $this->assertSame(['title' => 'Original', 'site' => 'My Site'], $parts);
    }

    public function test_document_title_untouched_when_no_stored_meta_title(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_the_ID')->justReturn(42);
        // No meta stored — postMeta stays empty, get_post_meta() returns ''.

        $parts = $this->makeSeoMeta()->filterDocumentTitle(['title' => 'Original', 'site' => 'My Site']);

        $this->assertSame(['title' => 'Original', 'site' => 'My Site'], $parts);
    }

    public function test_document_title_overridden_by_stored_meta_title(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_the_ID')->justReturn(42);
        $this->postMeta['_taw_seo_meta_title'] = 'Custom SEO Title';

        $parts = $this->makeSeoMeta()->filterDocumentTitle(['title' => 'Original', 'site' => 'My Site']);

        // Only the page-specific half is replaced — 'site' (and any tagline)
        // is left for WordPress's default assembly to append.
        $this->assertSame(['title' => 'Custom SEO Title', 'site' => 'My Site'], $parts);
    }

    public function test_robots_untouched_when_not_singular(): void
    {
        Functions\when('is_singular')->justReturn(false);

        $robots = $this->makeSeoMeta()->filterRobots(['max-image-preview' => 'large']);

        $this->assertSame(['max-image-preview' => 'large'], $robots);
    }

    public function test_robots_untouched_when_noindex_not_checked(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_the_ID')->justReturn(42);
        // No noindex flag stored — Metabox::get_bool() reads '' !== '1'.

        $robots = $this->makeSeoMeta()->filterRobots(['max-image-preview' => 'large']);

        $this->assertSame(['max-image-preview' => 'large'], $robots);
    }

    public function test_robots_gains_noindex_when_flag_checked(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_the_ID')->justReturn(42);
        $this->postMeta['_taw_seo_noindex'] = '1';

        $robots = $this->makeSeoMeta()->filterRobots(['max-image-preview' => 'large']);

        $this->assertSame(['max-image-preview' => 'large', 'noindex' => true], $robots);
    }

    public function test_constructor_removes_cores_default_canonical_when_no_seo_plugin_active(): void
    {
        Functions\expect('remove_action')
            ->once()
            ->with('wp_head', 'rel_canonical');

        $this->assertInstanceOf(SeoMeta::class, $this->makeSeoMeta());
    }

    /**
     * A static front page is still is_singular() (it's a Page), so it never
     * reaches renderHeadTags()'s dedicated is_front_page()/is_home() branch —
     * without its own fallback here, a placeholder post_title never meant
     * to be public-facing would leak into og:title/twitter:title.
     */
    public function test_singular_context_front_page_falls_back_to_site_identity_when_no_meta_set(): void
    {
        $this->stubSingularContextCollaborators(postType: 'page', isFrontPage: true);

        $context = $this->callMethod($this->makeSeoMeta(), 'singularContext');

        $this->assertSame('My Site', $context['title']);
        $this->assertSame('My site tagline', $context['description']);
    }

    public function test_singular_context_non_front_page_has_no_description_fallback_when_no_meta_set(): void
    {
        $this->stubSingularContextCollaborators(postType: 'page', isFrontPage: false);
        Functions\when('get_the_title')->justReturn('Raw Post Title');

        $context = $this->callMethod($this->makeSeoMeta(), 'singularContext');

        $this->assertSame('Raw Post Title', $context['title']);
        $this->assertSame('', $context['description']);
    }

    private function stubSingularContextCollaborators(string $postType, bool $isFrontPage): void
    {
        Functions\when('get_the_ID')->justReturn(10);
        Functions\when('is_front_page')->justReturn($isFrontPage);
        Functions\when('get_post_type')->justReturn($postType);
        Functions\when('get_bloginfo')->alias(
            fn (string $key) => ['name' => 'My Site', 'description' => 'My site tagline'][$key] ?? ''
        );
        Functions\when('wp_get_canonical_url')->justReturn('https://example.com/');
        Functions\when('get_permalink')->justReturn('https://example.com/');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
    }
}
