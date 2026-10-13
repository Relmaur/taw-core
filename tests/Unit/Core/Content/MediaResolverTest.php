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

    public function test_a_file_an_import_downloaded_matches_by_its_source_url_first(): void
    {
        // Downloaded earlier as 2026/10/photo-1.jpg (a name collision); 30 is an unrelated photo.jpg.
        \Brain\Monkey\Functions\when('get_posts')->alias(static function (array $q): array {
            $meta = $q['meta_query'][0] ?? [];
            if (($meta['key'] ?? '') === MediaResolver::SOURCE_META) {
                return ($meta['value'] ?? '') === 'https://src.test/wp-content/uploads/2026/10/photo.jpg' ? [31] : [];
            }
            return ($meta['compare'] ?? '=') === '=' ? [] : [30];
        });
        \Brain\Monkey\Functions\when('get_post_meta')->justReturn('2025/02/photo.jpg');
        \Brain\Monkey\Functions\when('wp_basename')->alias(static fn (string $p): string => basename($p));

        $resolver = new MediaResolver();
        $resolver->build([['id' => 50, 'filename' => 'photo.jpg', 'url' => 'https://src.test/wp-content/uploads/2026/10/photo.jpg']], false);

        $this->assertSame([50 => 31], $resolver->idMap(), 'the earlier download, not the other photo.jpg, and nothing downloaded again');
    }

    public function test_a_dropped_download_is_tried_again(): void
    {
        $calls = 0;
        \Brain\Monkey\Functions\when('download_url')->alias(static function () use (&$calls) {
            return ++$calls < 3 ? new \WP_Error('http_request_failed', 'cURL error 56: unexpected eof') : '/tmp/song.mp3';
        });
        \Brain\Monkey\Functions\when('is_wp_error')->alias(static fn ($v): bool => $v instanceof \WP_Error);

        $this->assertSame('/tmp/song.mp3', MediaResolver::download('https://src.test/song.mp3'));
        $this->assertSame(3, $calls);

        $calls = 0;
        $failed = MediaResolver::download('https://src.test/song.mp3', 2);
        $this->assertInstanceOf(\WP_Error::class, $failed);
        $this->assertSame('cURL error 56: unexpected eof', $failed->get_error_message(), 'the reason reaches the warning');
        $this->assertSame(2, $calls);
    }

    public function test_the_filename_fallback_never_takes_another_files_download_or_a_matched_one(): void
    {
        // 30: downloaded earlier from the other hero.jpg; 31: already matched in this run; 32: a plain upload.
        \Brain\Monkey\Functions\when('get_posts')->justReturn([30, 31, 32]);
        \Brain\Monkey\Functions\when('get_post_meta')->alias(static fn (int $id, string $key): string => match (true) {
            $key === MediaResolver::SOURCE_META => $id === 30 ? 'https://src.test/wp-content/uploads/2025/01/hero.jpg' : '',
            default => '2026/10/hero.jpg',
        });
        \Brain\Monkey\Functions\when('wp_basename')->alias(static fn (string $p): string => basename($p));

        $this->assertSame(32, MediaResolver::findByFilename('hero.jpg', [31], 'https://src.test/wp-content/uploads/2024/05/hero.jpg'));
        $this->assertSame(30, MediaResolver::findByFilename('hero.jpg'), 'without a URL or taken list: the first of that name, as before');
    }

    public function test_an_entry_that_says_its_bytes_matches_only_the_same_bytes(): void
    {
        $dir = sys_get_temp_dir() . '/taw-media-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/hero.jpg', 'production bytes');
        // One attachment at 2026/10/hero.jpg, holding "production bytes".
        \Brain\Monkey\Functions\when('get_posts')->alias(static fn (array $q): array => ($q['meta_query'][0]['value'] ?? '') === '2026/10/hero.jpg' ? [21] : []);
        \Brain\Monkey\Functions\when('get_post_meta')->justReturn('');
        \Brain\Monkey\Functions\when('wp_get_original_image_path')->justReturn($dir . '/hero.jpg');
        $entry = static fn (string $bytes): array => ['id' => 4, 'filename' => 'hero.jpg', 'url' => 'http://acme.local/wp-content/uploads/2026/10/hero.jpg',
            'file' => '2026/10/hero.jpg', 'sha256' => hash('sha256', $bytes)];

        $same = new MediaResolver();
        $same->build([$entry('production bytes')], false);
        $other = new MediaResolver();
        $other->build([$entry('Local bytes')], false);

        $this->assertSame('matched', $same->outcomes()[0]['status']);
        $this->assertSame([4 => 21], $same->idMap());
        $this->assertSame('would-sideload', $other->outcomes()[0]['status'], 'same name, other bytes: a new file');
        $this->assertSame([], $other->idMap());
        unlink($dir . '/hero.jpg');
        rmdir($dir);
    }

    public function test_with_staged_files_nothing_is_downloaded(): void
    {
        \Brain\Monkey\Functions\when('get_posts')->justReturn([]);
        \Brain\Monkey\Functions\expect('download_url')->never();
        \Brain\Monkey\Functions\when('wp_basename')->alias(static fn (string $p): string => basename($p));
        $resolver = (new MediaResolver())->withStaged(['2026/10/other.jpg' => '/tmp/x']);
        $resolver->build([['id' => 4, 'filename' => 'hero.jpg', 'url' => 'http://acme.local/wp-content/uploads/2026/10/hero.jpg',
            'file' => '2026/10/hero.jpg', 'sha256' => str_repeat('a', 64)]], true);

        $this->assertSame('missing', $resolver->outcomes()[0]['status'], "a file that wasn't uploaded isn't fetched from Local");
        $this->assertSame([4], $resolver->missingIds());
        $this->assertStringContainsString("wasn't uploaded", $resolver->warnings()[0]);
    }
}
