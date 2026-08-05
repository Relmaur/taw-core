<?php

declare(strict_types=1);

namespace TAW\Core\Seo;

use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Helpers\Image;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-post SEO meta (meta title, meta description, social/OG image,
 * noindex) plus the technical on-page signals that ride alongside them —
 * a capability TAW has never owned natively. Every real site either has an
 * SEO plugin installed (Yoast, most commonly) or has nothing at all: no
 * <title> override, no <meta name="description">, no canonical tag, no
 * OG/Twitter tags, no robots control.
 *
 * Dual-write, Yoast-aware by design — never assumes a plugin is present,
 * never fights one that is:
 *
 *   - No SEO plugin active: registers its own lightweight metabox
 *     (`_taw_seo_meta_title`/`_taw_seo_meta_description`/`_taw_seo_og_image`/
 *     `_taw_seo_noindex`) and renders the actual <title>, <link rel="canonical">,
 *     <meta name="description">, robots, and OG/Twitter tags itself.
 *   - Yoast active: TAW's own metabox and output both stand down —
 *     Yoast already owns both the editor UI and the <head> tags, and
 *     duplicating either would be actively harmful (split/duplicate SEO
 *     signal, confusing crawlers). SeoExtractCommand/SeoInjectCommand
 *     read and write Yoast's own meta keys directly in this case, so an
 *     agent-driven rewrite still lands somewhere the site owner's existing
 *     Yoast UI reflects it.
 *
 * RankMath/SmartCrawl/other plugins are detected as "a plugin is active"
 * (TAW stands down) but their own meta keys are not read/written — narrower
 * than "every SEO plugin," a deliberate scoping call given how differently
 * each stores this data. Extending write support to another specific
 * plugin is a contained addition here, not a redesign.
 */
final class SeoMeta
{
    public const TITLE_FIELD = 'seo_meta_title';
    public const DESCRIPTION_FIELD = 'seo_meta_description';
    public const OG_IMAGE_FIELD = 'seo_og_image';
    public const NOINDEX_FIELD = 'seo_noindex';

    private const YOAST_TITLE_KEY = '_yoast_wpseo_title';
    private const YOAST_DESCRIPTION_KEY = '_yoast_wpseo_metadesc';
    private const YOAST_OG_IMAGE_ID_KEY = '_yoast_wpseo_opengraph-image-id';
    private const YOAST_OG_IMAGE_URL_KEY = '_yoast_wpseo_opengraph-image';

    public function __construct()
    {
        if (!self::isSeoPluginActive()) {
            add_action('init', [$this, 'registerMetabox']);
            add_action('wp_head', [$this, 'renderHeadTags'], 1);
            add_filter('document_title_parts', [$this, 'filterDocumentTitle']);
            add_filter('wp_robots', [$this, 'filterRobots']);
            // renderHeadTags() prints its own canonical above; core's
            // rel_canonical() is also hooked to wp_head (default priority
            // 10) and would otherwise print a duplicate.
            remove_action('wp_head', 'rel_canonical');
        }
    }

    /**
     * True if a known third-party SEO plugin is active — Yoast is
     * detected specifically (its meta keys are read/written directly by
     * this class and the seo:extract/seo:inject CLI commands); RankMath
     * and SmartCrawl are detected only to stand TAW's own UI/output down,
     * not to write their meta.
     */
    public static function isSeoPluginActive(): bool
    {
        return self::isYoastActive()
            || defined('RANK_MATH_VERSION')
            || defined('WPMU_DEV_SITE_ID'); // SmartCrawl's own detection is unreliable across versions; this is a best-effort signal, not authoritative.
    }

    public static function isYoastActive(): bool
    {
        return defined('WPSEO_VERSION');
    }

    /**
     * Where meta title/description/OG image are actually stored right
     * now, resolved fresh on every call — never cached, since plugin
     * activation state can change between an extract and a later inject.
     *
     * `source: 'unsupported'` (null keys) means a *different* SEO plugin
     * is active (RankMath, SmartCrawl, etc.) — one this class doesn't know
     * how to write to. Falling back to TAW's own native keys in that case
     * would silently write to postmeta nothing reads or renders (TAW's own
     * metabox/head-output both stand down whenever any plugin is active,
     * not just Yoast) — a real bug caught before shipping. Callers MUST
     * check `source` before writing; write() itself just no-ops rather
     * than guessing.
     *
     * @return array{source: string, title_key: ?string, description_key: ?string}
     */
    public static function targetMetaKeys(): array
    {
        if (self::isYoastActive()) {
            return [
                'source' => 'yoast',
                'title_key' => self::YOAST_TITLE_KEY,
                'description_key' => self::YOAST_DESCRIPTION_KEY,
            ];
        }

        if (self::isSeoPluginActive()) {
            return ['source' => 'unsupported', 'title_key' => null, 'description_key' => null];
        }

        return [
            'source' => 'taw_native',
            'title_key' => '_taw_' . self::TITLE_FIELD,
            'description_key' => '_taw_' . self::DESCRIPTION_FIELD,
        ];
    }

    public static function metaTitle(int $postId): string
    {
        $key = self::targetMetaKeys()['title_key'];

        return $key !== null ? (string) get_post_meta($postId, $key, true) : '';
    }

    public static function metaDescription(int $postId): string
    {
        $key = self::targetMetaKeys()['description_key'];

        return $key !== null ? (string) get_post_meta($postId, $key, true) : '';
    }

    public static function ogImageId(int $postId): int
    {
        if (self::targetMetaKeys()['source'] === 'unsupported') {
            return 0;
        }

        if (self::isYoastActive()) {
            return absint(get_post_meta($postId, self::YOAST_OG_IMAGE_ID_KEY, true));
        }

        return absint(get_post_meta($postId, '_taw_' . self::OG_IMAGE_FIELD, true));
    }

    /**
     * Writes meta title/description/OG image to whichever store is
     * currently authoritative (Yoast's keys or TAW's native ones),
     * sanitized with the same rules Metabox::sanitizeValue() applies to
     * its own text/textarea/image fields — hand-applied here because
     * Yoast's keys were never registered in TAW's own field registry, so
     * Metabox::sanitizeValue() has no config to look them up by.
     *
     * No-ops entirely (writes nothing, no error) when `source` is
     * 'unsupported' — callers that need to surface this to a user (e.g.
     * SeoInjectCommand) must check TargetMetaKeys()['source'] themselves
     * *before* calling write() and refuse loudly there; silently doing
     * nothing here would look like success.
     */
    public static function write(int $postId, ?string $title, ?string $description, ?int $ogImageId): void
    {
        $keys = self::targetMetaKeys();
        if ($keys['source'] === 'unsupported') {
            return;
        }

        if ($title !== null && $keys['title_key'] !== null) {
            update_post_meta($postId, $keys['title_key'], sanitize_text_field($title));
        }

        if ($description !== null && $keys['description_key'] !== null) {
            update_post_meta($postId, $keys['description_key'], sanitize_textarea_field($description));
        }

        if ($ogImageId !== null) {
            $ogImageId = absint($ogImageId);

            if (self::isYoastActive()) {
                update_post_meta($postId, self::YOAST_OG_IMAGE_ID_KEY, $ogImageId);
                update_post_meta($postId, self::YOAST_OG_IMAGE_URL_KEY, $ogImageId > 0 ? Image::url($ogImageId, 'full') : '');
            } else {
                update_post_meta($postId, '_taw_' . self::OG_IMAGE_FIELD, $ogImageId);
            }
        }
    }

    public function registerMetabox(): void
    {
        new Metabox([
            'id' => 'taw_seo_meta',
            'title' => __('SEO & Social', 'taw-theme'),
            'screens' => ['page', 'post'],
            'context' => 'normal',
            'priority' => 'low',
            'fields' => [
                [
                    'id' => self::TITLE_FIELD,
                    'label' => __('Meta Title', 'taw-theme'),
                    'type' => 'text',
                    'description' => __('Falls back to the post title when empty. Also used as the <title> tag itself, not just social previews.', 'taw-theme'),
                ],
                [
                    'id' => self::DESCRIPTION_FIELD,
                    'label' => __('Meta Description', 'taw-theme'),
                    'type' => 'textarea',
                    'description' => __('Shown in search results and link previews. ~155 characters is the practical limit before truncation.', 'taw-theme'),
                ],
                [
                    'id' => self::OG_IMAGE_FIELD,
                    'label' => __('Social Share Image', 'taw-theme'),
                    'type' => 'image',
                    'description' => __('Used for Open Graph/Twitter card previews. Falls back to the featured image when empty.', 'taw-theme'),
                ],
                [
                    'id' => self::NOINDEX_FIELD,
                    'label' => __('Hide from search engines', 'taw-theme'),
                    'type' => 'checkbox',
                    'description' => __('Adds noindex — the page stays live but search engines are asked not to list it.', 'taw-theme'),
                ],
            ],
        ]);
    }

    /**
     * Overrides the actual <title> tag via document_title_parts — separate
     * from renderHeadTags()'s og:title/twitter:title, which read the same
     * stored value but were, before this existed, the *only* place it was
     * ever used. Leaves the 'site' part of $parts alone so the default
     * "Page Title {separator} Site Name" assembly still applies; this only
     * replaces the page-specific half.
     *
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    public function filterDocumentTitle(array $parts): array
    {
        if (!is_singular()) {
            return $parts;
        }

        $postId = get_the_ID();
        if (!$postId) {
            return $parts;
        }

        $title = self::metaTitle($postId);
        if ($title !== '') {
            $parts['title'] = $title;
        }

        return $parts;
    }

    /**
     * Adds noindex to WordPress core's wp_robots pipeline (available since
     * 5.7) instead of printing a separate <meta name="robots"> tag —
     * avoids a duplicate/conflicting tag alongside core's own
     * max-image-preview:large default and anything else already hooked
     * into wp_robots.
     *
     * @param array<string, bool> $robots
     * @return array<string, bool>
     */
    public function filterRobots(array $robots): array
    {
        if (!is_singular()) {
            return $robots;
        }

        $postId = get_the_ID();
        if ($postId && Metabox::get_bool($postId, self::NOINDEX_FIELD)) {
            $robots['noindex'] = true;
        }

        return $robots;
    }

    public function renderHeadTags(): void
    {
        $context = $this->resolveContext();
        if ($context === null) {
            return;
        }

        $title = $context['title'];
        $description = $context['description'];
        $url = $context['url'];
        $ogType = $context['og_type'];
        $imageUrl = $context['image_id'] ? Image::url($context['image_id'], 'full') : '';

        if ($url !== '') {
            printf('<link rel="canonical" href="%s">' . "\n", esc_url($url));
        }

        if ($description !== '') {
            printf('<meta name="description" content="%s">' . "\n", esc_attr($description));
        }

        printf('<meta property="og:site_name" content="%s">' . "\n", esc_attr(get_bloginfo('name')));
        printf('<meta property="og:locale" content="%s">' . "\n", esc_attr(get_locale()));
        printf('<meta property="og:type" content="%s">' . "\n", esc_attr($ogType));
        printf('<meta property="og:title" content="%s">' . "\n", esc_attr($title));
        if ($description !== '') {
            printf('<meta property="og:description" content="%s">' . "\n", esc_attr($description));
        }
        if ($url !== '') {
            printf('<meta property="og:url" content="%s">' . "\n", esc_url($url));
        }
        if ($imageUrl !== '') {
            printf('<meta property="og:image" content="%s">' . "\n", esc_url($imageUrl));
        }
        if ($ogType === 'article') {
            if ($context['published'] !== '') {
                printf('<meta property="article:published_time" content="%s">' . "\n", esc_attr($context['published']));
            }
            if ($context['modified'] !== '') {
                printf('<meta property="article:modified_time" content="%s">' . "\n", esc_attr($context['modified']));
            }
        }

        printf('<meta name="twitter:card" content="%s">' . "\n", $imageUrl !== '' ? 'summary_large_image' : 'summary');
        $twitterHandle = (string) OptionsPage::get('twitter_handle');
        if ($twitterHandle !== '') {
            printf('<meta name="twitter:site" content="%s">' . "\n", esc_attr($twitterHandle));
        }
        printf('<meta name="twitter:title" content="%s">' . "\n", esc_attr($title));
        if ($description !== '') {
            printf('<meta name="twitter:description" content="%s">' . "\n", esc_attr($description));
        }
        if ($imageUrl !== '') {
            printf('<meta name="twitter:image" content="%s">' . "\n", esc_url($imageUrl));
        }
    }

    /**
     * Resolves the current request into head-tag data, or null when the
     * current context has nothing sensible to describe (404, feeds, admin,
     * etc.). Covers more than singular posts/pages — home, archives (term,
     * date, author, post-type), and search all previously rendered no
     * description/OG/Twitter/canonical output at all, even with no SEO
     * plugin installed.
     *
     * @return array{title: string, description: string, url: string, og_type: string, image_id: int, published: string, modified: string}|null
     */
    private function resolveContext(): ?array
    {
        if (is_singular()) {
            return $this->singularContext();
        }

        if (is_front_page() || is_home()) {
            return [
                'title' => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'url' => home_url('/'),
                'og_type' => 'website',
                'image_id' => 0,
                'published' => '',
                'modified' => '',
            ];
        }

        if (is_archive() || is_search()) {
            $title = is_search()
                ? sprintf(__('Search results for "%s"', 'taw-theme'), get_search_query())
                : (string) get_the_archive_title();

            return [
                'title' => $title,
                'description' => wp_strip_all_tags((string) get_the_archive_description()),
                'url' => $this->currentUrl(),
                'og_type' => 'website',
                'image_id' => 0,
                'published' => '',
                'modified' => '',
            ];
        }

        return null;
    }

    /**
     * @return array{title: string, description: string, url: string, og_type: string, image_id: int, published: string, modified: string}|null
     */
    private function singularContext(): ?array
    {
        $postId = get_the_ID();
        if (!$postId) {
            return null;
        }

        $isPost = get_post_type($postId) === 'post';

        // A static front page is still is_singular() (it's a Page), so it
        // never reaches resolveContext()'s dedicated is_front_page()/is_home()
        // branch below — without this, a placeholder post_title (e.g. an
        // internal "Index" title never meant to be public-facing) leaks
        // straight into og:title/twitter:title, the same gap core's own
        // wp_get_document_title() already closes for the real <title> tag.
        $isFrontPage = is_front_page();

        return [
            'title' => self::metaTitle($postId) ?: ($isFrontPage ? get_bloginfo('name') : get_the_title($postId)),
            'description' => self::metaDescription($postId) ?: ($isFrontPage ? get_bloginfo('description') : ''),
            'url' => (string) (wp_get_canonical_url($postId) ?: get_permalink($postId)),
            'og_type' => $isPost ? 'article' : 'website',
            'image_id' => self::ogImageId($postId) ?: (int) get_post_thumbnail_id($postId),
            'published' => $isPost ? (string) get_the_date('c', $postId) : '',
            'modified' => $isPost ? (string) get_the_modified_date('c', $postId) : '',
        ];
    }

    /**
     * Best-effort current-URL resolution for non-singular contexts, where
     * wp_get_canonical_url() (core's own helper) doesn't apply — it only
     * ever resolves singular posts/pages. Built from $wp->request (the
     * matched rewrite path), the same primitive core itself resolves
     * pretty-permalink archive/search URLs from.
     */
    private function currentUrl(): string
    {
        global $wp;
        $path = isset($wp->request) ? (string) $wp->request : '';

        return $path !== '' ? home_url(user_trailingslashit($path)) : home_url('/');
    }
}
