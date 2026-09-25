<?php

declare(strict_types=1);

namespace TAW\Core\Schema\Definition;

use TAW\Core\Schema\Field;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * A group of fields shown on posts — compiled into a `new Metabox([...])`
 * at init:8 (ADR-0004 § Decision 2). Reusing Metabox means a schema
 * fieldset gets the exact save path, sanitizing, admin UI, REST meta and
 * content export that hand-written metaboxes already have.
 *
 *   Schema::fieldset('book_details')
 *       ->title('Book details')
 *       ->on('book')
 *       ->fields([
 *           Field::text('subtitle')->label('Subtitle'),
 *           Field::image('cover'),
 *       ]);
 *
 * The fieldset key becomes the Metabox id. Values are stored as post meta
 * under prefix + field id — `_taw_subtitle` by default, like every other
 * TAW field.
 */
final class Fieldset extends Definition
{
    public const KIND = 'fieldset';

    private string $title = '';

    /** @var list<string> */
    private array $screens = [];

    /** @var list<Field|array<string, mixed>> */
    private array $fields = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Where the fieldset appears: post type keys, page slugs, or template
     * filenames (`page-about.php`) — the same values Metabox's `screens`
     * accepts.
     */
    public function on(string ...$screens): self
    {
        $this->screens = array_values(array_unique([...$this->screens, ...$screens]));

        return $this;
    }

    /**
     * @param list<Field|array<string, mixed>> $fields Field builders, raw arrays, or a mix.
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /** 'normal' (default), 'side' or 'advanced'. */
    public function context(string $context): self
    {
        return $this->with(['context' => $context]);
    }

    /** 'high' (default), 'default' or 'low'. */
    public function priority(string $priority): self
    {
        return $this->with(['priority' => $priority]);
    }

    /**
     * Where the fieldset shows in the block editor: "panel" (the TAW Data
     * sidebar) or "metabox". Unset, the site default applies (ADR-0007).
     */
    public function ui(string $ui): self
    {
        return $this->with(['ui' => $ui]);
    }

    /**
     * Meta key prefix for this fieldset's fields. Defaults to Metabox's
     * `_taw_`; change it only for a reason (e.g. matching an existing key
     * scheme), since REST keys and content export follow it.
     */
    public function prefix(string $prefix): self
    {
        return $this->with(['prefix' => $prefix]);
    }

    /**
     * Any other Metabox config key (tabs, icon, show_on, …), merged as-is.
     *
     * @param array<string, mixed> $config
     */
    public function with(array $config): self
    {
        // These are owned by the builder; letting with() change them would
        // make the registry key and the compiled Metabox id disagree.
        unset($config['id'], $config['fields'], $config['screens'], $config['screen']);
        $this->extra = array_replace($this->extra, $config);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function screens(): array
    {
        return $this->screens;
    }

    public function problems(): array
    {
        $problems = [];

        // Metabox silently defaults to 'page' when no screen is given; for a
        // schema definition that's almost certainly a mistake, so refuse it.
        if ($this->screens === []) {
            $problems[] = sprintf('Fieldset "%s" has no location — call ->on(\'post_type\').', $this->key());
        }
        if ($this->fields === []) {
            $problems[] = sprintf('Fieldset "%s" has no fields.', $this->key());
        }
        if (array_key_exists('ui', $this->extra) && !\TAW\Core\DataPanel\Ui::isValue($this->extra['ui'])) {
            $problems[] = sprintf('Fieldset "%s" ui must be one of %s.', $this->key(), implode(', ', \TAW\Core\DataPanel\Ui::VALUES));
        }

        return $problems;
    }

    /**
     * The exact config for `new Metabox(...)`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_replace($this->extra, [
            'id'      => $this->key(),
            'title'   => $this->title !== '' ? $this->title : $this->key(),
            'screens' => $this->screens,
            'fields'  => Field::normalizeList($this->fields),
        ]);
    }

    /**
     * Every field id this fieldset puts in Metabox's field registry —
     * top-level ids plus group sub-fields as "{group}_{sub}", mirroring how
     * Metabox registers them. Used for collision detection.
     *
     * @return list<string>
     */
    public function registryFieldIds(): array
    {
        $ids = [];

        foreach ($this->toArray()['fields'] as $field) {
            $ids[] = (string) $field['id'];

            if (($field['type'] ?? '') === 'group' && is_array($field['fields'] ?? null)) {
                foreach ($field['fields'] as $sub) {
                    $ids[] = $field['id'] . '_' . $sub['id'];
                }
            }
        }

        return $ids;
    }
}
