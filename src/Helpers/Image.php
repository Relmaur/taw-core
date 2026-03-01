<?php

declare(strict_types=1);

namespace TAW\Helpers;

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
class Image {
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

        if(!$image) {
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
        if($above_fold) {
            $attrs['loading']       = 'eager';
            $attrs['fetchpriority'] = 'high';
            $attrs['decoding']      = 'high';
        } else {
            $attrs['loading']       = 'lazy';
            $attrs['fetchpriority'] = 'low';
            $attrs['decoding']      = 'async';
        }

        // srcset - WordPress generates this from stored image metadata
        $srcset = wp_get_attachment_image_srcset($attachment_id, $size);

        if($srcset) {
            $attrs['srcset'] = $srcset;
        }

        // Sizes - use custom value if provided, otherwise let WordPress calculate
        if(isset($options['sizes'])) {
            $attrs['sizes'] = $options['sizes'];
        } elseif ($srcset) {
            $sizes = wp_get_attachment_image_sizes($attachment_id, $size);
            if($sizes) {
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
     * Generate a <link rel="preload" tag for an above-the-fold image.
     * 
     * Call this in your template BEFORE wp_head(), or hook into wp_head
     * at priority 1-2. Preloading tells the browser to fetch the image
     * immediately, before it discovers the <img> tag in the HTML.
     * 
     * Only preload your single most important image (usually the hero).
     * Preloading multiple images defeats the purpose.
     * 
     * @param int $attachment_id Wordpress attachment ID.
     * @param string $size       Wordpress image size
     * @param string HTML <link> preload tag, or empty string
     */
    public static function preload_tag(int $attachment_id, string $size = 'large'): string {
        if(!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return '';
        }

        $image = wp_get_attachment_image_src($attachment_id, $size);

        if(!$image) {
            return '';
        }

        [$src] = $image;

        //Build preload with srcset for responsive preloading
        $srcset = wp_get_attachment_image_srcset($attachment_id, $size);
        $sizes = wp_get_attachment_image_sizes($attachment_id, $size);

        $tag = sprintf(
            '<link rel="preload" href="%s" as="image"',
            esc_url($src)
        );

        // Responsive preloading - browser picks the right size to preload
        if($srcset && $sizes) {
            $tag .= sprintf(
                ' imagesrcset="%s" imagesize="%s"',
                esc_attr($srcset),
                esc_attr($sizes)
            );
        }

        $tag .= ">\n";

        return $tag;
    }
}