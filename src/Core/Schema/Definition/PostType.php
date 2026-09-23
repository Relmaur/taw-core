<?php

declare(strict_types=1);

namespace TAW\Core\Schema\Definition;

use TAW\Core\Editing\Rules;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * A custom post type, compiled to register_post_type() at init:5.
 *
 *   Schema::postType('book')
 *       ->labels('Book', 'Books')
 *       ->args(['menu_icon' => 'dashicons-book', 'has_archive' => true]);
 *
 * Defaults differ from WordPress's own on purpose, because a TAW post type
 * exists to hold structured data that the block editor and REST can reach:
 *   - public and show_in_rest are on (show_in_rest is what enables the
 *     block editor and wp/v2 access);
 *   - 'custom-fields' is ALWAYS added to supports — WordPress only exposes
 *     registered post meta over REST for post types that support it, so
 *     without it every TAW field on this post type would silently vanish
 *     from wp/v2 (and, later, from Block Bindings).
 * Everything in args() overrides the defaults, except that last rule.
 */
final class PostType extends Definition
{
    public const KIND = 'post_type';

    /**
     * Post type keys WordPress itself registers or reserves. Registering one
     * would silently replace core behavior (e.g. 'post' or 'attachment').
     */
    private const RESERVED = [
        'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css',
        'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
        'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'action', 'author', 'order', 'theme',
    ];

    private string $singular = '';
    private string $plural = '';

    /** @var array<string, mixed> */
    private array $args = [];

    /** @var string|array<string, mixed>|null */
    private string|array|null $editing = null;

    /**
     * Singular and plural names; the rest of WordPress's labels are
     * generated from them. Pass an explicit 'labels' array in args() to
     * override any generated one.
     */
    public function labels(string $singular, string $plural): self
    {
        $this->singular = $singular;
        $this->plural   = $plural;

        return $this;
    }

    /**
     * Arguments passed straight to register_post_type() (merged over the
     * defaults described in the class docblock).
     *
     * @param array<string, mixed> $args
     */
    public function args(array $args): self
    {
        $this->args = array_replace($this->args, $args);

        return $this;
    }

    /**
     * How the block editor is locked down for this post type (ADR-0005): a
     * level ('structured') or a content rule (['allow' => ['core/*'],
     * 'lock' => 'contentOnly', 'template' => [...]]). The site's editing
     * policy can still override it. Only applied when the theme calls
     * Boot::editing(); never passed to register_post_type().
     *
     * @param string|array<string, mixed> $rule
     */
    public function editing(string|array $rule): self
    {
        $this->editing = $rule;

        return $this;
    }

    /**
     * @return string|array<string, mixed>|null
     */
    public function editingRule(): string|array|null
    {
        return $this->editing;
    }

    public function problems(): array
    {
        if ($this->editing === null) {
            return [];
        }

        return array_map(
            fn (string $error): string => sprintf('Post type "%s" %s', $this->key(), $error),
            Rules::validateContentRule($this->editing, '/editing')
        );
    }

    /**
     * The final register_post_type() arguments.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $args = array_replace(['public' => true, 'show_in_rest' => true], $this->args);

        $labels = $this->generatedLabels();
        if (isset($args['labels']) && is_array($args['labels'])) {
            $labels = array_replace($labels, $args['labels']);
        }
        if ($labels !== []) {
            $args['labels'] = $labels;
        }

        $supports = $args['supports'] ?? ['title', 'editor', 'thumbnail'];
        // supports => false means "no title/editor", but meta must still work.
        $supports = is_array($supports) ? $supports : [];
        if (!in_array('custom-fields', $supports, true)) {
            $supports[] = 'custom-fields';
        }
        $args['supports'] = array_values($supports);

        return $args;
    }

    protected static function keyProblem(string $key): ?string
    {
        // register_post_type() rejects keys over 20 characters.
        if (preg_match('/^[a-z0-9_-]{1,20}$/', $key) !== 1) {
            return 'use 1–20 lowercase letters, numbers, underscores or dashes (a WordPress limit)';
        }

        return in_array($key, self::RESERVED, true) ? 'this post type is reserved by WordPress' : null;
    }

    /**
     * @return array<string, string>
     */
    private function generatedLabels(): array
    {
        if ($this->singular === '' || $this->plural === '') {
            return [];
        }

        // Built from the names the developer passed (already translated at
        // their call site). The surrounding English phrases are TAW's own
        // defaults; override any of them through args(['labels' => [...]]).
        return [
            'name'               => $this->plural,
            'singular_name'      => $this->singular,
            'menu_name'          => $this->plural,
            'all_items'          => sprintf('All %s', $this->plural),
            'add_new_item'       => sprintf('Add New %s', $this->singular),
            'edit_item'          => sprintf('Edit %s', $this->singular),
            'new_item'           => sprintf('New %s', $this->singular),
            'view_item'          => sprintf('View %s', $this->singular),
            'view_items'         => sprintf('View %s', $this->plural),
            'search_items'       => sprintf('Search %s', $this->plural),
            'not_found'          => sprintf('No %s found', strtolower($this->plural)),
            'not_found_in_trash' => sprintf('No %s found in Trash', strtolower($this->plural)),
        ];
    }
}
