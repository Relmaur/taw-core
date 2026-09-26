<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Fields\Image;
use TAW\Core\Fields\Value;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Field type × block attribute → the value WordPress puts into the block
 * (ADR-0010 decision 6).
 *
 * `null` means "not bound here": WordPress then keeps the block's saved
 * content, which is also what an empty field does. `false` removes an HTML
 * attribute (a button's target/rel when the link isn't "new tab").
 */
final class AttributeMap
{
    /** Types whose text binds as-is. */
    private const TEXT_TYPES = ['text', 'select', 'number', 'range', 'color', 'icon', 'datepicker'];

    /** Types that never bind (a group's sub-field does, through `sub`). */
    public const UNBINDABLE = ['checkbox', 'files', 'repeater', 'group', 'gradient_text', 'hubspot_form'];

    public static function valueFor(Value $value, Target $target, Reference $ref): mixed
    {
        $type = $value->type();
        if ($type === null || in_array($type, self::UNBINDABLE, true) || $value->isEmpty()) {
            return null;
        }

        return match (true) {
            in_array($type, self::TEXT_TYPES, true) => self::text($value, $target, $type),
            $type === 'textarea'                    => self::textarea($value, $target),
            $type === 'wysiwyg'                     => self::wysiwyg($value, $target),
            $type === 'url'                         => self::url($value, $target),
            $type === 'image'                       => self::image($value->image(), $target, $ref),
            $type === 'link'                        => self::link($value, $target),
            $type === 'post_select'                 => self::postSelect($value, $target, $ref),
            default                                 => null,
        };
    }

    private static function text(Value $value, Target $target, string $type): mixed
    {
        return match ($target->kind) {
            'text'  => esc_html($value->text()),
            'plain' => $value->text(),
            'date'  => $type === 'datepicker' ? $value->text() : null,
            default => null,
        };
    }

    private static function textarea(Value $value, Target $target): mixed
    {
        return match ($target->kind) {
            'text'  => nl2br(esc_html($value->text()), false),
            'plain' => $value->text(),
            default => null,
        };
    }

    private static function wysiwyg(Value $value, Target $target): mixed
    {
        // No wpautop(): the block is already the paragraph (as the data panel
        // stores it, ADR-0007 addendum 1).
        return match ($target->kind) {
            'text'  => wp_kses_post($value->text()),
            'plain' => trim(wp_strip_all_tags($value->text())),
            default => null,
        };
    }

    private static function url(Value $value, Target $target): mixed
    {
        return match ($target->kind) {
            'url'   => self::cleanUrl($value->text()),
            'text'  => esc_html($value->text()),
            'plain' => $value->text(),
            default => null,
        };
    }

    private static function image(Image $image, Target $target, Reference $ref): mixed
    {
        if (!$image->exists()) {
            return null;
        }

        return match ($target->kind) {
            'id'    => $image->id(),
            'url'   => self::emptyToNull($image->url($ref->size)),
            'plain' => $target->attribute === 'title' ? self::emptyToNull(get_the_title($image->id())) : self::emptyToNull($image->alt()),
            'text'  => $target->block === 'core/image' ? self::emptyToNull(esc_html((string) wp_get_attachment_caption($image->id()))) : null,
            default => null,
        };
    }

    private static function link(Value $value, Target $target): mixed
    {
        $link = $value->link();
        if (!$link->exists()) {
            return null;
        }

        return match ($target->kind) {
            'url'    => self::cleanUrl($link->url()),
            'text'   => esc_html($link->label() !== '' ? $link->label() : $link->url()),
            'plain'  => $link->label() !== '' ? $link->label() : $link->url(),
            'target' => $link->newTab() ? '_blank' : false,
            'rel'    => $link->newTab() ? 'noopener' : false,
            default  => null,
        };
    }

    private static function postSelect(Value $value, Target $target, Reference $ref): mixed
    {
        if (!empty($value->config()['multiple'])) {
            return null;
        }
        $post = $value->post();
        if (!$post->exists()) {
            return null;
        }

        $thumbnail = new Image((int) get_post_thumbnail_id($post->id()));

        return match ($target->kind) {
            'text'  => $target->block === 'core/image' ? self::image($thumbnail, $target, $ref) : esc_html($post->title()),
            'url'   => $target->block === 'core/image' ? self::image($thumbnail, $target, $ref) : self::cleanUrl($post->url()),
            'id'    => self::image($thumbnail, $target, $ref),
            'plain' => $target->attribute === 'alt' ? self::image($thumbnail, $target, $ref) : $post->title(),
            default => null,
        };
    }

    private static function cleanUrl(string $url): ?string
    {
        return self::emptyToNull(esc_url_raw($url));
    }

    private static function emptyToNull(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
