<?php

declare(strict_types=1);

namespace TAW\Core\Seo;

use TAW\Core\Metabox\Metabox;
use TAW\Helpers\Image;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-post SEO meta (meta title, meta description, social/OG image) — a
 * capability TAW has never owned natively. Every real site either has an
 * SEO plugin installed (Yoast, most commonly) or has nothing at all: no
 * <meta name="description">, no OG/Twitter tags, no per-post title
 * override.
 *
 * Dual-write, Yoast-aware by design — never assumes a plugin is present,
 * never fights one that is:
 *
 *   - No SEO plugin active: registers its own lightweight metabox
 *     (`_taw_seo_meta_title`/`_taw_seo_meta_description`/`_taw_seo_og_image`)
 *     and renders the actual <head> tags itself on wp_head.
 *   - Yoast active: TAW's own metabox and <head> output both stand down —
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

    private const YOAST_TITLE_KEY = '_yoast_wpseo_title';
    private const YOAST_DESCRIPTION_KEY = '_yoast_wpseo_metadesc';
    private const YOAST_OG_IMAGE_ID_KEY = '_yoast_wpseo_opengraph-image-id';
    private const YOAST_OG_IMAGE_URL_KEY = '_yoast_wpseo_opengraph-image';

    public function __construct()
    {
        if (!self::isSeoPluginActive()) {
            add_action('init', [$this, 'registerMetabox']);
            add_action('wp_head', [$this, 'renderHeadTags'], 1);
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
                    'description' => __('Falls back to the post title when empty.', 'taw-theme'),
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
            ],
        ]);
    }

    public function renderHeadTags(): void
    {
        if (!is_singular()) {
            return;
        }

        $postId = get_the_ID();
        if (!$postId) {
            return;
        }

        $title = self::metaTitle($postId) ?: get_the_title($postId);
        $description = self::metaDescription($postId);
        $ogImageId = self::ogImageId($postId) ?: get_post_thumbnail_id($postId);
        $imageUrl = $ogImageId ? Image::url($ogImageId, 'full') : '';
        $url = (string) get_permalink($postId);

        if ($description !== '') {
            printf('<meta name="description" content="%s">' . "\n", esc_attr($description));
        }

        printf('<meta property="og:type" content="website">' . "\n");
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

        printf('<meta name="twitter:card" content="%s">' . "\n", $imageUrl !== '' ? 'summary_large_image' : 'summary');
        printf('<meta name="twitter:title" content="%s">' . "\n", esc_attr($title));
        if ($description !== '') {
            printf('<meta name="twitter:description" content="%s">' . "\n", esc_attr($description));
        }
        if ($imageUrl !== '') {
            printf('<meta name="twitter:image" content="%s">' . "\n", esc_url($imageUrl));
        }
    }
}
