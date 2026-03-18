<?php

declare(strict_types=1);

namespace TAW\Helpers;

/**
 * SVG Helper
 *
 * Enables SVG uploads in WordPress, sanitizes uploaded files, and provides
 * rendering utilities similar to the Image helper.
 *
 * Usage:
 *   // In theme setup — enables uploads and auto-sanitization on upload:
 *   Svg::register();
 *
 *   // Render as <img> tag (scripts inside SVG cannot execute):
 *   echo Svg::render($attachment_id, 'Company logo', ['class' => 'logo']);
 *
 *   // Render inline (allows CSS targeting of SVG internals / animations):
 *   echo Svg::inline($attachment_id, ['class' => 'icon', 'title' => 'Menu']);
 *
 *   // Get URL only:
 *   $url = Svg::url($attachment_id);
 *
 * @package TAW
 */
class Svg
{
    // -------------------------------------------------------------------------
    // WordPress integration
    // -------------------------------------------------------------------------

    /**
     * Register WordPress hooks to allow SVG uploads and sanitize on upload.
     *
     * Call once during theme setup (e.g. in functions.php or your Theme class).
     */
    public static function register(): void
    {
        add_filter('upload_mimes', [self::class, 'allowMimeType']);
        add_filter('wp_check_filetype_and_ext', [self::class, 'fixFileTypeDetection'], 10, 5);
        add_filter('wp_handle_upload', [self::class, 'sanitizeOnUpload']);
    }

    /**
     * Add SVG to the list of allowed upload mime types.
     *
     * @internal Used by register().
     */
    public static function allowMimeType(array $mimes): array
    {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';

        return $mimes;
    }

    /**
     * Correct WordPress's filetype detection for SVG files.
     *
     * finfo / mime_content_type often returns 'text/plain' or 'image/svg'
     * for SVG files. This filter normalises the detected type so WordPress
     * doesn't reject the upload.
     *
     * @internal Used by register().
     */
    public static function fixFileTypeDetection(
        array $data,
        string $file,
        string $filename,
        array $mimes,
        string|false $real_mime
    ): array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, ['svg', 'svgz'], true)) {
            $data['ext']  = $ext;
            $data['type'] = 'image/svg+xml';
        }

        return $data;
    }

    /**
     * Sanitize an SVG file immediately after it is uploaded.
     *
     * @internal Used by register().
     */
    public static function sanitizeOnUpload(array $upload): array
    {
        if (($upload['type'] ?? '') !== 'image/svg+xml') {
            return $upload;
        }

        $raw = file_get_contents($upload['file']);

        if ($raw === false) {
            return $upload;
        }

        $clean = self::sanitize($raw);

        if ($clean) {
            file_put_contents($upload['file'], $clean);
        }

        return $upload;
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    /**
     * Sanitize raw SVG content.
     *
     * Strips: <script>, <foreignObject>, <iframe>, <object>, <embed>, <base>,
     * all event-handler attributes (on*), javascript:/vbscript: hrefs,
     * data:text/html URIs, style attributes containing expressions, and PHP tags.
     *
     * @param string $svg Raw SVG markup.
     * @return string     Sanitized SVG markup, or empty string on failure.
     */
    public static function sanitize(string $svg): string
    {
        // Strip PHP processing instructions before the XML parser sees them
        $svg = preg_replace('/<\?php.*?\?>/si', '', $svg) ?? '';
        $svg = preg_replace('/<\?=.*?\?>/si', '', $svg) ?? '';

        $dom = new \DOMDocument();
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (!$loaded) {
            return '';
        }

        $root = $dom->documentElement;

        // Reject anything that is not an SVG document
        if (!$root || strtolower($root->localName) !== 'svg') {
            return '';
        }

        self::cleanNode($root);

        return $dom->saveXML($root) ?: '';
    }

    // -------------------------------------------------------------------------
    // Rendering API  (mirrors Image helper)
    // -------------------------------------------------------------------------

    /**
     * Render a performance-safe <img> tag pointing to the SVG attachment.
     *
     * Using an SVG via <img> is the safest approach: any scripts embedded
     * inside the SVG file cannot execute in this context.
     *
     * @param int    $attachment_id WordPress attachment ID.
     * @param string $alt           Alt text — required for accessibility.
     * @param array  $options {
     *     @type string $class  CSS class(es) to add to the <img> tag.
     *     @type int    $width  Override the width attribute.
     *     @type int    $height Override the height attribute.
     *     @type array  $attr   Any additional HTML attributes as key => value.
     * }
     * @return string HTML <img> tag, or empty string if attachment is invalid.
     */
    public static function render(int $attachment_id, string $alt = '', array $options = []): string
    {
        $url = self::url($attachment_id);

        if (!$url) {
            return '';
        }

        $attrs = [
            'src' => $url,
            'alt' => $alt,
        ];

        [$width, $height] = self::dimensions($attachment_id);

        $attrs['width']  = isset($options['width'])  ? (int) $options['width']  : $width;
        $attrs['height'] = isset($options['height']) ? (int) $options['height'] : $height;

        // Remove null dimensions so we don't emit empty attributes
        $attrs = array_filter($attrs, fn($v) => $v !== null && $v !== 0);

        if (!empty($options['class'])) {
            $attrs['class'] = $options['class'];
        }

        if (isset($options['attr']) && is_array($options['attr'])) {
            $attrs = array_merge($attrs, $options['attr']);
        }

        $html = '<img';
        foreach ($attrs as $key => $value) {
            $html .= sprintf(' %s="%s"', esc_attr((string) $key), esc_attr((string) $value));
        }
        $html .= '>';

        return $html;
    }

    /**
     * Render the SVG inline in the HTML document.
     *
     * Inline SVGs allow full CSS styling of internal paths/shapes and support
     * animations. The file is re-sanitized on every call so it is always safe.
     *
     * @param int   $attachment_id WordPress attachment ID.
     * @param array $options {
     *     @type string $class CSS class(es) appended to the <svg> root element.
     *     @type string $title Accessible title inserted as the first <title> child.
     *     @type array  $attr  Additional attributes set on the <svg> root element.
     * }
     * @return string SVG markup, or empty string if attachment is invalid.
     */
    public static function inline(int $attachment_id, array $options = []): string
    {
        $path = self::filePath($attachment_id);

        if (!$path || !is_readable($path)) {
            return '';
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return '';
        }

        $svg = self::sanitize($raw);

        if (!$svg) {
            return '';
        }

        // Apply options to the SVG root element
        if (!empty($options['class']) || !empty($options['title']) || !empty($options['attr'])) {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadXML($svg, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors(false);

            $root = $dom->documentElement;

            if ($root) {
                if (!empty($options['class'])) {
                    $existing = $root->getAttribute('class');
                    $root->setAttribute('class', trim($existing . ' ' . $options['class']));
                }

                if (!empty($options['attr']) && is_array($options['attr'])) {
                    foreach ($options['attr'] as $attr => $val) {
                        $root->setAttribute((string) $attr, (string) $val);
                    }
                }

                if (!empty($options['title'])) {
                    $titleEl = $dom->createElement('title');
                    $titleEl->appendChild($dom->createTextNode($options['title']));
                    $root->insertBefore($titleEl, $root->firstChild);
                }

                $svg = $dom->saveXML($root) ?: $svg;
            }
        }

        return $svg;
    }

    /**
     * Get the URL for an SVG attachment.
     *
     * @param int $attachment_id WordPress attachment ID.
     * @return string URL, or empty string if the attachment is not a valid SVG.
     */
    public static function url(int $attachment_id): string
    {
        if (!self::isSvg($attachment_id)) {
            return '';
        }

        return wp_get_attachment_url($attachment_id) ?: '';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether an attachment is an SVG file.
     */
    private static function isSvg(int $attachment_id): bool
    {
        return $attachment_id > 0 && get_post_mime_type($attachment_id) === 'image/svg+xml';
    }

    /**
     * Get the absolute filesystem path to an SVG attachment.
     */
    private static function filePath(int $attachment_id): string
    {
        if (!self::isSvg($attachment_id)) {
            return '';
        }

        return get_attached_file($attachment_id) ?: '';
    }

    /**
     * Extract intrinsic width and height from an SVG file.
     *
     * Tries viewBox first (most reliable), then width/height attributes.
     *
     * @return array{int|null, int|null} [width, height]
     */
    private static function dimensions(int $attachment_id): array
    {
        $path = self::filePath($attachment_id);

        if (!$path || !is_readable($path)) {
            return [null, null];
        }

        $raw = file_get_contents($path);

        if (!$raw) {
            return [null, null];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($raw, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $root = $dom->documentElement;

        if (!$root) {
            return [null, null];
        }

        // viewBox="minX minY width height" is the most reliable source
        $viewBox = $root->getAttribute('viewBox');

        if ($viewBox) {
            $parts = preg_split('/[\s,]+/', trim($viewBox));

            if (is_array($parts) && count($parts) === 4) {
                $w = (int) round((float) $parts[2]);
                $h = (int) round((float) $parts[3]);

                if ($w > 0 && $h > 0) {
                    return [$w, $h];
                }
            }
        }

        // Fall back to explicit width/height attributes (strip any CSS units)
        $w = (int) preg_replace('/[^0-9.]/', '', $root->getAttribute('width'));
        $h = (int) preg_replace('/[^0-9.]/', '', $root->getAttribute('height'));

        return [$w ?: null, $h ?: null];
    }

    /**
     * Recursively remove dangerous elements and attributes from a DOM node.
     */
    private static function cleanNode(\DOMNode $node): void
    {
        static $blockedElements = [
            'script', 'foreignobject', 'iframe', 'object', 'embed', 'base',
        ];

        $toRemove = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            /** @var \DOMElement $child */
            $tag = strtolower($child->localName);

            if (in_array($tag, $blockedElements, true)) {
                $toRemove[] = $child;
                continue;
            }

            if ($child->hasAttributes()) {
                self::cleanAttributes($child);
            }

            self::cleanNode($child);
        }

        foreach ($toRemove as $child) {
            $node->removeChild($child);
        }
    }

    /**
     * Strip dangerous attributes from a single DOM element.
     */
    private static function cleanAttributes(\DOMElement $element): void
    {
        $toRemove = [];

        foreach ($element->attributes as $attr) {
            $name  = strtolower($attr->localName);
            $value = $attr->value;

            // All event handlers (onclick, onload, onerror, …)
            if (str_starts_with($name, 'on')) {
                $toRemove[] = $attr->nodeName;
                continue;
            }

            // javascript:, vbscript:, and data:text/html in link-like attributes
            if (in_array($name, ['href', 'src', 'action', 'formaction'], true)
                || $attr->nodeName === 'xlink:href'
            ) {
                $normalized = strtolower(trim($value));

                if (str_starts_with($normalized, 'javascript:')
                    || str_starts_with($normalized, 'vbscript:')
                    || str_starts_with($normalized, 'data:text/html')
                ) {
                    $toRemove[] = $attr->nodeName;
                    continue;
                }
            }

            // CSS expressions and javascript: inside style attributes
            if ($name === 'style') {
                $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');

                if (str_contains($normalized, 'expression(')
                    || str_contains($normalized, 'javascript:')
                ) {
                    $toRemove[] = $attr->nodeName;
                }
            }
        }

        foreach ($toRemove as $attrName) {
            $element->removeAttribute($attrName);
        }
    }
}
