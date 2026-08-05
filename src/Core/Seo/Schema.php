<?php

declare(strict_types=1);

namespace TAW\Core\Seo;

use TAW\Core\OptionsPage\OptionsPage;
use TAW\Helpers\Image;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site-wide JSON-LD (schema.org) structured data — the single biggest gap
 * TAW's SEO surface had before this existed: zero schema output anywhere,
 * regardless of SeoMeta's own <meta>/OG/Twitter tags being present.
 *
 * Emits one `<script type="application/ld+json">` per page containing an
 * `@graph` array: Organization + WebSite always, Article on singular posts,
 * BreadcrumbList on any singular post/page, plus anything blocks push onto
 * the graph during template rendering (see push()) — e.g. the FAQ block's
 * FAQPage node, built via the faqPage() helper below.
 *
 * The settings page always registers — it's this class's home for
 * SeoMeta::OUTPUT_MODE_FIELD, the manual override for auto-detection
 * (see that constant's own docblock), so it has to stay reachable even
 * when isSeoPluginActive() currently says a plugin is active. Only the
 * actual JSON-LD *render* stands down whenever that's true — duplicating
 * Yoast/RankMath/etc.'s own Organization/WebSite/Article/Breadcrumb
 * schema would produce conflicting/duplicate structured data, the same
 * failure mode SeoMeta's own docblock describes for <head> tags.
 *
 * Rendered on wp_footer (not wp_head) deliberately: JSON-LD doesn't need
 * to live in <head> for any crawler that matters, and blocks (which push
 * their own nodes onto the graph, e.g. FAQ) render in the body — after
 * wp_head has already fired.
 */
final class Schema
{
    private const OPTIONS_ID = 'taw_seo_schema';

    /** @var array<int, array<string, mixed>> */
    private static array $graph = [];

    public function __construct()
    {
        add_action('init', [$this, 'registerOptions']);

        if (!SeoMeta::isSeoPluginActive()) {
            add_action('wp_footer', [$this, 'render'], 99);
        }
    }

    /**
     * Queue an additional JSON-LD node to be merged into the current
     * request's @graph. Call during template rendering (before wp_footer
     * fires) — a block emitting FAQPage schema alongside its own accordion
     * markup is the primary intended caller. No-ops harmlessly if an SEO
     * plugin is active and nothing will ever render the graph — callers
     * don't need to check isSeoPluginActive() themselves before pushing.
     *
     * @param array<string, mixed> $node
     */
    public static function push(array $node): void
    {
        self::$graph[] = $node;
    }

    /**
     * Builds a schema.org FAQPage node from a TAW repeater's row shape
     * (`[['question' => ..., 'answer' => ...], ...]`, matching the FAQ
     * block pattern documented in taw-theme's AGENTS.md) — the caller
     * still passes the result to push() itself; this only shapes the data.
     * Rows missing either half are skipped rather than emitting an invalid
     * empty Question node.
     *
     * @param array<int, array{question?: string, answer?: string}> $items
     * @return array{'@type': string, mainEntity: array<int, array<string, mixed>>}
     */
    public static function faqPage(array $items): array
    {
        $entities = [];

        foreach ($items as $item) {
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => wp_strip_all_tags($answer),
                ],
            ];
        }

        return [
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    public function registerOptions(): void
    {
        new OptionsPage([
            'id' => self::OPTIONS_ID,
            'title' => __('SEO Schema', 'taw-theme'),
            'menu_title' => __('SEO Schema', 'taw-theme'),
            'icon' => 'dashicons-networking',
            'fields' => [
                [
                    'id' => SeoMeta::OUTPUT_MODE_FIELD,
                    'label' => __('SEO Output', 'taw-theme'),
                    'type' => 'select',
                    'options' => [
                        'auto' => __('Automatic — detect Yoast/RankMath/SmartCrawl and stand down if one is active', 'taw-theme'),
                        'force_on' => __("Always on — ignore detection, always use TAW's own SEO output", 'taw-theme'),
                        'force_off' => __('Always off — defer entirely to another SEO plugin', 'taw-theme'),
                    ],
                    'default' => 'auto',
                    'description' => __('Automatic detection can be unreliable for plugins with no stable version constant (e.g. some SmartCrawl installs) — this overrides it explicitly. Controls SeoMeta\'s <title>/meta/OG tags and this page\'s JSON-LD together.', 'taw-theme'),
                ],
                [
                    'id' => 'organization_type',
                    'label' => __('Organization Type', 'taw-theme'),
                    'type' => 'select',
                    'options' => [
                        'Organization' => __('Organization', 'taw-theme'),
                        'LocalBusiness' => __('Local Business', 'taw-theme'),
                    ],
                    'default' => 'Organization',
                    'width' => '50',
                ],
                [
                    'id' => 'twitter_handle',
                    'label' => __('Twitter / X Handle', 'taw-theme'),
                    'type' => 'text',
                    'placeholder' => '@yoursite',
                    'width' => '50',
                ],
                [
                    'id' => 'organization_name',
                    'label' => __('Organization Name', 'taw-theme'),
                    'type' => 'text',
                    'description' => __('Falls back to the site title when empty.', 'taw-theme'),
                ],
                [
                    'id' => 'organization_logo',
                    'label' => __('Logo', 'taw-theme'),
                    'type' => 'image',
                    'width' => '50',
                ],
                [
                    'id' => 'organization_phone',
                    'label' => __('Phone', 'taw-theme'),
                    'type' => 'text',
                    'width' => '50',
                ],
                [
                    'id' => 'organization_same_as',
                    'label' => __('Social Profile Links', 'taw-theme'),
                    'type' => 'repeater',
                    'button_label' => __('Add Profile', 'taw-theme'),
                    'description' => __('Feeds the "sameAs" entity signal — link every profile that represents this same organization/brand (LinkedIn, Instagram, etc.).', 'taw-theme'),
                    'fields' => [
                        ['id' => 'url', 'label' => __('Profile URL', 'taw-theme'), 'type' => 'url'],
                    ],
                ],
            ],
        ]);
    }

    public function render(): void
    {
        $graph = array_values(array_filter([
            $this->organizationNode(),
            $this->websiteNode(),
            is_singular('post') ? $this->articleNode() : null,
            $this->breadcrumbListNode(),
        ]));

        $graph = array_merge($graph, self::$graph);

        if (empty($graph)) {
            return;
        }

        printf(
            '<script type="application/ld+json">%s</script>' . "\n",
            wp_json_encode(
                ['@context' => 'https://schema.org', '@graph' => $graph],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationNode(): array
    {
        $type = OptionsPage::get('organization_type') ?: 'Organization';
        $name = OptionsPage::get('organization_name') ?: get_bloginfo('name');
        $logoId = (int) OptionsPage::get('organization_logo');
        $phone = OptionsPage::get('organization_phone');

        $node = [
            '@type' => $type,
            '@id' => home_url('/#organization'),
            'name' => $name,
            'url' => home_url('/'),
        ];

        if ($logoId) {
            $logoUrl = Image::url($logoId, 'full');
            if ($logoUrl !== '') {
                $node['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $logoUrl,
                ];
            }
        }

        if ($phone !== '') {
            $node['telephone'] = $phone;
        }

        $sameAs = $this->sameAsLinks();
        if (!empty($sameAs)) {
            $node['sameAs'] = $sameAs;
        }

        return $node;
    }

    /**
     * @return array<int, string>
     */
    private function sameAsLinks(): array
    {
        $raw = OptionsPage::get('organization_same_as', '_taw_', '[]');
        $rows = json_decode((string) $raw, true);
        if (!is_array($rows)) {
            return [];
        }

        $links = [];
        foreach ($rows as $row) {
            $url = is_array($row) ? ($row['url'] ?? '') : '';
            if ($url !== '') {
                $links[] = $url;
            }
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteNode(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => home_url('/#website'),
            'url' => home_url('/'),
            'name' => get_bloginfo('name'),
            'publisher' => ['@id' => home_url('/#organization')],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function articleNode(): ?array
    {
        $postId = get_the_ID();
        if (!$postId) {
            return null;
        }

        $authorId = (int) get_post_field('post_author', $postId);
        $imageId = SeoMeta::ogImageId($postId) ?: (int) get_post_thumbnail_id($postId);

        $node = [
            '@type' => 'Article',
            '@id' => get_permalink($postId) . '#article',
            'headline' => get_the_title($postId),
            'datePublished' => get_the_date('c', $postId),
            'dateModified' => get_the_modified_date('c', $postId),
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => (string) get_permalink($postId),
            ],
            'publisher' => ['@id' => home_url('/#organization')],
            'author' => [
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', $authorId),
            ],
        ];

        if ($imageId) {
            $imageUrl = Image::url($imageId, 'full');
            if ($imageUrl !== '') {
                $node['image'] = $imageUrl;
            }
        }

        return $node;
    }

    /**
     * Home > [parent pages for a Page | primary category for a Post] > current.
     *
     * @return array<string, mixed>|null
     */
    private function breadcrumbListNode(): ?array
    {
        if (!is_singular()) {
            return null;
        }

        $postId = get_the_ID();
        if (!$postId) {
            return null;
        }

        $items = [['name' => __('Home', 'taw-theme'), 'url' => home_url('/')]];

        if (is_page($postId)) {
            $ancestors = array_reverse(get_post_ancestors($postId));
            foreach ($ancestors as $ancestorId) {
                $items[] = ['name' => get_the_title($ancestorId), 'url' => (string) get_permalink($ancestorId)];
            }
        } elseif (get_post_type($postId) === 'post') {
            $categories = get_the_category($postId);
            if (!empty($categories)) {
                $items[] = ['name' => $categories[0]->name, 'url' => (string) get_category_link($categories[0])];
            }
        }

        $items[] = ['name' => get_the_title($postId), 'url' => (string) get_permalink($postId)];

        $listItems = [];
        foreach ($items as $index => $item) {
            $listItems[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $listItems,
        ];
    }
}
