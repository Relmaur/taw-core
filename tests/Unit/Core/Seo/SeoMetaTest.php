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
}
