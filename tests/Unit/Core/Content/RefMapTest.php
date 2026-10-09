<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use TAW\Core\Content\BlockRefs;
use TAW\Core\Content\FieldCodec;
use TAW\Core\Content\RefMap;
use TAW\Tests\TestCase;

/**
 * Content Interchange fidelity, phases 2+3: references travel by natural
 * key, and URLs are rewritten.
 */
final class RefMapTest extends TestCase
{
    /** @var array<string, int> "type:slug" => local id, the posts this "site" has */
    private array $sitePosts = ['post:studio-pipeline' => 78, 'page:about' => 12];

    private function map(array $extra = []): RefMap
    {
        return new RefMap(
            $extra['attachments'] ?? [501 => 9001, 502 => 9002],
            ['135' => ['type' => 'post', 'slug' => 'studio-pipeline'], 140 => ['type' => 'page', 'slug' => 'gone'], 150 => ['type' => 'post', 'slug' => 'later']],
            [33 => ['taxonomy' => 'category', 'slug' => 'news']],
            [7 => ['login' => 'editor', 'email' => 'e@x.test']],
            fn (string $type, string $slug): int => $this->sitePosts["{$type}:{$slug}"] ?? 0,
            static fn (string $taxonomy, string $slug): int => $taxonomy === 'category' && $slug === 'news' ? 4 : 0,
            static fn (array $ref): int => ($ref['login'] ?? '') === 'editor' ? 2 : 0,
            $extra['media'] ?? [
                ['from' => 'https://prod.test/wp-content/uploads/2024/05/hero-scaled.jpg', 'to' => 'http://site.local/wp-content/uploads/2026/10/hero-scaled.jpg'],
                ['from' => 'https://prod.test/wp-content/uploads/2024/09/guide.pdf', 'to' => 'http://site.local/wp-content/uploads/2026/10/guide.pdf'],
            ],
            'https://prod.test',
            'http://site.local',
        );
    }

    public function test_references_resolve_by_natural_key(): void
    {
        $map = $this->map();

        $this->assertSame(78, $map->post(135), 'prod 135 is this site\'s 78 (mlizardo\'s homepage)');
        $this->assertFalse($map->post(140), 'named but missing here: dropped');
        $this->assertNull($map->post(999), 'not in refs (an older snapshot): left alone');
        $this->assertSame(4, $map->term(33));
        $this->assertSame(2, $map->user(7));
        $this->assertSame(9001, $map->attachment(501));
        $this->assertSame([78, 999], RefMap::mapIds([135, 140, 999], $map->post(...)));
        $this->assertSame(['page:gone'], $map->takeUnresolved());
        $this->assertSame([], $map->takeUnresolved(), 'taken once');
    }

    public function test_a_post_created_later_resolves_on_the_next_lookup(): void
    {
        $map = $this->map();
        $this->assertFalse($map->post(150));
        $this->sitePosts['post:later'] = 91;

        $this->assertSame(91, $map->post(150), 'misses are not cached');
    }

    public function test_media_urls_map_with_their_sizes_then_the_origin(): void
    {
        $map = $this->map();
        $html = '<img src="https://prod.test/wp-content/uploads/2024/05/hero-1024x683.jpg" srcset="https://prod.test/wp-content/uploads/2024/05/hero.jpg 2048w">'
            . '<a href="https://prod.test/wp-content/uploads/2024/09/guide.pdf">PDF</a> <a href="http://prod.test/contacto/">Contact</a>'
            . ' https://prod.test.evil.example/ https://prod.testing.example/'
            . ' <img src="https://prod.test/wp-content/uploads/2026/09/logo.webp">';

        $this->assertSame(
            '<img src="http://site.local/wp-content/uploads/2026/10/hero-1024x683.jpg" srcset="http://site.local/wp-content/uploads/2026/10/hero.jpg 2048w">'
            . '<a href="http://site.local/wp-content/uploads/2026/10/guide.pdf">PDF</a> <a href="http://site.local/contacto/">Contact</a>'
            . ' https://prod.test.evil.example/ https://prod.testing.example/'
            . ' <img src="https://prod.test/wp-content/uploads/2026/09/logo.webp">',
            $map->urls($html),
            'size variants and the original of a -scaled upload land in the new folder; another host is left alone;'
            . ' an uploaded file this site lacks keeps loading from the source'
        );
        $this->assertSame(
            '{"url":"http:\/\/site.local\/wp-content\/uploads\/2026\/10\/guide.pdf","home":"http:\/\/site.local\/"}',
            $map->urls('{"url":"https:\/\/prod.test\/wp-content\/uploads\/2024\/09\/guide.pdf","home":"https:\/\/prod.test\/"}'),
            'JSON-escaped slashes too'
        );
    }

    public function test_the_same_site_rewrites_nothing(): void
    {
        $map = new RefMap([], [], [], [], null, null, null, [], 'https://site.test', 'https://site.test');

        $this->assertFalse($map->rewritesUrls());
        $this->assertSame('https://site.test/a', $map->urls('https://site.test/a'));
    }

    public function test_block_attributes_that_hold_ids_and_no_others(): void
    {
        $blocks = [
            ['blockName' => 'core/image', 'attrs' => ['id' => 501, 'sizeSlug' => 'large'], 'innerBlocks' => []],
            ['blockName' => 'core/gallery', 'attrs' => ['ids' => [501, 502, 503]], 'innerBlocks' => [
                ['blockName' => 'core/image', 'attrs' => ['id' => 502], 'innerBlocks' => []],
            ]],
            ['blockName' => 'core/media-text', 'attrs' => ['mediaId' => 502], 'innerBlocks' => []],
            ['blockName' => 'core/navigation-link', 'attrs' => ['id' => 135, 'kind' => 'post-type'], 'innerBlocks' => []],
            ['blockName' => 'core/navigation-link', 'attrs' => ['id' => 33, 'kind' => 'taxonomy'], 'innerBlocks' => []],
            ['blockName' => 'core/query', 'attrs' => ['query' => ['include' => [135, 140], 'exclude' => [], 'author' => '7', 'taxQuery' => ['category' => [33]]]], 'innerBlocks' => []],
            ['blockName' => 'taw/loop', 'attrs' => ['loopId' => 3, 'source' => ['type' => 'query', 'include' => [135], 'terms' => ['category' => [33], 'genre' => 'current'], 'author' => 7]], 'innerBlocks' => []],
            ['blockName' => 'taw/form', 'attrs' => ['id' => 501], 'innerBlocks' => []],
        ];

        $refs = BlockRefs::collectBlocks($blocks);
        $this->assertSame([501, 502, 503], $refs['attachments']);
        $this->assertSame([135, 140], $refs['posts']);
        $this->assertSame([33], $refs['terms']);
        $this->assertSame([7], $refs['users']);

        $changed = false;
        $out = BlockRefs::rewriteBlocks($blocks, $this->map(), $changed);
        $this->assertTrue($changed);
        $this->assertSame(9001, $out[0]['attrs']['id']);
        $this->assertSame([9001, 9002, 503], $out[1]['attrs']['ids'], 'an unknown attachment stays (phase 4 clears it)');
        $this->assertSame(9002, $out[1]['innerBlocks'][0]['attrs']['id']);
        $this->assertSame(9002, $out[2]['attrs']['mediaId']);
        $this->assertSame(78, $out[3]['attrs']['id']);
        $this->assertSame(4, $out[4]['attrs']['id']);
        $this->assertSame(['include' => [78], 'exclude' => [], 'author' => '2', 'taxQuery' => ['category' => [4]]], $out[5]['attrs']['query']);
        $this->assertSame(['type' => 'query', 'include' => [78], 'terms' => ['category' => [4], 'genre' => 'current'], 'author' => 2], $out[6]['attrs']['source']);
        $this->assertSame(3, $out[6]['attrs']['loopId']);
        $this->assertSame(501, $out[7]['attrs']['id'], "another block's id is not an attachment");
    }

    public function test_nothing_to_map_leaves_blocks_unchanged(): void
    {
        $changed = false;
        BlockRefs::rewriteBlocks([['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []]], $this->map(), $changed);

        $this->assertFalse($changed, 'so the content string is kept byte for byte');
    }

    public function test_classic_html_ids_and_urls(): void
    {
        $html = '<img class="alignnone wp-image-501" src="https://prod.test/wp-content/uploads/2024/05/hero-300x200.jpg" data-id="502">';

        $this->assertSame(
            '<img class="alignnone wp-image-9001" src="http://site.local/wp-content/uploads/2026/10/hero-300x200.jpg" data-id="9002">',
            BlockRefs::rewrite($html, $this->map())
        );
    }

    public function test_field_values_map_by_type(): void
    {
        $map = $this->map();

        $this->assertSame(78, FieldCodec::rewriteRefs(['type' => 'post_select'], 135, $map));
        $this->assertNull(FieldCodec::rewriteRefs(['type' => 'post_select'], 140, $map), 'a post this site lacks is cleared, not left pointing at another');
        $this->assertSame([78], FieldCodec::rewriteRefs(['type' => 'post_select', 'multiple' => true], [135, 140], $map));
        $this->assertSame(9001, FieldCodec::rewriteRefs(['type' => 'image'], 501, $map));
        $this->assertSame([9001, 9002], FieldCodec::rewriteRefs(['type' => 'files'], [501, 502], $map));
        $this->assertSame('http://site.local/contacto/', FieldCodec::rewriteRefs(['type' => 'url'], 'https://prod.test/contacto/', $map));
        $this->assertSame(
            ['url' => 'http://site.local/wp-content/uploads/2026/10/guide.pdf', 'label' => 'Guía', 'new_tab' => true],
            FieldCodec::rewriteRefs(['type' => 'link'], ['url' => 'https://prod.test/wp-content/uploads/2024/09/guide.pdf', 'label' => 'Guía', 'new_tab' => true], $map)
        );

        $repeater = ['type' => 'repeater', 'fields' => [['id' => 'post', 'type' => 'post_select'], ['id' => 'logo', 'type' => 'text'], ['id' => 'photo', 'type' => 'image']]];
        $this->assertSame(
            [['post' => 78, 'logo' => 'http://site.local/wp-content/uploads/2026/10/hero.jpg', 'photo' => 9002, 'extra' => 'http://site.local/']],
            FieldCodec::rewriteRefs($repeater, [['post' => 135, 'logo' => 'https://prod.test/wp-content/uploads/2024/05/hero.jpg', 'photo' => 502, 'extra' => 'https://prod.test/']], $map)
        );
        $this->assertSame([135], FieldCodec::referencedPostIds($repeater, [['post' => 135]]));
        $this->assertSame(['a', 'b'], FieldCodec::strings(['x' => 'a', 'y' => [1, 'b', '']]));
    }
}
