<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Seo;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TAW\Core\Seo\SocialImage;
use TAW\Tests\TestCase;

/**
 * Issue #196: with modern_image_formats on, an uploaded JPEG is served as
 * AVIF, and link-preview crawlers don't read AVIF. Social tags get the
 * original upload instead.
 */
final class SocialImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-social-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/share.jpg', str_repeat('x', 1024));

        Functions\when('get_attached_file')->justReturn($this->dir . '/share.avif');
        Functions\when('wp_check_filetype')->alias(static fn (string $f): array => [
            'type' => ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg'][pathinfo($f, PATHINFO_EXTENSION)] ?? false,
        ]);
        Functions\when('wp_get_original_image_path')->justReturn($this->dir . '/share.jpg');
        Functions\when('wp_get_original_image_url')->justReturn('https://example.test/uploads/share.jpg');
        Functions\when('wp_getimagesize')->justReturn([1200, 630]);
        Functions\when('wp_get_attachment_image_src')->justReturn(['https://example.test/uploads/share.avif', 1200, 630, false]);
        Functions\when('attachment_url_to_postid')->justReturn(42);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_an_image_served_as_avif_shares_its_original_upload(): void
    {
        $this->assertSame('https://example.test/uploads/share.jpg', SocialImage::url(42));
    }

    public function test_a_jpeg_served_as_jpeg_is_left_alone(): void
    {
        Functions\when('get_attached_file')->justReturn($this->dir . '/share.jpg');

        $this->assertSame('https://example.test/uploads/share.avif', SocialImage::url(42), 'the served URL, whatever it is');
    }

    public function test_no_usable_original_keeps_the_served_file(): void
    {
        Functions\when('wp_get_original_image_path')->justReturn(false);
        $this->assertSame('https://example.test/uploads/share.avif', SocialImage::url(42), 'no original kept');

        file_put_contents($this->dir . '/share.jpg', str_repeat('x', 5 * 1024 * 1024 + 1));
        Functions\when('wp_get_original_image_path')->justReturn($this->dir . '/share.jpg');
        $this->assertSame('https://example.test/uploads/share.avif', SocialImage::url(42), 'original over 5 MB (X rejects it)');
    }

    public function test_the_filter_turns_it_off(): void
    {
        Filters\expectApplied('taw_social_image_original')->andReturn(false);

        $this->assertSame('https://example.test/uploads/share.avif', SocialImage::url(42));
    }

    public function test_seo_plugin_urls_are_swapped_and_yoast_gets_the_matching_type(): void
    {
        $this->assertSame('https://example.test/uploads/share.jpg', SocialImage::filterUrl('https://example.test/uploads/share.avif'));
        $this->assertSame('image/jpeg', SocialImage::filterYoastType('image/avif'));
        $this->assertSame('image/avif', SocialImage::filterYoastType('image/avif'), 'only the image just swapped');

        $this->assertSame('https://example.test/uploads/share.png', SocialImage::filterUrl('https://example.test/uploads/share.png'), 'not AVIF/WebP: untouched');
        $this->assertSame(['not', 'a', 'url'], SocialImage::filterUrl(['not', 'a', 'url']));
    }

    public function test_smartcrawl_images_are_rekeyed_with_the_originals_size(): void
    {
        Functions\when('wp_getimagesize')->justReturn([2400, 1260]);
        $in = [
            'https://example.test/uploads/share.avif' => ['https://example.test/uploads/share.avif', 1200, 630, false],
            'https://example.test/uploads/logo.png' => ['https://example.test/uploads/logo.png', 512, 512, false],
        ];

        $this->assertSame([
            'https://example.test/uploads/share.jpg' => ['https://example.test/uploads/share.jpg', 2400, 1260, false],
            'https://example.test/uploads/logo.png' => ['https://example.test/uploads/logo.png', 512, 512, false],
        ], SocialImage::filterSmartCrawl($in));
    }
}
