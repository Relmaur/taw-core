<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Bindings\Expression\Evaluator;
use TAW\Core\Bindings\Settings\Normalizer;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dynamic block settings (ADR-0016): `metadata.tawSettings` expressions set a
 * block's classes, colors and HTML attributes at render time. Each one is
 * evaluated in the block's own context (so loop items differ), cleaned by
 * Settings\Normalizer, and written to the block's outer tag only (a button's
 * colors go to its link, where core puts them). A failed or invalid value is
 * skipped; an empty one changes nothing.
 */
final class BlockSettings
{
    public const KEY = 'tawSettings';

    /** Blocks whose colors belong on an inner tag (the class to find), as core does. */
    public const COLOR_TAG = ['core/button' => 'wp-block-button__link'];

    /** @var array{palette: array<string, string>, custom: bool}|null */
    private static ?array $colors = null;

    public static function register(): void
    {
        add_filter('render_block', [self::class, 'renderBlock'], 11, 3);
    }

    /**
     * render_block (after BlockVisibility and InlineTags).
     *
     * @param array<string, mixed> $parsed
     */
    public static function renderBlock(string $html, array $parsed = [], ?object $block = null): string
    {
        $metadata = $parsed['attrs']['metadata'] ?? null;
        if ($html === '' || !is_array($metadata) || !isset($metadata[self::KEY])) {
            return $html;
        }
        $settings = Normalizer::settings($metadata[self::KEY]);
        if ($settings === []) {
            return $html;
        }

        $plan = self::plan($settings, BindingContext::fromBlock($block ?? new \stdClass()));

        return self::apply($html, $plan, is_string($parsed['blockName'] ?? null) ? $parsed['blockName'] : '');
    }

    /**
     * What a block's settings come to for one context: the classes to add,
     * the style declarations and their marker classes, the attributes, and
     * why any setting was skipped (error|empty|not_a_color|palette_only).
     *
     * @param array{classes?: string, color?: string, background?: string, border?: string, attributes?: array<string, string>} $settings
     * @return array{classes: list<string>, style: array<string, string>, markers: list<string>, attributes: array<string, string>, dropped: array<string, string>}
     */
    public static function plan(array $settings, BindingContext $context): array
    {
        $plan = ['classes' => [], 'style' => [], 'markers' => [], 'attributes' => [], 'dropped' => []];

        if (isset($settings['classes'])) {
            $result = self::value('classes', $settings['classes'], $context);
            $plan['classes'] = $result['value'] === null ? [] : Normalizer::classes($result['value']);
            if ($result['dropped'] !== null) {
                $plan['dropped']['classes'] = $result['dropped'];
            }
        }
        foreach (Normalizer::COLORS as $key => $property) {
            if (!isset($settings[$key])) {
                continue;
            }
            $result = self::value($key, $settings[$key], $context);
            if ($result['value'] === null) {
                $plan['dropped'][$key] = $result['dropped'] ?? 'empty';
                continue;
            }
            $plan['style'][$property] = $result['value'];
            $plan['markers'][] = Normalizer::MARKERS[$key];
        }
        foreach ($settings['attributes'] ?? [] as $name => $expression) {
            $result = self::value($name, $expression, $context);
            if ($result['value'] === null) {
                $plan['dropped'][$name] = $result['dropped'] ?? 'empty';
                continue;
            }
            $plan['attributes'][$name] = $result['value'];
        }

        return $plan;
    }

    /**
     * One setting's cleaned value for a context (also the editor preview,
     * `kind: "setting"`): classes as space-separated names, a color as its CSS
     * value, an attribute as its text.
     *
     * @return array{value: string|null, dropped: string|null, errors: list<array{code: string, at: int}>}
     */
    public static function value(string $setting, string $expression, BindingContext $context): array
    {
        $evaluated = Evaluator::evaluate($expression, $context);
        $errors = $evaluated['errors'];
        if ($errors !== []) {
            return ['value' => null, 'dropped' => 'error', 'errors' => $errors];
        }
        $raw = $evaluated['value'];

        if ($setting === 'classes') {
            $classes = Normalizer::classes($raw);
            return ['value' => $classes === [] ? null : implode(' ', $classes), 'dropped' => $classes === [] ? 'empty' : null, 'errors' => []];
        }
        if (isset(Normalizer::COLORS[$setting])) {
            $colors = self::colors();
            return Normalizer::color($raw, $colors['palette'], $colors['custom']) + ['errors' => []];
        }
        if (Normalizer::isAttribute($setting)) {
            return Normalizer::attribute($setting, $raw) + ['errors' => []];
        }

        return ['value' => null, 'dropped' => 'unknown_setting', 'errors' => []];
    }

    /**
     * Writes a plan to the block's outer tag (colors to COLOR_TAG's tag when
     * the block has one). Existing classes and styles stay; new declarations
     * come last, so they win.
     *
     * @param array{classes: list<string>, style: array<string, string>, markers: list<string>, attributes: array<string, string>, dropped: array<string, string>} $plan
     */
    public static function apply(string $html, array $plan, string $blockName): string
    {
        if ($plan['classes'] === [] && $plan['style'] === [] && $plan['attributes'] === []) {
            return $html;
        }
        $tags = new \WP_HTML_Tag_Processor($html);
        if (!$tags->next_tag()) {
            return $html;
        }
        foreach ($plan['classes'] as $class) {
            $tags->add_class($class);
        }
        foreach ($plan['attributes'] as $name => $value) {
            $tags->set_attribute($name, $value);
        }
        if ($plan['style'] !== []) {
            $inner = self::COLOR_TAG[$blockName] ?? null;
            if ($inner === null || $tags->has_class($inner) || $tags->next_tag(['class_name' => $inner])) {
                self::style($tags, $plan);
            }
        }

        return $tags->get_updated_html();
    }

    /** Resets the palette cache (tests). */
    public static function reset(): void
    {
        self::$colors = null;
    }

    /**
     * @param array{classes: list<string>, style: array<string, string>, markers: list<string>, attributes: array<string, string>, dropped: array<string, string>} $plan
     */
    private static function style(\WP_HTML_Tag_Processor $tags, array $plan): void
    {
        $declarations = implode(';', array_map(static fn (string $property, string $value): string => "{$property}:{$value}", array_keys($plan['style']), $plan['style']));
        $existing = $tags->get_attribute('style');
        $existing = is_string($existing) ? rtrim(trim($existing), ';') : '';
        $tags->set_attribute('style', $existing === '' ? $declarations : "{$existing};{$declarations}");
        foreach ($plan['markers'] as $marker) {
            $tags->add_class($marker);
        }
    }

    /**
     * The site's palette (theme, default and custom colors; slug → CSS
     * variable) and whether custom colors are allowed (`color.custom`, which
     * the editing policy's design layer turns off), once per request.
     *
     * @return array{palette: array<string, string>, custom: bool}
     */
    private static function colors(): array
    {
        if (self::$colors !== null) {
            return self::$colors;
        }
        $palette = [];
        $origins = function_exists('wp_get_global_settings') ? wp_get_global_settings(['color', 'palette']) : [];
        foreach (is_array($origins) ? $origins : [] as $colors) {
            foreach (is_array($colors) ? $colors : [] as $color) {
                $slug = is_array($color) && is_string($color['slug'] ?? null) ? strtolower($color['slug']) : '';
                if ($slug !== '' && preg_match('/^[a-z0-9-]+$/', $slug) === 1) {
                    $palette[$slug] = 'var(--wp--preset--color--' . _wp_to_kebab_case($slug) . ')';
                }
            }
        }
        $custom = function_exists('wp_get_global_settings') ? wp_get_global_settings(['color', 'custom']) : true;

        return self::$colors = ['palette' => $palette, 'custom' => $custom !== false];
    }
}
