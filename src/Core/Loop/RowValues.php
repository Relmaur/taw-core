<?php

declare(strict_types=1);

namespace TAW\Core\Loop;

use TAW\Core\Bindings\AttributeMap;
use TAW\Core\Bindings\BindingContext;
use TAW\Core\Bindings\Bindings;
use TAW\Core\Bindings\FieldResolver;
use TAW\Core\Bindings\InlineTags;
use TAW\Core\Bindings\Reference;
use TAW\Core\Bindings\Target;
use TAW\Core\Bindings\TagResolver;
use TAW\Core\Fields\Image;
use TAW\Core\Fields\Value;
use TAW\Taw;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `@row.*` and `@loop.*` inside a TAW Loop item (ADR-0014):
 *
 * - a repeater row: its sub-fields (`@row.award`), typed like any field;
 * - a post: its properties and fields (`@row.title` = `@post.title`);
 * - a term: `id`, `name`, `slug`, `url`, `description`, `count`, `taxonomy`,
 *   and the term's TAW fields;
 * - an image: `id`, `url`, `alt`, `caption`, `title`, `description`,
 *   `width`, `height`, and `image` (the whole image, for image blocks);
 * - `@loop.index|count|first|last|even|odd` for any item.
 *
 * Outside a loop, or for a name the item doesn't have, there is nothing.
 */
final class RowValues
{
    public const TERM_PROPERTIES = ['id', 'name', 'slug', 'url', 'description', 'count', 'taxonomy'];

    public const IMAGE_PROPERTIES = ['id', 'url', 'alt', 'caption', 'title', 'description', 'width', 'height'];

    /**
     * Plain text for chips, expressions and conditions (the caller escapes).
     *
     * @param array<string, mixed> $args `row` or `loop` (the name), plus `format`, `size`.
     */
    public static function text(array $args, BindingContext $context): ?string
    {
        $item = $context->item;
        if ($item === null) {
            return null;
        }
        if (isset($args['loop'])) {
            return self::nonEmpty($item->loop((string) $args['loop']));
        }

        $value = self::value((string) ($args['row'] ?? ''), $item, $context, Target::inline(), $args);

        return is_scalar($value) ? self::nonEmpty(trim((string) $value)) : null;
    }

    /**
     * A value for a bound attribute (`args: {"row": "photo"}`): shaped for the
     * target like any field (images give their URL, ID or alt).
     *
     * @param array<string, mixed> $args
     */
    public static function forTarget(array $args, BindingContext $context, Target $target): mixed
    {
        $item = $context->item;
        if ($item === null) {
            return null;
        }
        if (isset($args['loop'])) {
            $text = self::nonEmpty($item->loop((string) $args['loop']));
            return $text !== null && $target->kind === 'text' ? esc_html($text) : $text;
        }

        return self::value((string) ($args['row'] ?? ''), $item, $context, $target, $args);
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function value(string $name, Item $item, BindingContext $context, Target $target, array $args): mixed
    {
        if ($name === '') {
            return null;
        }
        $ref = Reference::fromArgs(['field' => $name] + array_intersect_key($args, ['size' => 1, 'format' => 1]));

        return match ($item->kind) {
            Item::ROW   => $item->data instanceof \TAW\Core\Fields\Row && $ref !== null ? self::field($item->data->field($name), $target, $ref) : null,
            Item::POST  => self::post($name, $context, $target, $args),
            Item::TERM  => $item->data instanceof \WP_Term ? self::term($name, $item->data, $target, $ref) : null,
            Item::IMAGE => is_int($item->data) ? self::image($name, new Image($item->data), $target, $ref) : null,
            default     => null,
        };
    }

    private static function field(Value $value, Target $target, ?Reference $ref): mixed
    {
        if ($ref === null || $value->type() === null || !FieldResolver::bindable($value, null, 'post')) {
            return null;
        }
        // A checkbox reads as "1" or nothing, so conditions can test it.
        if ($target->kind === 'inline' && $value->type() === 'checkbox') {
            return $value->bool() ? '1' : null;
        }

        return AttributeMap::valueFor($value, $target, $ref);
    }

    /**
     * A post item: `@row.x` is `@post.x`.
     *
     * @param array<string, mixed> $args
     */
    private static function post(string $name, BindingContext $context, Target $target, array $args): mixed
    {
        $format = isset($args['format']) ? ['format' => $args['format']] : [];
        if (TagResolver::knows("post.{$name}")) {
            $text = InlineTags::value(['tag' => "post.{$name}"] + $format, $context);
            return $text !== null && $target->kind === 'text' ? esc_html($text) : $text;
        }
        $ref = Reference::fromArgs(['field' => $name] + array_intersect_key($args, ['size' => 1, 'format' => 1]));

        return $ref === null ? null : Bindings::resolver()->resolve($ref, $context, $target);
    }

    private static function term(string $name, \WP_Term $term, Target $target, ?Reference $ref): mixed
    {
        if (in_array($name, self::TERM_PROPERTIES, true)) {
            $text = match ($name) {
                'id'          => (string) $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'url'         => ($link = get_term_link($term)) instanceof \WP_Error ? '' : $link,
                'description' => wp_strip_all_tags($term->description),
                'count'       => (string) $term->count,
                default       => $term->taxonomy,
            };
            return self::shaped(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $target, $name === 'url');
        }

        return self::field(Taw::term($term)->field($name), $target, $ref);
    }

    private static function image(string $name, Image $image, Target $target, ?Reference $ref): mixed
    {
        if (!$image->exists()) {
            return null;
        }
        $size = $ref->size ?? 'full';

        // The whole image, for an image block: its URL, ID or alt, as the attribute needs.
        if ($name === 'image') {
            return match ($target->kind) {
                'id'    => $image->id(),
                'url'   => $image->url($size),
                'inline' => $image->url($size),
                default => self::shaped($image->alt(), $target, false),
            };
        }
        if (!in_array($name, self::IMAGE_PROPERTIES, true)) {
            return null;
        }
        if ($name === 'id' && $target->kind === 'id') {
            return $image->id();
        }
        $post = get_post($image->id());
        $text = match ($name) {
            'id'          => (string) $image->id(),
            'url'         => $image->url($size),
            'alt'         => $image->alt(),
            'caption'     => $post instanceof \WP_Post ? $post->post_excerpt : '',
            'title'       => $post instanceof \WP_Post ? $post->post_title : '',
            'description' => $post instanceof \WP_Post ? wp_strip_all_tags($post->post_content) : '',
            'width'       => (string) ($image->width($size) ?: ''),
            default       => (string) ($image->height($size) ?: ''),
        };

        return self::shaped($text, $target, $name === 'url');
    }

    /** Text for a target: escaped for rich text, a URL for URL targets, plain otherwise. */
    private static function shaped(string $text, Target $target, bool $isUrl): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        return match ($target->kind) {
            'text'  => esc_html($text),
            'url'   => $isUrl ? esc_url_raw($text) : null,
            'id'    => null,
            default => $text,
        };
    }

    private static function nonEmpty(?string $text): ?string
    {
        return $text === null || $text === '' ? null : $text;
    }
}
