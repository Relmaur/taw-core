<?php
/**
 * `wp eval-file keys.php <file>`: this site's state with every ID written as
 * the record it points to, one sorted line per value. Two sites hold the same
 * content exactly when their files are equal, whatever their IDs.
 *
 * Read straight from the database (not through the Exporter), so a mistake the
 * exporter and importer share still shows. Keys: a post is `type:path`, media
 * its uploads path at the source (`_taw_interchange_source` for a download),
 * a term `taxonomy:slug`, a user their login. URLs read `{home}` and
 * `{media:<path>}`.
 */

// No strict_types: `wp eval-file` evaluates this file.

use TAW\Core\Content\MediaResolver;
use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

const FID_TYPES = ['page', 'post', 'fid_book', 'wp_block', 'wp_navigation'];
const FID_TAXONOMIES = ['fid_genre', 'category', 'post_tag'];

/** media path at the source, without extension or `-scaled`: `2024/05/hero` */
function fid_media_key(int $id): string
{
    if ($id <= 0 || get_post_type($id) !== 'attachment') {
        return $id > 0 ? "missing-media#{$id}" : '';
    }
    $source = (string) get_post_meta($id, MediaResolver::SOURCE_META, true);
    $path = $source !== '' ? MediaResolver::uploadsPath($source) : (string) get_post_meta($id, '_wp_attached_file', true);

    return (string) preg_replace('/(-scaled)?\.[a-z0-9]+$/i', '', $path);
}

function fid_post_key(int $id): string
{
    $post = $id > 0 ? get_post($id) : null;
    if (!$post instanceof WP_Post) {
        return $id > 0 ? "missing-post#{$id}" : '';
    }
    if ($post->post_type === 'attachment') {
        return 'media:' . fid_media_key($id);
    }
    $path = $post->post_name === '' ? 'draft:' . $post->post_title
        : (is_post_type_hierarchical($post->post_type) ? get_page_uri($post) : $post->post_name);

    return $post->post_type . ':' . $path;
}

function fid_term_key(int $id): string
{
    $term = $id > 0 ? get_term($id) : null;

    return $term instanceof WP_Term ? $term->taxonomy . ':' . $term->slug : ($id > 0 ? "missing-term#{$id}" : '');
}

/** URLs of this site's files and pages, as keys. */
function fid_text(string $text): string
{
    static $replace = null;
    if ($replace === null) {
        $replace = [];
        foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $id) {
            $url = (string) wp_get_attachment_url((int) $id);
            $base = (string) preg_replace('/(-scaled)?\.[a-z0-9]+$/i', '', $url);
            $replace[$base] = '{media:' . fid_media_key((int) $id) . '}';
            $replace[str_replace('/', '\/', $base)] = $replace[$base]; // inside JSON (footnotes)
        }
        uksort($replace, static fn (string $a, string $b): int => strlen($b) <=> strlen($a)); // hero-1 before hero
        $replace[untrailingslashit(home_url())] = '{home}';
        $replace[str_replace('/', '\/', untrailingslashit(home_url()))] = '{home}';
    }
    $text = strtr($text, $replace);

    return (string) preg_replace_callback('/wp-image-(\d+)/', static fn (array $m): string => 'wp-image-{media:' . fid_media_key((int) $m[1]) . '}', $text);
}

/** A field value with its IDs as keys. @param array<string, mixed> $config */
function fid_value(array $config, mixed $raw): mixed
{
    $decode = static fn (mixed $v): mixed => is_string($v) && $v !== '' && in_array($v[0], ['[', '{'], true) ? json_decode($v, true) : $v;
    $ids = static fn (mixed $v): array => array_map('intval', array_values(array_filter((array) $decode($v), 'is_numeric')));

    switch ($config['type'] ?? 'text') {
        case 'image':
            return fid_media_key((int) $raw);
        case 'files':
            return array_map('fid_media_key', $ids($raw));
        case 'post_select':
            return empty($config['multiple']) ? fid_post_key((int) $raw) : array_map('fid_post_key', $ids($raw));
        case 'repeater':
            $rows = [];
            foreach ((array) $decode($raw) as $row) {
                $out = [];
                foreach ($config['fields'] ?? [] as $sub) {
                    if (is_array($row) && array_key_exists($sub['id'], $row)) {
                        $out[$sub['id']] = fid_value($sub, $row[$sub['id']]);
                    }
                }
                $rows[] = $out;
            }
            return $rows;
        default:
            $value = $decode($raw);
            return is_array($value) ? json_decode(fid_text((string) wp_json_encode($value)), true) : fid_text((string) $value);
    }
}

/** Block attributes that hold IDs, as keys; HTML with its URLs as keys. @param list<array<string, mixed>> $blocks */
function fid_blocks(array $blocks): array
{
    foreach ($blocks as &$block) {
        $attrs = $block['attrs'] ?? [];
        $name = (string) $block['blockName'];
        foreach (['id', 'mediaId'] as $attr) {
            if (isset($attrs[$attr]) && is_numeric($attrs[$attr])) {
                $attrs[$attr] = match (true) {
                    in_array($name, ['core/navigation-link', 'core/navigation-submenu'], true) => ($attrs['kind'] ?? '') === 'taxonomy'
                        ? fid_term_key((int) $attrs[$attr]) : fid_post_key((int) $attrs[$attr]),
                    default => 'media:' . fid_media_key((int) $attrs[$attr]),
                };
            }
        }
        foreach (['ref', 'rootPageID'] as $attr) {
            if (isset($attrs[$attr]) && is_numeric($attrs[$attr])) {
                $attrs[$attr] = fid_post_key((int) $attrs[$attr]);
            }
        }
        if (isset($attrs['ids']) && is_array($attrs['ids'])) {
            $attrs['ids'] = array_map(static fn ($id): string => 'media:' . fid_media_key((int) $id), $attrs['ids']);
        }
        if (isset($attrs['query']['include']) && is_array($attrs['query']['include'])) {
            $attrs['query']['include'] = array_map(static fn ($id): string => fid_post_key((int) $id), $attrs['query']['include']);
        }
        foreach ((array) ($attrs['query']['taxQuery'] ?? []) as $taxonomy => $termIds) {
            $attrs['query']['taxQuery'][$taxonomy] = array_map(static fn ($id): string => fid_term_key((int) $id), (array) $termIds);
        }
        $block['attrs'] = json_decode(fid_text((string) wp_json_encode($attrs)), true) ?? []; // url, href, …
        $block['innerContent'] = array_map(static fn ($c) => is_string($c) ? fid_text($c) : $c, $block['innerContent'] ?? []);
        $block['innerHTML'] = fid_text((string) ($block['innerHTML'] ?? ''));
        $block['innerBlocks'] = fid_blocks($block['innerBlocks'] ?? []);
    }

    return $blocks;
}

$lines = [];
$line = static function (string $section, string $key, mixed $value) use (&$lines): void {
    $lines[] = $section . ' ' . $key . ' = ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

// Posts
foreach (get_posts(['post_type' => FID_TYPES, 'post_status' => ['publish', 'draft', 'private', 'pending', 'future'], 'numberposts' => -1]) as $post) {
    $key = fid_post_key($post->ID);
    $line('post', $key, [
        'title'    => $post->post_title,
        'status'   => $post->post_status,
        'parent'   => fid_post_key((int) $post->post_parent),
        'order'    => (int) $post->menu_order,
        'author'   => $post->post_author ? (string) get_the_author_meta('user_login', (int) $post->post_author) : '',
        'excerpt'  => fid_text($post->post_excerpt),
        'comments' => $post->comment_status . '/' . $post->ping_status,
        'featured' => fid_media_key((int) get_post_thumbnail_id($post)),
    ]);
    $line('content', $key, serialize_blocks(fid_blocks(parse_blocks($post->post_content))));
    foreach (FID_TAXONOMIES as $taxonomy) {
        if (is_object_in_taxonomy($post->post_type, $taxonomy)) {
            $terms = array_map(static fn (WP_Term $t): string => $t->slug, wp_get_object_terms($post->ID, $taxonomy));
            sort($terms);
            $line('terms', "{$key} {$taxonomy}", $terms);
        }
    }
    foreach (Metabox::fieldsFor('post', $post->post_type) as $metaKey => $config) {
        if (($config['type'] ?? '') !== 'group' && metadata_exists('post', $post->ID, $metaKey)) {
            $line('field', "{$key} {$metaKey}", fid_value($config, get_post_meta($post->ID, $metaKey, true)));
        }
    }
    if (metadata_exists('post', $post->ID, 'footnotes')) {
        $line('field', "{$key} footnotes", fid_text((string) get_post_meta($post->ID, 'footnotes', true)));
    }
}

// Media (title, alt, caption)
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]) as $media) {
    $line('media', fid_media_key($media->ID), [$media->post_title, $media->post_excerpt, get_post_meta($media->ID, '_wp_attachment_image_alt', true), $media->post_mime_type]);
}

// Terms
foreach (get_terms(['taxonomy' => FID_TAXONOMIES, 'hide_empty' => false]) as $term) {
    $key = $term->taxonomy . ':' . $term->slug;
    $line('term', $key, [$term->name, $term->description, fid_term_key((int) $term->parent)]);
    foreach (Metabox::fieldsFor('term', $term->taxonomy) as $metaKey => $config) {
        if (metadata_exists('term', $term->term_id, $metaKey)) {
            $line('term-field', "{$key} {$metaKey}", fid_value($config, get_term_meta($term->term_id, $metaKey, true)));
        }
    }
}

// Users (not the admin each site installs with)
foreach (get_users(['login__not_in' => ['admin']]) as $user) {
    $line('user', $user->user_login, [$user->user_email, $user->display_name, $user->roles]);
}

// Options, theme mods, menus
foreach (['blogname', 'blogdescription', 'show_on_front', 'permalink_structure'] as $option) {
    $line('option', $option, get_option($option));
}
foreach (['page_on_front', 'page_for_posts'] as $option) {
    $line('option', $option, fid_post_key((int) get_option($option)));
}
$line('option', 'site_icon', fid_media_key((int) get_option('site_icon')));
$sticky = array_map(static fn ($id): string => fid_post_key((int) $id), (array) get_option('sticky_posts', []));
sort($sticky);
$line('option', 'sticky_posts', $sticky);
foreach (OptionsPage::getFieldRegistry() as $id => $config) {
    $name = ($config['prefix'] ?? '_taw_') . $id;
    if (($config['type'] ?? '') !== 'group' && get_option($name, null) !== null) {
        $line('option', $name, fid_value($config, get_option($name)));
    }
}
$line('theme_mod', 'custom_logo', fid_media_key((int) get_theme_mod('custom_logo')));
$locations = [];
foreach ((array) get_theme_mod('nav_menu_locations', []) as $location => $menuId) {
    $menu = wp_get_nav_menu_object((int) $menuId);
    $locations[$location] = $menu ? $menu->slug : "missing-menu#{$menuId}";
}
ksort($locations);
$line('theme_mod', 'nav_menu_locations', $locations);
foreach (wp_get_nav_menus() as $menu) {
    $items = wp_get_nav_menu_items($menu->term_id) ?: [];
    $position = [];
    foreach ($items as $i => $item) {
        $position[(int) $item->ID] = $i;
    }
    foreach ($items as $i => $item) {
        $object = match ($item->type) {
            'post_type' => fid_post_key((int) $item->object_id),
            'taxonomy'  => fid_term_key((int) $item->object_id),
            default     => fid_text((string) $item->url),
        };
        $line('menu', "{$menu->slug} #{$i}", [$item->title, $item->type, $object, $position[(int) $item->menu_item_parent] ?? null]);
    }
}

sort($lines);
/** @var list<string> $args the arguments after the file, set by `wp eval-file` */
file_put_contents($args[0], implode("\n", $lines) . "\n");
printf("keys: %d values\n", count($lines));
