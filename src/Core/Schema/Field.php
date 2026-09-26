<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Fluent builder for one field definition.
 *
 * It produces exactly the array `new Metabox([...])` / `new OptionsPage([...])`
 * already accept (ADR-0004 § Decision 2) — nothing more. So:
 *
 *   Field::text('subtitle')->label('Subtitle')->required()
 *
 * is the same as writing
 *
 *   ['id' => 'subtitle', 'type' => 'text', 'label' => 'Subtitle', 'required' => true]
 *
 * and anything the builder has no method for goes through with():
 *
 *   Field::select('size')->options([...])->with(['conditions' => [...]])
 *
 * The field engine (storage, sanitizing, admin UI) is still Metabox; this
 * class only makes definitions shorter and typo-resistant.
 */
final class Field
{
    /**
     * Every field type the Metabox engine can render and store.
     *
     * Keep in sync with Metabox's renderer (and, from Step 4, the JSON
     * Schema file's enum — a test enforces that).
     */
    public const TYPES = [
        'text', 'url', 'number', 'textarea', 'wysiwyg', 'select', 'checkbox',
        'color', 'datepicker', 'range', 'image', 'icon', 'files', 'gradient_text',
        'hubspot_form', 'group', 'post_select', 'repeater', 'link',
    ];

    /** Types that hold nested fields. */
    private const CONTAINER_TYPES = ['group', 'repeater'];

    /** @var array<string, mixed> */
    private array $config;

    private function __construct(string $type, string $id)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown field type "%s" for field "%s". Known types: %s.',
                $type,
                $id,
                implode(', ', self::TYPES)
            ));
        }

        // Field ids become meta keys (prefix + id) and HTML ids.
        if (preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid field id "%s": use letters, numbers, underscores and dashes only.',
                $id
            ));
        }

        $this->config = ['id' => $id, 'type' => $type];
    }

    /**
     * Any field type by name — for types without a shortcut below.
     */
    public static function make(string $type, string $id): self
    {
        return new self($type, $id);
    }

    public static function text(string $id): self { return new self('text', $id); }
    public static function url(string $id): self { return new self('url', $id); }
    public static function number(string $id): self { return new self('number', $id); }
    public static function textarea(string $id): self { return new self('textarea', $id); }
    public static function wysiwyg(string $id): self { return new self('wysiwyg', $id); }
    public static function select(string $id): self { return new self('select', $id); }
    public static function checkbox(string $id): self { return new self('checkbox', $id); }
    public static function color(string $id): self { return new self('color', $id); }
    public static function datepicker(string $id): self { return new self('datepicker', $id); }
    public static function range(string $id): self { return new self('range', $id); }
    public static function image(string $id): self { return new self('image', $id); }
    public static function icon(string $id): self { return new self('icon', $id); }
    public static function files(string $id): self { return new self('files', $id); }
    public static function gradientText(string $id): self { return new self('gradient_text', $id); }
    public static function hubspotForm(string $id): self { return new self('hubspot_form', $id); }
    /** A link: URL, text and "open in a new tab", stored as one JSON value (v1.59.0+). */
    public static function link(string $id): self { return new self('link', $id); }
    public static function group(string $id): self { return new self('group', $id); }
    public static function postSelect(string $id): self { return new self('post_select', $id); }
    public static function repeater(string $id): self { return new self('repeater', $id); }

    public function label(string $label): self
    {
        return $this->set('label', $label);
    }

    public function description(string $description): self
    {
        return $this->set('description', $description);
    }

    public function placeholder(string $placeholder): self
    {
        return $this->set('placeholder', $placeholder);
    }

    public function default(mixed $value): self
    {
        return $this->set('default', $value);
    }

    public function required(bool $required = true): self
    {
        return $this->set('required', $required);
    }

    public function readonly(bool $readonly = true): self
    {
        return $this->set('readonly', $readonly);
    }

    /**
     * Whether core blocks may show this field through the `taw/field` Block
     * Bindings source (ADR-0010). Post, term and option fields may unless
     * this says false; user fields only when it says true.
     */
    public function bindings(bool $bindable = true): self
    {
        return $this->set('bindings', $bindable);
    }

    /**
     * Choices for select fields: [value => label].
     *
     * @param array<string|int, string> $options
     */
    public function options(array $options): self
    {
        return $this->set('options', $options);
    }

    public function min(int|float $min): self
    {
        return $this->set('min', $min);
    }

    public function max(int|float $max): self
    {
        return $this->set('max', $max);
    }

    public function step(int|float $step): self
    {
        return $this->set('step', $step);
    }

    /**
     * Grid width in the admin form, passed through as-is (see the Metabox
     * README for accepted values).
     */
    public function width(string|int $width): self
    {
        return $this->set('width', $width);
    }

    /**
     * Nested fields — only for group and repeater.
     *
     * @param list<Field|array<string, mixed>> $fields
     */
    public function fields(array $fields): self
    {
        if (!in_array($this->config['type'], self::CONTAINER_TYPES, true)) {
            throw new \LogicException(sprintf(
                'Field "%s" is a %s; only group and repeater fields hold nested fields.',
                $this->config['id'],
                $this->config['type']
            ));
        }

        return $this->set('fields', $fields);
    }

    /**
     * Any other Metabox field key (conditions, editor, layout, …), merged
     * as-is. Keeps the builder complete without mirroring every option.
     *
     * @param array<string, mixed> $config
     */
    public function with(array $config): self
    {
        // id and type are fixed at construction; changing them here would
        // bypass the validation above.
        unset($config['id'], $config['type']);
        $this->config = array_replace($this->config, $config);

        return $this;
    }

    public function id(): string
    {
        return (string) $this->config['id'];
    }

    /**
     * The field as the array Metabox/OptionsPage accept, nested fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $config = $this->config;

        if (isset($config['fields']) && is_array($config['fields'])) {
            $config['fields'] = self::normalizeList($config['fields']);
        }

        return $config;
    }

    /**
     * Normalize a list mixing Field builders and raw legacy arrays into
     * plain arrays — so definitions can use either style, or both.
     *
     * @param array<int|string, mixed> $fields
     * @return list<array<string, mixed>>
     */
    public static function normalizeList(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if ($field instanceof self) {
                $normalized[] = $field->toArray();
            } elseif (is_array($field)) {
                if (isset($field['fields']) && is_array($field['fields'])) {
                    $field['fields'] = self::normalizeList($field['fields']);
                }
                $normalized[] = $field;
            } else {
                throw new \InvalidArgumentException('A field must be a Field builder or an array.');
            }
        }

        return $normalized;
    }

    private function set(string $key, mixed $value): self
    {
        $this->config[$key] = $value;

        return $this;
    }
}
