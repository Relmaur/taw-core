<?php

declare(strict_types=1);

namespace TAW\Core\Fields;

use TAW\Core\Content\FieldCodec;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One field's value, typed by its field config (ADR-0009).
 *
 * `echo $value` prints it escaped for its type (see {@see self::html()});
 * `raw()` is the stored value and `value()` the decoded one, the same shape
 * REST `taw_<id>` fields and content export give. Nothing here throws: an
 * empty or missing value reads as '', false, 0, [] or an empty object.
 */
final class Value implements \Stringable
{
    /** @var (\Closure(string): Value)|null */
    private ?\Closure $subField;

    /**
     * @param array<string, mixed>|null $config   The field's config; null when no metabox registers it.
     * @param mixed                     $raw      The stored value ('' when unset).
     * @param (\Closure(string): Value)|null $subField Group sub-field lookup, for group values.
     */
    public function __construct(
        private readonly ?array $config,
        private readonly mixed $raw,
        ?\Closure $subField = null,
        private readonly string $name = ''
    ) {
        $this->subField = $subField;
    }

    /** An empty value (a missing post or field). */
    public static function none(string $name = ''): self
    {
        return new self(null, '', null, $name);
    }

    /** The field type, or null when the field isn't registered. */
    public function type(): ?string
    {
        return $this->config === null ? null : (string) ($this->config['type'] ?? 'text');
    }

    /**
     * The field config, as the metabox registered it.
     *
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        return $this->config;
    }

    /** The stored value, as `Metabox::get()` returns it. */
    public function raw(): mixed
    {
        return $this->raw;
    }

    /**
     * The decoded value: repeater rows and files as arrays, a checkbox as a
     * bool, an image as an int (see FieldCodec). A group gives its
     * sub-fields' values by sub-field id.
     */
    public function value(): mixed
    {
        if ($this->type() === 'group') {
            $values = [];
            foreach ($this->subFieldIds() as $sub) {
                $values[$sub] = $this->field($sub)->value();
            }

            return $values;
        }

        return $this->config === null
            ? ($this->raw === false || $this->raw === null ? '' : $this->raw)
            : FieldCodec::decode($this->config, $this->raw);
    }

    public function isEmpty(): bool
    {
        if ($this->type() === 'group') {
            foreach ($this->subFieldIds() as $sub) {
                if (!$this->field($sub)->isEmpty()) {
                    return false;
                }
            }

            return true;
        }

        $value = $this->value();

        return $value === '' || $value === null || $value === false || $value === [] || $this->raw === '[]'
            || ($this->type() === 'image' && $value === 0);
    }

    public function exists(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * This value, or $default when it's empty. Still a Value, so `echo`
     * escapes the default the same way.
     */
    public function or(mixed $default): self
    {
        return $this->isEmpty() ? new self($this->config, $default, $this->subField, $this->name) : $this;
    }

    /** The value as a plain, unescaped string ('' for structured values). */
    public function text(): string
    {
        $raw = $this->raw;
        if (is_array($raw) || is_object($raw)) {
            $this->mismatch('text()', []);

            return '';
        }

        return $raw === null || $raw === false ? '' : (string) $raw;
    }

    public function bool(): bool
    {
        $this->mismatch('bool()', ['checkbox']);

        return in_array($this->raw, ['1', 1, true, 'true', 'on', 'yes'], true);
    }

    public function int(): int
    {
        $this->mismatch('int()', ['number', 'range', 'image', 'post_select', 'text', 'select']);

        return is_numeric($this->raw) ? (int) $this->raw : 0;
    }

    public function float(): float
    {
        $this->mismatch('float()', ['number', 'range', 'text', 'select']);

        return is_numeric($this->raw) ? (float) $this->raw : 0.0;
    }

    /** An image field (or the first of a files field). */
    public function image(): Image
    {
        $this->mismatch('image()', ['image', 'files']);

        return new Image($this->ids()[0] ?? 0);
    }

    /**
     * A files field's attachments (or an image field's one).
     *
     * @return list<Image>
     */
    public function images(): array
    {
        $this->mismatch('images()', ['files', 'image']);

        return array_map(static fn (int $id): Image => new Image($id), $this->ids());
    }

    /** A post_select field's post (the first one when it holds several). */
    public function post(): PostRef
    {
        $this->mismatch('post()', ['post_select']);

        return new PostRef($this->ids()[0] ?? 0);
    }

    /**
     * A post_select field's posts, in the stored order.
     *
     * @return list<PostRef>
     */
    public function posts(): array
    {
        $this->mismatch('posts()', ['post_select']);

        return array_map(static fn (int $id): PostRef => new PostRef($id), $this->ids());
    }

    /** A link field's link (URL, text, new tab). */
    public function link(): Link
    {
        $this->mismatch('link()', ['link', 'url']);

        if ($this->type() === 'url' || ($this->config === null && is_string($this->raw) && !str_starts_with(ltrim($this->raw), '{'))) {
            return new Link(is_string($this->raw) ? $this->raw : '');
        }

        $link = FieldCodec::decode(['type' => 'link'], $this->raw);

        return Link::from(is_array($link) ? $link : null);
    }

    /** A repeater's rows, each typed by the repeater's sub-fields. */
    public function rows(): Rows
    {
        $this->mismatch('rows()', ['repeater']);

        $rows = is_array($this->raw) ? $this->raw : json_decode(is_string($this->raw) ? $this->raw : '', true);
        if (!is_array($rows)) {
            return new Rows([], []);
        }

        $subFields = [];
        foreach (is_array($this->config['fields'] ?? null) ? $this->config['fields'] : [] as $sub) {
            if (is_array($sub) && isset($sub['id'])) {
                $subFields[(string) $sub['id']] = $sub;
            }
        }

        return new Rows(array_values(array_filter($rows, 'is_array')), $subFields);
    }

    /** A group's sub-field (stored as `{prefix}{group}_{sub}`). */
    public function field(string $sub): self
    {
        if ($this->subField === null) {
            $this->mismatch('field()', ['group']);

            return self::none($sub);
        }

        return ($this->subField)($sub);
    }

    /** A wysiwyg or textarea value with paragraphs added (`wpautop()`), kses-filtered. */
    public function paragraphs(): string
    {
        return wp_kses_post(wpautop($this->text()));
    }

    /**
     * The value as escaped HTML for its type: what `echo` prints.
     *
     * text, textarea, select, number, range, datepicker, color, icon: esc_html();
     * url: esc_url(); wysiwyg: wp_kses_post(); image: the <img> markup;
     * a single post_select: the post's title; link: the <a>; structured types: ''.
     */
    public function html(): string
    {
        return match ($this->type()) {
            'url'         => esc_url($this->text()),
            'wysiwyg'     => wp_kses_post($this->text()),
            'image'       => (new Image($this->ids()[0] ?? 0))->html(),
            'post_select' => empty($this->config['multiple']) ? (new PostRef($this->ids()[0] ?? 0))->html() : '',
            'link'        => $this->link()->html(),
            'checkbox', 'files', 'repeater', 'group', 'gradient_text', 'hubspot_form' => '',
            default       => is_array($this->raw) || is_object($this->raw) ? '' : esc_html($this->text()),
        };
    }

    public function __toString(): string
    {
        return $this->html();
    }

    /**
     * Attachment or post IDs held by this value, in order.
     *
     * @return list<int>
     */
    private function ids(): array
    {
        $raw = $this->raw;
        if (is_string($raw) && str_starts_with(ltrim($raw), '[')) {
            $raw = json_decode($raw, true);
        }
        $ids = is_array($raw) ? $raw : [$raw];

        return array_values(array_filter(array_map(static fn ($id): int => is_numeric($id) ? (int) $id : 0, $ids), static fn (int $id): bool => $id > 0));
    }

    /**
     * @return list<string>
     */
    private function subFieldIds(): array
    {
        $ids = [];
        foreach (is_array($this->config['fields'] ?? null) ? $this->config['fields'] : [] as $sub) {
            if (is_array($sub) && isset($sub['id'])) {
                $ids[] = (string) $sub['id'];
            }
        }

        return $ids;
    }

    /**
     * A debug notice when an accessor doesn't fit the field's known type.
     *
     * @param list<string> $types The types the accessor is for ([] = none).
     */
    private function mismatch(string $accessor, array $types): void
    {
        $type = $this->type();
        if ($type === null || in_array($type, $types, true)) {
            return;
        }

        _doing_it_wrong(
            __CLASS__ . '::' . $accessor,
            sprintf('Field "%s" is a %s field.', esc_html($this->name), esc_html($type)),
            '1.57.0'
        );
    }
}
