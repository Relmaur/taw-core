<?php

declare(strict_types=1);

namespace TAW\Core\Schema\Definition;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * A custom taxonomy, compiled to register_taxonomy() at init:6 — after the
 * post types (init:5) it attaches to exist.
 *
 *   Schema::taxonomy('genre')->for('book')->labels('Genre', 'Genres')
 *       ->args(['hierarchical' => true]);
 *
 * show_in_rest defaults to on: without it the taxonomy panel doesn't appear
 * in the block editor, and terms aren't reachable over wp/v2.
 */
final class Taxonomy extends Definition
{
    public const KIND = 'taxonomy';

    /**
     * Taxonomy keys WordPress registers itself, plus public query vars that a
     * taxonomy of the same name would hijack (e.g. ?author=, ?year=).
     */
    private const RESERVED = [
        'category', 'post_tag', 'nav_menu', 'link_category', 'post_format',
        'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
        'attachment', 'author', 'name', 'order', 'orderby', 'page', 'paged',
        'post', 'post_type', 's', 'search', 'tag', 'term', 'type', 'year',
        'month', 'day', 'hour', 'minute', 'second', 'feed', 'cat', 'taxonomy',
    ];

    /** @var list<string> */
    private array $objectTypes = [];

    private string $singular = '';
    private string $plural = '';

    /** @var array<string, mixed> */
    private array $args = [];

    /**
     * Post types this taxonomy attaches to.
     */
    public function for(string ...$postTypes): self
    {
        $this->objectTypes = array_values(array_unique([...$this->objectTypes, ...$postTypes]));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function objectTypes(): array
    {
        return $this->objectTypes;
    }

    public function labels(string $singular, string $plural): self
    {
        $this->singular = $singular;
        $this->plural   = $plural;

        return $this;
    }

    /**
     * @param array<string, mixed> $args Passed straight to register_taxonomy().
     */
    public function args(array $args): self
    {
        $this->args = array_replace($this->args, $args);

        return $this;
    }

    public function problems(): array
    {
        return $this->objectTypes === []
            ? [sprintf('Taxonomy "%s" is not attached to any post type — call ->for(\'post_type\').', $this->key())]
            : [];
    }

    /**
     * The final register_taxonomy() arguments, plus the object types under
     * the 'object_type' key (so the whole definition hashes as one array).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $args = array_replace(['show_in_rest' => true], $this->args);

        if ($this->singular !== '' && $this->plural !== '') {
            $labels = [
                'name'          => $this->plural,
                'singular_name' => $this->singular,
                'menu_name'     => $this->plural,
                'all_items'     => sprintf('All %s', $this->plural),
                'edit_item'     => sprintf('Edit %s', $this->singular),
                'add_new_item'  => sprintf('Add New %s', $this->singular),
                'search_items'  => sprintf('Search %s', $this->plural),
                'not_found'     => sprintf('No %s found', strtolower($this->plural)),
            ];
            $args['labels'] = isset($args['labels']) && is_array($args['labels'])
                ? array_replace($labels, $args['labels'])
                : $labels;
        }

        return ['object_type' => $this->objectTypes, 'args' => $args];
    }

    protected static function keyProblem(string $key): ?string
    {
        // register_taxonomy() rejects keys over 32 characters.
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $key) !== 1) {
            return 'use 1–32 lowercase letters, numbers, underscores or dashes (a WordPress limit)';
        }

        return in_array($key, self::RESERVED, true) ? 'this name is reserved by WordPress' : null;
    }
}
