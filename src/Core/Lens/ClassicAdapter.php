<?php

declare(strict_types=1);

namespace TAW\Core\Lens;

use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feeds TAW Lens's source map (SourceMap) on a classic theme from WordPress's own hooks, with no template
 * changes (umbrella ADR-0006 § 7):
 *
 * - the main template is the root node (`template_include`, last);
 * - template parts (`get_header()`, `get_template_part()`, …) open nodes (`wp_before_load_template` /
 *   `wp_after_load_template`); blocks open their own (MetaBlock/Block::render());
 * - reads: TAW fields on posts, terms and users (the metadata filters, with the field's default when that's
 *   what the reader returned), TAW options and the site title and tagline (`pre_option`), post titles
 *   (`the_title`), menu items (`wp_get_nav_menu_items`);
 * - the manifest prints last in `wp_footer`.
 *
 * Started by Lens on `template_redirect`, only when the Lens gate is open, and never for feeds, embeds or
 * robots.txt (their output isn't HTML a person looks at).
 */
final class ClassicAdapter
{
    /** WordPress options Lens attributes, with their labels: the rest of WordPress's options are settings. */
    private const WP_OPTIONS = ['blogname' => 'Site Title', 'blogdescription' => 'Tagline'];

    private static bool $started = false;

    private static bool $reading = false;

    /** @var array<string, array<string, array<string, mixed>>> object type:subtype → meta key → field config */
    private static array $fields = [];

    public static function start(): void
    {
        if (self::$started || is_feed() || is_embed() || is_robots() || is_trackback()) {
            return;
        }
        self::$started = true;
        SourceMap::enable();

        add_filter('template_include', [self::class, 'root'], PHP_INT_MAX);
        add_action('wp_before_load_template', [self::class, 'openPart'], 10, 1);
        add_action('wp_after_load_template', [self::class, 'closePart'], 10, 1);
        add_filter('get_post_metadata', [self::class, 'postMeta'], PHP_INT_MAX, 4);
        add_filter('get_term_metadata', [self::class, 'termMeta'], PHP_INT_MAX, 4);
        add_filter('get_user_metadata', [self::class, 'userMeta'], PHP_INT_MAX, 4);
        add_filter('pre_option', [self::class, 'option'], PHP_INT_MAX, 3);
        add_filter('the_title', [self::class, 'title'], PHP_INT_MAX, 2);
        add_filter('wp_get_nav_menu_items', [self::class, 'menu'], PHP_INT_MAX, 2);
        add_action('wp_footer', [self::class, 'printManifest'], PHP_INT_MAX);
    }

    public static function root(string $template): string
    {
        SourceMap::root($template);

        return $template;
    }

    /** @var list<int|null> */
    private static array $parts = [];

    public static function openPart(string $file): void
    {
        $name = preg_replace('/\.php$/', '', self::themeRelative($file)) ?? $file;
        self::$parts[] = SourceMap::open('part', $name, SourceMap::humanize(basename($name)), $file);
    }

    public static function closePart(string $file = ''): void
    {
        SourceMap::close(array_pop(self::$parts));
    }

    public static function postMeta(mixed $short, int $objectId, string $metaKey, bool $single): mixed
    {
        return self::meta('post', $short, $objectId, $metaKey, $single);
    }

    public static function termMeta(mixed $short, int $objectId, string $metaKey, bool $single): mixed
    {
        return self::meta('term', $short, $objectId, $metaKey, $single);
    }

    public static function userMeta(mixed $short, int $objectId, string $metaKey, bool $single): mixed
    {
        return self::meta('user', $short, $objectId, $metaKey, $single);
    }

    /**
     * A TAW field read on a post, term or user: recorded with its label, type, edit screen and the value the
     * reader returned (the field's default when nothing is stored and the field declares one).
     */
    private static function meta(string $type, mixed $short, int $objectId, string $metaKey, bool $single): mixed
    {
        if (self::$reading || $objectId <= 0 || $metaKey === '') {
            return $short;
        }
        $config = self::fieldConfig($type, $objectId, $metaKey);
        if ($config === null) {
            return $short;
        }

        self::$reading = true;
        try {
            $value = $short ?? get_metadata_raw($type, $objectId, $metaKey, $single);
            $value = $single ? $value : (is_array($value) ? ($value[0] ?? '') : $value);
            $default = false;
            if ($type === 'post') {
                $shown = SourceMap::quietly(static fn (): mixed => Metabox::withDefault($objectId, $metaKey, $value));
                $default = $shown !== $value;
                $value = $shown;
            }
            $admin = match ($type) {
                'post' => (string) get_edit_post_link($objectId, 'raw'),
                'term' => (string) get_edit_term_link($objectId),
                default => (string) get_edit_user_link($objectId),
            };
        } finally {
            self::$reading = false;
        }

        $fieldKey = (string) ($config['field_key'] ?? $metaKey);
        SourceMap::record(self::describe($config, [
            'store'  => $type,
            'object' => $objectId,
            'key'    => $fieldKey,
            'value'  => $value,
            'admin'  => $admin,
        ] + ($default ? ['default' => true] : [])));

        return $short;
    }

    /**
     * TAW options (OptionsPage fields, with their defaults) and the site title and tagline. pre_option fires for
     * every get_option(), so it catches OptionsPage::get(), bloginfo() and direct reads alike.
     */
    public static function option(mixed $pre, string $option, mixed $default): mixed
    {
        if (self::$reading) {
            return $pre;
        }
        $config = OptionsPage::getFieldRegistry()[$option] ?? null;
        $wp = self::WP_OPTIONS[$option] ?? null;
        if ($config === null && $wp === null) {
            return $pre;
        }

        self::$reading = true;
        try {
            $value = $pre !== false ? $pre : get_option($option, null);
            $fallback = $config !== null ? OptionsPage::defaultOf($option) : null;
            $isDefault = $fallback !== null && Metabox::isUnset($value, $config);
            if ($isDefault) {
                $value = $fallback;
            }
            $admin = $wp !== null
                ? admin_url('options-general.php')
                : admin_url('admin.php?page=' . rawurlencode((string) ($config['option_page'] ?? '')));
        } finally {
            self::$reading = false;
        }

        if ($config !== null) {
            $prefix = (string) ($config['prefix'] ?? '_taw_');
            $read = self::describe($config, [
                'store'  => 'option',
                'object' => (string) ($config['option_page'] ?? ''),
                'key'    => str_starts_with($option, $prefix) ? substr($option, strlen($prefix)) : $option,
                'value'  => $value,
                'admin'  => $admin,
            ] + ($isDefault ? ['default' => true] : []));
        } else {
            $read = ['store' => 'wp', 'object' => 'options', 'key' => $option, 'type' => 'wp', 'label' => (string) $wp, 'value' => $value, 'admin' => $admin];
        }
        SourceMap::record($read);

        return $pre;
    }

    public static function title(string $title, int $postId = 0): string
    {
        if ($postId > 0 && !self::$reading && get_post_type($postId) !== 'nav_menu_item') {
            SourceMap::record([
                'store'  => 'post',
                'object' => $postId,
                'key'    => 'post_title',
                'type'   => 'wp',
                'label'  => 'Title',
                'value'  => $title,
                'admin'  => (string) get_edit_post_link($postId, 'raw'),
            ]);
        }

        return $title;
    }

    /**
     * Menu items as menus read them. An item that links to a post carries that post's id (`target`): when a
     * menu label equals the linked page's title, the menu item is what rendered it.
     *
     * @param mixed $items The menu's items (an array of item objects; anything else is passed through).
     */
    public static function menu(mixed $items, mixed $menu): mixed
    {
        if (!is_array($items) || !is_object($menu)) {
            return $items;
        }
        $menuId = (int) ($menu->term_id ?? 0);
        foreach ($items as $item) {
            if (!is_object($item) || !isset($item->ID)) {
                continue;
            }
            SourceMap::record([
                'store'  => 'menu',
                'object' => $menuId,
                'key'    => 'item-' . $item->ID,
                'type'   => 'wp',
                'label'  => 'Menu item',
                'value'  => (string) ($item->title ?? ''),
                'admin'  => admin_url('nav-menus.php?action=edit&menu=' . $menuId),
            ] + (($item->type ?? '') === 'post_type' ? ['target' => (int) ($item->object_id ?? 0)] : []));
        }

        return $items;
    }

    public static function printManifest(): void
    {
        $label = SourceMap::quietly(static fn (): string => self::pageLabel());
        SourceMap::setRootLabel($label);
        echo "\n" . SourceMap::script(get_stylesheet_directory()) . "\n";
    }

    /** @internal */
    public static function resetForTests(): void
    {
        self::$started = false;
        self::$reading = false;
        self::$fields = [];
        self::$parts = [];
    }

    /**
     * What a person calls this page: "Home", the post's title, the archive's title.
     */
    private static function pageLabel(): string
    {
        self::$reading = true;
        try {
            if (is_front_page()) {
                return 'Home';
            }
            if (is_singular()) {
                return wp_strip_all_tags((string) get_the_title(get_queried_object_id()));
            }
            if (is_home()) {
                return wp_strip_all_tags((string) get_the_title((int) get_option('page_for_posts'))) ?: 'Posts';
            }
            if (is_archive()) {
                return wp_strip_all_tags((string) get_the_archive_title());
            }
            if (is_search()) {
                return 'Search';
            }
            if (is_404()) {
                return 'Not found';
            }

            return '';
        } finally {
            self::$reading = false;
        }
    }

    /**
     * The TAW field a meta key belongs to on this object, or null for any other meta.
     *
     * @return array<string, mixed>|null
     */
    private static function fieldConfig(string $type, int $objectId, string $metaKey): ?array
    {
        $subtype = match ($type) {
            'post' => (string) get_post_type($objectId),
            'term' => (string) (get_term($objectId)->taxonomy ?? ''),
            default => '',
        };
        $cacheKey = $type . ':' . $subtype;
        self::$fields[$cacheKey] ??= Metabox::fieldsFor($type, $subtype);

        return self::$fields[$cacheKey][$metaKey] ?? null;
    }

    /**
     * Adds what the page script needs from a field's config: its label and type, the labels of a repeater's
     * sub-fields, and an image's file name (any size or format variant of it then matches).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $read
     * @return array<string, mixed>
     */
    private static function describe(array $config, array $read): array
    {
        $read['type'] = (string) ($config['type'] ?? 'text');
        $read['label'] = (string) ($config['label'] ?? $read['key']);
        if ($read['type'] === 'repeater' && is_array($config['fields'] ?? null)) {
            $subs = [];
            foreach ($config['fields'] as $sub) {
                if (is_array($sub) && isset($sub['id'])) {
                    $subs[(string) $sub['id']] = ['label' => (string) ($sub['label'] ?? $sub['id']), 'type' => (string) ($sub['type'] ?? 'text')];
                }
            }
            $read['subs'] = $subs;
        }
        if ($read['type'] === 'image' && is_numeric($read['value']) && (int) $read['value'] > 0) {
            $file = SourceMap::quietly(static fn (): string => (string) get_attached_file((int) $read['value']));
            if ($file !== '') {
                $read['stem'] = preg_replace('/(-scaled)?\.[a-z0-9]+$/i', '', basename($file));
            }
        }

        return $read;
    }

    private static function themeRelative(string $file): string
    {
        $real = realpath($file) ?: $file;
        foreach ([get_stylesheet_directory(), get_template_directory()] as $dir) {
            $root = rtrim(realpath($dir) ?: $dir, '/') . '/';
            if (str_starts_with($real, $root)) {
                return substr($real, strlen($root));
            }
        }

        return basename($real);
    }
}
