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

    public function test_media_matches_its_uploads_path_before_its_filename(): void
    {
        // Two attachments named hero.jpg: 15 in 2024/05, 16 in 2025/01.
        \Brain\Monkey\Functions\when('get_posts')->alias(static function (array $q): array {
            $meta = $q['meta_query'][0] ?? [];
            if (($meta['compare'] ?? '=') === '=') {
                return ($meta['value'] ?? '') === '2025/01/hero.jpg' ? [16] : [];
            }
            return [15, 16];
        });
        \Brain\Monkey\Functions\when('get_post_meta')->alias(static fn (int $id): string => $id === 15 ? '2024/05/hero.jpg' : '2025/01/hero.jpg');
        \Brain\Monkey\Functions\when('wp_basename')->alias(static fn (string $p): string => basename($p));

        $resolver = new MediaResolver();
        $resolver->build([
            ['id' => 5615, 'filename' => 'hero.jpg', 'url' => 'https://prod.test/wp-content/uploads/2025/01/hero.jpg'],
            ['id' => 99, 'filename' => 'hero.jpg'],
        ], false);

        $this->assertSame([5615 => 16, 99 => 15], $resolver->idMap(), 'by path when the URL gives one, else the first file of that name');
        $this->assertSame('2025/01/hero.jpg', MediaResolver::uploadsPath('https://x.test/wp-content/uploads/2025/01/hero.jpg?v=2'));
    }
}
