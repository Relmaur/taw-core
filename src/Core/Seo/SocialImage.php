<?php

declare(strict_types=1);

namespace TAW\Core\Seo;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The image link previews use (og:image, twitter:image) in a format every
 * crawler reads.
 *
 * With `modern_image_formats` on (Performance, the default), an uploaded JPEG
 * or PNG is served as AVIF (or WebP): WordPress writes the attachment's main
 * file and sizes in that format and keeps the upload as the "original image".
 * Facebook, WhatsApp, LinkedIn and X don't read AVIF for link previews, so a
 * shared page showed no picture. For social tags this returns the original
 * upload instead, when the served file isn't JPEG/PNG/GIF and the original is
 * (and no bigger than 5 MB, X's limit).
 *
 * Used by SeoMeta, and hooked into Yoast, Rank Math and SmartCrawl's social
 * image filters by register() (Theme::boot()). `taw_social_image_original`
 * (false) turns it off.
 */
final class SocialImage
{
    private const SAFE_MIMES = ['image/jpeg', 'image/png', 'image/gif'];

    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Mime of the last URL swapped, for Yoast's og:image:type (asked right after the URL). */
    private static ?string $lastMime = null;

    /** Width and height of the last original swapped in, when known. @var array{0: int, 1: int}|null */
    private static ?array $lastSize = null;

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // Yoast SEO: URL, then the type of the same image.
        add_filter('wpseo_opengraph_image', [self::class, 'filterUrl']);
        add_filter('wpseo_twitter_image', [self::class, 'filterUrl']);
        add_filter('wpseo_opengraph_image_type', [self::class, 'filterYoastType']);
        // Rank Math: rank_math/opengraph/{network}/image.
        add_filter('rank_math/opengraph/facebook/image', [self::class, 'filterUrl']);
        add_filter('rank_math/opengraph/twitter/image', [self::class, 'filterUrl']);
        // SmartCrawl: [url => [url, width, height, …]].
        add_filter('smartcrawl_get_opengraph_images', [self::class, 'filterSmartCrawl']);
        add_filter('smartcrawl_get_twitter_images', [self::class, 'filterSmartCrawl']);
    }

    /** The URL for social tags of an image attachment ('' when it isn't one). */
    public static function url(int $attachmentId): string
    {
        $original = self::original($attachmentId);
        if ($original !== null) {
            return $original['url'];
        }
        $image = $attachmentId > 0 ? wp_get_attachment_image_src($attachmentId, 'full') : false;

        return is_array($image) ? (string) $image[0] : '';
    }

    /** A social image URL, swapped for the original upload when it is an attachment served as AVIF/WebP. */
    public static function forUrl(string $url): string
    {
        self::$lastMime = null;
        if ($url === '' || !preg_match('/\.(avif|webp)(\?.*)?$/i', $url)) {
            return $url;
        }
        $original = self::original((int) attachment_url_to_postid(preg_replace('/\?.*$/', '', $url) ?? $url));
        if ($original === null) {
            return $url;
        }
        self::$lastMime = $original['mime'];

        return $original['url'];
    }

    /** @param mixed $url */
    public static function filterUrl($url): mixed
    {
        return is_string($url) ? self::forUrl($url) : $url;
    }

    /** @param mixed $type */
    public static function filterYoastType($type): mixed
    {
        $mime = self::$lastMime;
        self::$lastMime = null;

        return $mime ?? $type;
    }

    /**
     * @param mixed $images SmartCrawl's [url => [url, width, height, …]]
     * @return mixed
     */
    public static function filterSmartCrawl($images): mixed
    {
        if (!is_array($images)) {
            return $images;
        }
        $out = [];
        foreach ($images as $key => $image) {
            $url = is_string($key) ? $key : (is_array($image) && is_string($image[0] ?? null) ? $image[0] : '');
            $swapped = $url !== '' ? self::forUrl($url) : $url;
            if ($swapped === $url || !is_array($image)) {
                $out[$key] = $image;
                continue;
            }
            $image[0] = $swapped;
            $size = self::$lastSize;
            if ($size !== null) {
                [$image[1], $image[2]] = $size;
            }
            $out[$swapped] = $image;
        }

        return $out;
    }

    /**
     * The original upload of an attachment served as AVIF/WebP, when it is a
     * JPEG/PNG/GIF of at most 5 MB.
     *
     * @return array{url: string, mime: string}|null
     */
    private static function original(int $attachmentId): ?array
    {
        self::$lastSize = null;
        if ($attachmentId <= 0 || !apply_filters('taw_social_image_original', true, $attachmentId)) {
            return null;
        }
        $file = get_attached_file($attachmentId);
        $served = is_string($file) ? (string) wp_check_filetype($file)['type'] : '';
        if ($served === '' || in_array($served, self::SAFE_MIMES, true)) {
            return null;
        }
        $path = wp_get_original_image_path($attachmentId);
        $url = wp_get_original_image_url($attachmentId);
        if (!is_string($path) || !is_string($url) || !is_file($path) || filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $mime = (string) wp_check_filetype($path)['type'];
        if (!in_array($mime, self::SAFE_MIMES, true)) {
            return null;
        }
        $size = function_exists('wp_getimagesize') ? wp_getimagesize($path) : false;
        if (is_array($size)) {
            self::$lastSize = [(int) $size[0], (int) $size[1]];
        }

        return ['url' => $url, 'mime' => $mime];
    }
}
