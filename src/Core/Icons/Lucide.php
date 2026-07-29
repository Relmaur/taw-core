<?php

declare(strict_types=1);

namespace TAW\Core\Icons;

use TAW\Core\Rest\IconsEndpoint;
use TAW\Helpers\Framework;

/**
 * Opt-in Lucide icon system (https://lucide.dev).
 *
 * The full Lucide icon set is vendored locally (resources/icons/lucide/ +
 * resources/icons/lucide-index.json — see IconsSyncCommand) so nothing here
 * ever makes a network call.
 *
 * Usage:
 *   // In the theme's inc/customizations.php, before Theme::boot():
 *   TAW\Core\Icons\Lucide::enable();
 *
 *   // Then the Metabox/OptionsPage 'icon' field type becomes usable, and
 *   // in templates:
 *   echo TAW\Core\Icons\Lucide::render('house', ['class' => 'w-5 h-5']);
 *
 * render() itself needs no enable() call — same relationship as
 * Svg::register() (upload support) vs Svg::inline()/Svg::render() (template
 * output). Only the admin picker (field type + REST search + JS/CSS) is
 * gated behind enable().
 */
class Lucide
{
    /**
     * Whether the Lucide icon picker has been explicitly enabled for this
     * theme. Must call Lucide::enable() in customizations.php to activate.
     */
    private static bool $enabled = false;

    /**
     * Lazily-loaded, cached icon index (name + searchable keywords).
     *
     * @var array<int, array{name: string, keywords: string[]}>|null
     */
    private static ?array $index = null;

    /**
     * Guards against enqueuing the picker assets more than once per request.
     */
    private static bool $picker_assets_enqueued = false;

    /**
     * Opt-in to the Lucide icon picker.
     * Call this in the theme's customizations.php before Theme::boot().
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Whether the icon picker has been enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Boot the Lucide icon picker.
     * No-op unless enable() was called first.
     */
    public static function init(): void
    {
        if (!self::$enabled) {
            return;
        }

        new IconsEndpoint();
    }

    /**
     * Render a Lucide icon as inline SVG for use in templates.
     *
     * @param string $name    Lucide icon name, e.g. 'house', 'arrow-right'.
     * @param array  $options {
     *     @type string $class Extra CSS class(es) merged onto the <svg> root.
     *     @type string $title Optional <title> element for accessibility.
     *     @type array  $attr  Extra attribute => value pairs set on the <svg> root.
     * }
     * @return string Inline <svg>...</svg> markup, or '' if the icon doesn't exist.
     */
    public static function render(string $name, array $options = []): string
    {
        $path = self::iconPath($name);

        if ($path === null) {
            return '';
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return '';
        }

        if (empty($options['class']) && empty($options['title']) && empty($options['attr'])) {
            return $raw;
        }

        // These are our own vendored, pre-trusted files (not user uploads),
        // so — unlike Svg::inline() — no sanitize pass is needed before
        // parsing; we only need the same attribute-merge technique.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($raw, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $root = $dom->documentElement;

        if (!$root) {
            return $raw;
        }

        if (!empty($options['class'])) {
            $existing = $root->getAttribute('class');
            $root->setAttribute('class', trim($existing . ' ' . $options['class']));
        }

        if (!empty($options['attr']) && is_array($options['attr'])) {
            foreach ($options['attr'] as $attr => $value) {
                $root->setAttribute((string) $attr, (string) $value);
            }
        }

        if (!empty($options['title'])) {
            $titleEl = $dom->createElement('title');
            $titleEl->appendChild($dom->createTextNode($options['title']));
            $root->insertBefore($titleEl, $root->firstChild);
        }

        return $dom->saveXML($root) ?: $raw;
    }

    /**
     * Search the vendored icon index by name or keyword substring.
     *
     * @param string $query Search term. Empty string returns the first $limit icons.
     * @param int    $limit Maximum number of results.
     * @return array<int, array{name: string, svg: string}>
     */
    public static function search(string $query, int $limit = 60): array
    {
        $query = strtolower(trim($query));
        $results = [];

        foreach (self::loadIndex() as $entry) {
            if ($query !== '' && !self::matches($entry, $query)) {
                continue;
            }

            $svg = self::render($entry['name']);

            if ($svg === '') {
                continue;
            }

            $results[] = ['name' => $entry['name'], 'svg' => $svg];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param array{name: string, keywords: string[]} $entry
     */
    private static function matches(array $entry, string $query): bool
    {
        if (str_contains($entry['name'], $query)) {
            return true;
        }

        foreach ($entry['keywords'] as $keyword) {
            if (str_contains($keyword, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{name: string, keywords: string[]}>
     */
    private static function loadIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $path = Framework::path('resources/icons/lucide-index.json');

        if (!is_readable($path)) {
            self::$index = [];
            return self::$index;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$index = is_array($decoded) ? $decoded : [];

        return self::$index;
    }

    /**
     * Resolve an icon name to its vendored SVG file path, guarding against
     * path traversal — only Lucide's own kebab-case naming is allowed.
     */
    private static function iconPath(string $name): ?string
    {
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            return null;
        }

        $path = Framework::path('resources/icons/lucide/' . $name . '.svg');

        return is_file($path) ? $path : null;
    }

    /**
     * Enqueue the icon picker's admin JS/CSS. Called by
     * Metabox::enqueue_field_scripts() when an 'icon' field is present.
     */
    public static function enqueuePickerAssets(): void
    {
        if (self::$picker_assets_enqueued) {
            return;
        }

        self::$picker_assets_enqueued = true;

        $dir = Framework::path('src/Core/Icons/');
        $url = Framework::url('src/Core/Icons/');

        wp_enqueue_style(
            'taw-lucide-picker',
            $url . 'picker.css',
            [],
            filemtime($dir . 'picker.css')
        );

        wp_enqueue_script(
            'taw-lucide-picker',
            $url . 'picker.js',
            ['alpinejs'],
            filemtime($dir . 'picker.js'),
            true
        );

        wp_localize_script('taw-lucide-picker', 'tawLucidePicker', [
            'restUrl' => rest_url('taw/v1/icons'),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}
