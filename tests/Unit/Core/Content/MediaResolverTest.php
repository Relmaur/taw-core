<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use TAW\Core\Content\MediaResolver;
use TAW\Tests\TestCase;

/**
 * The pure half of MediaResolver — rewriting attachment IDs inside
 * post_content. DB matching and sideloading are exercised by the live
 * end-to-end path, not here.
 */
final class MediaResolverTest extends TestCase
{
    public function test_rewrites_wp_image_class_and_block_id_attr(): void
    {
        $content = '<!-- wp:image {"id":42,"sizeSlug":"large"} -->'
            . '<figure class="wp-block-image size-large"><img src="x.jpg" class="wp-image-42"/></figure>'
            . '<!-- /wp:image -->';

        $out = MediaResolver::rewriteContent($content, [42 => 108]);

        $this->assertStringContainsString('"id":108', $out);
        $this->assertStringContainsString('wp-image-108', $out);
        $this->assertStringNotContainsString('wp-image-42', $out);
        $this->assertStringNotContainsString('"id":42', $out);
    }

    public function test_rewrites_gallery_ids_array(): void
    {
        $out = MediaResolver::rewriteContent('<!-- wp:gallery {"ids":[5,6,7]} -->', [5 => 50, 7 => 70]);

        $this->assertStringContainsString('"ids":[50,6,70]', $out);
    }

    public function test_ids_absent_from_the_map_are_left_untouched(): void
    {
        $content = '<img class="wp-image-9"/> "id":9';
        $this->assertSame($content, MediaResolver::rewriteContent($content, [1 => 2]));
    }

    public function test_empty_map_is_a_noop(): void
    {
        $content = '<img class="wp-image-9"/>';
        $this->assertSame($content, MediaResolver::rewriteContent($content, []));
    }
}
