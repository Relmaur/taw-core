<?php

declare(strict_types=1);

namespace TAW\Core\Loop;

use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the TAW Loop's editor offers (ADR-0014), sent as `window.tawBindings.loop`:
 * the fields a loop can read (repeaters with their sub-fields, related-post
 * fields, image fields) per place, and the public post types and taxonomies
 * a query or terms loop can use. Fields that opt out (`bindings: false`) and
 * user fields that don't opt in are left out, as the server would read nothing.
 */
final class EditorData
{
    /** Field type → the source that loops over it. */
    public const SOURCE_TYPES = ['repeater' => 'repeater', 'post_select' => 'related', 'files' => 'images', 'image' => 'images'];

    /**
     * @return array{sources: array{post: array<string, list<array<string, mixed>>>, option: list<array<string, mixed>>, term: list<array<string, mixed>>, user: list<array<string, mixed>>}, postTypes: list<array{name: string, label: string}>, taxonomies: list<array{name: string, label: string, postTypes: list<string>}>}
     */
    public static function all(): array
    {
        $post = [];
        foreach (Metabox::postTypesWithMetabox() as $type) {
            $post[$type] = self::sources(Metabox::fieldsFor('post', $type), 'post');
        }

        $term = [];
        foreach (get_taxonomies(['public' => true]) as $taxonomy) {
            array_push($term, ...self::sources(Metabox::fieldsFor('term', (string) $taxonomy), 'term'));
        }

        return [
            'sources'    => [
                'post'   => $post,
                'option' => self::sources(OptionsPage::getFieldRegistry(), 'option'),
                'term'   => self::unique($term),
                'user'   => self::sources(Metabox::fieldsFor('user'), 'user'),
            ],
            'postTypes'  => self::postTypes(),
            'taxonomies' => self::taxonomies(),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     * @return list<array<string, mixed>>
     */
    private static function sources(array $configs, string $from): array
    {
        $sources = [];
        foreach ($configs as $config) {
            $type = (string) ($config['type'] ?? '');
            if (!isset(self::SOURCE_TYPES[$type]) || isset($config['parent_group']) || !self::shown($config, $from)) {
                continue;
            }
            $source = [
                'field'    => (string) ($config['id'] ?? ''),
                'from'     => $from,
                'type'     => self::SOURCE_TYPES[$type],
                'label'    => (string) ($config['label'] ?? $config['id'] ?? ''),
                'fieldset' => (string) ($config['option_page_title'] ?? ''),
            ];
            if ($type === 'repeater') {
                $source['subs'] = self::subs(is_array($config['fields'] ?? null) ? $config['fields'] : []);
            }
            if ($type === 'post_select' && is_string($config['post_type'] ?? null)) {
                $source['postType'] = $config['post_type'];
            }
            if ($source['field'] !== '') {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * A repeater's sub-fields, nested repeaters included (a loop inside the item).
     *
     * @param array<int, mixed> $fields
     * @return list<array<string, mixed>>
     */
    private static function subs(array $fields): array
    {
        $subs = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['id']) || ($field['bindings'] ?? null) === false) {
                continue;
            }
            $sub = ['id' => (string) $field['id'], 'label' => (string) ($field['label'] ?? $field['id']), 'type' => (string) ($field['type'] ?? 'text')];
            if ($sub['type'] === 'repeater') {
                $sub['subs'] = self::subs(is_array($field['fields'] ?? null) ? $field['fields'] : []);
            }
            $subs[] = $sub;
        }

        return $subs;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function shown(array $config, string $from): bool
    {
        $flag = $config['bindings'] ?? null;

        return $from === 'user' ? $flag === true : $flag !== false;
    }

    /**
     * @param list<array<string, mixed>> $sources
     * @return list<array<string, mixed>>
     */
    private static function unique(array $sources): array
    {
        $seen = [];

        return array_values(array_filter($sources, static function (array $source) use (&$seen): bool {
            $key = $source['field'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
    }

    /** @return list<array{name: string, label: string}> */
    private static function postTypes(): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'objects') as $name => $object) {
            if ($name !== 'attachment' && is_post_type_viewable((string) $name)) {
                $types[] = ['name' => (string) $name, 'label' => (string) ($object->labels->singular_name ?? $name)];
            }
        }

        return $types;
    }

    /** @return list<array{name: string, label: string, postTypes: list<string>}> */
    private static function taxonomies(): array
    {
        $taxonomies = [];
        foreach (get_taxonomies(['public' => true], 'objects') as $name => $object) {
            if ($name === 'post_format') {
                continue;
            }
            $taxonomies[] = [
                'name'      => (string) $name,
                'label'     => (string) ($object->labels->name ?? $name),
                'postTypes' => array_values(array_map('strval', $object->object_type)),
            ];
        }

        return $taxonomies;
    }
}
