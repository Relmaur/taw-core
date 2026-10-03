<?php

declare(strict_types=1);

namespace TAW\Core\Bindings\Settings;

// No ABSPATH guard: pure class (no WordPress calls), like Expression\Parser.

/**
 * Dynamic block settings (ADR-0016): the shape of `metadata.tawSettings` and
 * how an expression's result is cleaned before it touches a block's tag.
 * Nothing is trusted: classes are cleaned like sanitize_html_class(), colors
 * come from the palette or a closed CSS color grammar, attribute names from an
 * allow-list. `tests/fixtures/block-settings.json` is the spec (the editor's
 * settings.ts passes it too).
 */
final class Normalizer
{
    /** The color settings and the CSS property each one sets. */
    public const COLORS = ['color' => 'color', 'background' => 'background-color', 'border' => 'border-color'];

    /** The class core adds with each color, so theme styles keyed on it apply. */
    public const MARKERS = ['color' => 'has-text-color', 'background' => 'has-background', 'border' => 'has-border-color'];

    public const ATTRIBUTES = ['id', 'title', 'aria-label', 'aria-description'];

    public const MAX_CLASSES = 20;

    public const MAX_ATTRIBUTES = 10;

    public const MAX_VALUE_LENGTH = 500;

    /** CSS named colors (CSS Color 4), lowercase. */
    private const NAMED = [
        'aliceblue', 'antiquewhite', 'aqua', 'aquamarine', 'azure', 'beige', 'bisque', 'black', 'blanchedalmond',
        'blue', 'blueviolet', 'brown', 'burlywood', 'cadetblue', 'chartreuse', 'chocolate', 'coral',
        'cornflowerblue', 'cornsilk', 'crimson', 'cyan', 'darkblue', 'darkcyan', 'darkgoldenrod', 'darkgray',
        'darkgreen', 'darkgrey', 'darkkhaki', 'darkmagenta', 'darkolivegreen', 'darkorange', 'darkorchid',
        'darkred', 'darksalmon', 'darkseagreen', 'darkslateblue', 'darkslategray', 'darkslategrey',
        'darkturquoise', 'darkviolet', 'deeppink', 'deepskyblue', 'dimgray', 'dimgrey', 'dodgerblue', 'firebrick',
        'floralwhite', 'forestgreen', 'fuchsia', 'gainsboro', 'ghostwhite', 'gold', 'goldenrod', 'gray', 'green',
        'greenyellow', 'grey', 'honeydew', 'hotpink', 'indianred', 'indigo', 'ivory', 'khaki', 'lavender',
        'lavenderblush', 'lawngreen', 'lemonchiffon', 'lightblue', 'lightcoral', 'lightcyan',
        'lightgoldenrodyellow', 'lightgray', 'lightgreen', 'lightgrey', 'lightpink', 'lightsalmon',
        'lightseagreen', 'lightskyblue', 'lightslategray', 'lightslategrey', 'lightsteelblue', 'lightyellow',
        'lime', 'limegreen', 'linen', 'magenta', 'maroon', 'mediumaquamarine', 'mediumblue', 'mediumorchid',
        'mediumpurple', 'mediumseagreen', 'mediumslateblue', 'mediumspringgreen', 'mediumturquoise',
        'mediumvioletred', 'midnightblue', 'mintcream', 'mistyrose', 'moccasin', 'navajowhite', 'navy', 'oldlace',
        'olive', 'olivedrab', 'orange', 'orangered', 'orchid', 'palegoldenrod', 'palegreen', 'paleturquoise',
        'palevioletred', 'papayawhip', 'peachpuff', 'peru', 'pink', 'plum', 'powderblue', 'purple',
        'rebeccapurple', 'red', 'rosybrown', 'royalblue', 'saddlebrown', 'salmon', 'sandybrown', 'seagreen',
        'seashell', 'sienna', 'silver', 'skyblue', 'slateblue', 'slategray', 'slategrey', 'snow', 'springgreen',
        'steelblue', 'tan', 'teal', 'thistle', 'tomato', 'turquoise', 'violet', 'wheat', 'white', 'whitesmoke',
        'yellow', 'yellowgreen', 'transparent', 'currentcolor',
    ];

    /**
     * The settings a block may carry, from `metadata.tawSettings`: expression
     * strings only, unknown keys and refused attribute names dropped, at most
     * MAX_ATTRIBUTES attributes.
     *
     * @return array{classes?: string, color?: string, background?: string, border?: string, attributes?: array<string, string>}
     */
    public static function settings(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (['classes', 'color', 'background', 'border'] as $key) {
            if (is_string($raw[$key] ?? null) && trim($raw[$key]) !== '') {
                $out[$key] = $raw[$key];
            }
        }
        $attributes = [];
        foreach (is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [] as $name => $expression) {
            if (count($attributes) >= self::MAX_ATTRIBUTES) {
                break;
            }
            if (is_string($name) && self::isAttribute($name) && is_string($expression) && trim($expression) !== '') {
                $attributes[$name] = $expression;
            }
        }
        if ($attributes !== []) {
            $out['attributes'] = $attributes;
        }

        return $out;
    }

    /**
     * Space-separated class names, each cleaned like sanitize_html_class()
     * (percent-encoded octets removed, then anything but A–Z, a–z, 0–9, _ and -),
     * empty ones and duplicates dropped, at most MAX_CLASSES.
     *
     * @return list<string>
     */
    public static function classes(string $value): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
            $clean = self::className($token);
            if ($clean !== '' && !in_array($clean, $out, true)) {
                $out[] = $clean;
            }
            if (count($out) === self::MAX_CLASSES) {
                break;
            }
        }

        return $out;
    }

    /**
     * A color for CSS: a palette slug → its CSS value, else (when the site
     * allows custom colors) a hex, numeric rgb()/rgba()/hsl()/hsla() or named
     * color, lowercased.
     *
     * @param array<string, string> $palette slug → CSS value (e.g. var(--wp--preset--color--primary))
     * @return array{value: string|null, dropped: string|null} dropped: empty|not_a_color|palette_only
     */
    public static function color(string $value, array $palette, bool $custom = true): array
    {
        $color = strtolower(trim($value));
        if ($color === '') {
            return ['value' => null, 'dropped' => 'empty'];
        }
        if (isset($palette[$color])) {
            return ['value' => $palette[$color], 'dropped' => null];
        }
        if (!self::isCssColor($color)) {
            return ['value' => null, 'dropped' => 'not_a_color'];
        }

        return $custom ? ['value' => $color, 'dropped' => null] : ['value' => null, 'dropped' => 'palette_only'];
    }

    /** An attribute an expression may set: id, title, aria-label, aria-description or data-*. */
    public static function isAttribute(string $name): bool
    {
        return in_array($name, self::ATTRIBUTES, true) || preg_match('/^data-[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1;
    }

    /**
     * An attribute's value: `id` is one class-like token (the first), the
     * others are plain text (tags stripped, trimmed, at most MAX_VALUE_LENGTH
     * characters). The HTML tag processor escapes it.
     *
     * @return array{value: string|null, dropped: string|null}
     */
    public static function attribute(string $name, string $value): array
    {
        if ($name === 'id') {
            $first = preg_split('/\s+/', trim($value))[0] ?? '';
            $clean = self::className($first);
        } else {
            $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));
            $clean = mb_substr($clean, 0, self::MAX_VALUE_LENGTH);
        }

        return $clean === '' ? ['value' => null, 'dropped' => 'empty'] : ['value' => $clean, 'dropped' => null];
    }

    private static function className(string $token): string
    {
        $token = (string) preg_replace('/%[a-fA-F0-9][a-fA-F0-9]/', '', $token);

        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $token);
    }

    private static function isCssColor(string $color): bool
    {
        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $color) === 1) {
            return true;
        }
        if (in_array($color, self::NAMED, true)) {
            return true;
        }
        // rgb()/rgba()/hsl()/hsla() with numbers only: no var(), calc(), url() or other functions.
        $number = '[-+]?(?:\d+\.?\d*|\.\d+)(?:%|deg)?';

        return preg_match('/^(?:rgba?|hsla?)\(\s*' . $number . '(?:\s*[,\/]?\s*' . $number . '){2,3}\s*\)$/', $color) === 1;
    }
}
