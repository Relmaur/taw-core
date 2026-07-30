<?php

declare(strict_types=1);

namespace TAW\Helpers;


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performance-Optimized Image Helper
 * 
 * 
 * Generates <img> tags with proper performance attributes based on
 * whether the image is above or below the fold
 * 
 * Above-the-fold images (heores, banners):
 *  - loading="eager" (start downloading immediately)
 *  - fetchpriority="high" (prioritize downloading immediately)
 *  - decoding="auto" (let the browser decide)
 *  - Should also be preloaded via <link> in <head> for LCP
 * 
 * Below-the-fold images (everyhting else):
 *  - loading="lazy" (defer download until near viewport)
 *  - fetchpriority="low" (don't compete with critical resources)
 *  - decoding="async" (decode off the main thread)
 * 
 * Usage:
 *  // Below the fold (default - most images)
 *  echo Image::render(get_post_thumbnail_id(), 'large', 'A red barn');
 * 
 * // Above the  fold (hero, banner)
 *  echo Image::render($image_id, 'full', 'Hero image', [
 *      'above_fold' => true,
 *      'sizes' => '100vw',
 *  ]);
 * 
 * // With custom CSS class
 *  echo Image::render($image_id, 'medium', 'Team photo', [
 *    'class' => 'rounded-lg shadow-md',
 * ]);
 * 
 * @paackage TAW
 */
class Image
{
    /**
     * Render a performance-optimized <img> tag.
     * 
     * @param int    $attachment_id WordPress attachment ID.
     * @param string $size          Wordpress image size (thumbnail, medium, large, full).
     * @param string $alt           Alt text - required for accessibility.
     * @param array  $options {
     *      @type bool ·above_fold Whether image is above the fold. Default false.
     *      @type string $sizes Custom sizes attribute. Defualt auto-calculated.
     *      @type string $class CSS class(es) to add to the img tag.
     *      @type array $attr Any additional HTML attributes as key => value
     * }
     * @return string HTML <img> tag, or empty string if attachemnt is invalid.
     */
    public static function render(
        int $attachment_id,
        string $size = 'large',
        string $alt = '',
        array $options = []
    ): string {
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return '';
        }

        $above_fold = $options['above_fold'] ?? false;

        // Get the image soruce for the requested size
        $image = wp_get_attachment_image_src($attachment_id, $size);

        if (!$image) {
            return '';
        }

        [$src, $width, $height] = $image;

        // Build the attributes array
        $attrs = [
            'src'    => $src,
            'alt'    => $alt,
            'width'  => $width,
            'height' => $height,
        ];

        // Performance attributes based on fold position
        if ($above_fold) {
            $attrs['loading']       = 'eager';
            $attrs['fetchpriority'] = 'high';
            $attrs['decoding']      = 'auto';
        } else {
            $attrs['loading']       = 'lazy';
            $attrs['fetchpriority'] = 'low';
            $attrs['decoding']      = 'async';
        }

        // srcset - WordPress generates this from stored image metadata
        $srcset = wp_get_attachment_image_srcset($attachment_id, $size);

        if ($srcset) {
            $attrs['srcset'] = $srcset;
        }

        // Sizes - use custom value if provided, otherwise let WordPress calculate
        if (isset($options['sizes'])) {
            $attrs['sizes'] = $options['sizes'];
        } elseif ($srcset) {
            $sizes = wp_get_attachment_image_sizes($attachment_id, $size);
            if ($sizes) {
                $attrs['sizes'] = $sizes;
            }
        }

        // Optional CSS Class
        if (!empty($options['class'])) {
            $attrs['class'] = $options['class'];
        }

        // Merge any additional custom attributes
        if (isset($options['attr']) && !empty($options['attr']) && is_array($options['attr'])) {
            $attrs = array_merge($attrs, $options['attr']);
        }

        // Build the HTML string
        $html = '<img';
        foreach ($attrs as $key => $value) {
            $html .= sprintf(' %s="%s"', esc_attr($key), esc_attr($value));
        }
        $html .= '>';

        return $html;
    }

    /**
     * Generate a <link rel="preload"> tag for an above-the-fold image.
     *
     * Call this in your template BEFORE wp_head(), or hook into wp_head
     * at priority 1-2. Preloading tells the browser to fetch the image
     * immediately, before it discovers the <img> tag in the HTML.
     *
     * Only preload your single most important image (usually the hero).
     * Preloading multiple images defeats the purpose.
     *
     * The $sizes argument should match whatever you pass as 'sizes' to
     * Image::render() so the browser preloads the same candidate the
     * <img> will ultimately use.
     *
     * @param int         $attachment_id WordPress attachment ID.
     * @param string      $size          WordPress image size.
     * @param string|null $sizes         Custom sizes attribute (e.g. '100vw'). Falls back
     *                                   to the WordPress-generated value when omitted.
     * @return string HTML <link> preload tag, or empty string.
     */
    public static function preload_tag(int $attachment_id, string $size = 'large', ?string $sizes = null): string
    {
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return '';
        }

        $image = wp_get_attachment_image_src($attachment_id, $size);

        if (!$image) {
            return '';
        }

        [$src] = $image;

        $srcset         = wp_get_attachment_image_srcset($attachment_id, $size);
        $resolved_sizes = $sizes ?? wp_get_attachment_image_sizes($attachment_id, $size);

        $tag = sprintf(
            '<link rel="preload" href="%s" as="image" fetchpriority="high"',
            esc_url($src)
        );

        // Responsive preloading — browser picks the right candidate to preload
        if ($srcset && $resolved_sizes) {
            $tag .= sprintf(
                ' imagesrcset="%s" imagesizes="%s"',
                esc_attr($srcset),
                esc_attr($resolved_sizes)
            );
        }

        $tag .= ">\n";

        return $tag;
    }

    /**
     * Get the natural width and height of an image at a given size.
     *
     * @param int    $attachment_id WordPress attachment ID.
     * @param string $size          WordPress image size (thumbnail, medium, large, full).
     * @return array{width: int, height: int}|null Associative array with 'width' and 'height',
     *                                              or null if attachment is invalid.
     *
     * Usage:
     *  $dim = Image::getDimension($id, 'large');
     *  $dim['width'];   // e.g. 1024
     *  $dim['height'];  // e.g. 768
     */
    public static function getDimension(int $attachment_id, string $size = 'full'): ?array
    {
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return null;
        }

        $image = wp_get_attachment_image_src($attachment_id, $size);

        if (!$image) {
            return null;
        }

        return [
            'width'  => (int) $image[1],
            'height' => (int) $image[2],
        ];
    }

    /**
     * Generate a CSS background-image value for use in inline styles.
     *
     * Usage:
     *  // Below the fold (default)
     *  <div style="<?= Image::background($id, 'full') ?>">
     *
     *  // Combine with other CSS properties
     *  <div style="height:400px; <?= Image::background($id, 'full') ?>">
     *
     * To preload a background image, use Performance::configure():
     *  Performance::configure(['preload_images' => [[$id, 'full']]]);
     *
     * @param int    $attachment_id WordPress attachment ID.
     * @param string $size          WordPress image size. Defaults to 'full'.
     * @param array  $options {
     *     @type string $position  CSS background-position value. Default 'center'.
     *     @type string $size_css  CSS background-size value. Default 'cover'.
     *     @type bool   $no_repeat Whether to add background-repeat: no-repeat. Default true.
     *     @type bool   $url_only  Return just the URL instead of the full CSS string. Default false.
     * }
     * @return string Inline CSS string (no style="" wrapper), or empty string if invalid.
     */
    public static function background(
        int $attachment_id,
        string $size = 'full',
        array $options = []
    ): string {
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return '';
        }

        $image = wp_get_attachment_image_src($attachment_id, $size);

        if (!$image) {
            return '';
        }

        $url = esc_url($image[0]);

        $position  = $options['position'] ?? 'center';
        $size_css  = $options['size_css'] ?? 'cover';
        $no_repeat = $options['no_repeat'] ?? true;

        $css  = "background-image: url('{$url}');";
        $css .= " background-position: {$position};";
        $css .= " background-size: {$size_css};";

        if ($no_repeat) {
            $css .= ' background-repeat: no-repeat;';
        }

        if (isset($options['url_only']) && $options['url_only']) {
            return $url;
        }

        return $css;
    }

    /**
     * Get the image URL for a given attachment ID and size.
     *
     * @param int $attachment_id Wordpress attachment ID.
     * @param string $size       Wordpress image size
     * @return string Image URL, or empty string if attachment is invalid.
     */
    public static function url(int $attachment_id, string $size = 'large'): string
    {
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return '';
        }

        $image = wp_get_attachment_image_src($attachment_id, $size);

        if (!$image) {
            return '';
        }

        return $image[0];
    }
}
