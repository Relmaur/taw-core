<?php

declare(strict_types=1);

namespace TAW\Core\Bindings;

use TAW\Core\Metabox\Metabox;
use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The fields the block editor offers in the Attributes panel for `taw/field`
 * (ADR-0010 decision 8): what `getFieldsList` returns, built once per editor
 * load and inlined as `window.tawBindings.fields`.
 *
 * Each entry: `{label, args: {field, from, sub?}, type: "string"|"number",
 * fieldType, fieldset}`; `fieldset` is the metabox or options page title,
 * which the TAW data popup groups by (ADR-0012).
 * WordPress only offers an entry for attributes of the same type, so images
 * (and single post selects, for their featured image) get a second, numeric
 * entry for core/image's `id`. The same bindable rules as the resolver
 * apply: no unbindable types, no `bindings: false`, users only with
 * `bindings: true`.
 */
final class EditorFields
{
    /**
     * @return array{post: array<string, list<array<string, mixed>>>, option: list<array<string, mixed>>, term: list<array<string, mixed>>, user: list<array<string, mixed>>}
     */
    public static function all(): array
    {
        $post = [];
        foreach (Metabox::postTypesWithMetabox() as $type) {
            $post[$type] = self::fromConfigs(Metabox::fieldsFor('post', $type), 'post');
        }

        $term = [];
        foreach (self::taxonomies() as $taxonomy) {
            array_push($term, ...self::fromConfigs(Metabox::fieldsFor('term', $taxonomy), 'term'));
        }

        return [
            'post'   => $post,
            'option' => self::options(),
            'term'   => self::unique($term),
            'user'   => self::fromConfigs(Metabox::fieldsFor('user'), 'user'),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $configs Keyed by meta key.
     * @return list<array<string, mixed>>
     */
    private static function fromConfigs(array $configs, string $from): array
    {
        $entries = [];
        foreach ($configs as $config) {
            $group = isset($config['parent_group']) && is_string($config['parent_group']) ? $config['parent_group'] : null;
            $sub   = $group !== null ? (string) ($config['id'] ?? '') : null;
            $field = $group ?? (string) ($config['id'] ?? '');
            if ($field === '') {
                continue;
            }
            array_push($entries, ...self::entriesFor($config, $field, $sub, $from, $group !== null ? self::groupLabel($config, $group) : null, self::boxTitle($config)));
        }

        return $entries;
    }

    /**
     * The title of the metabox a field belongs to.
     *
     * @param array<string, mixed> $config A registry entry.
     */
    private static function boxTitle(array $config): string
    {
        $boxId = strstr((string) ($config['qualified_id'] ?? ''), '.', true);
        foreach (Metabox::instances() as $box) {
            if ($box->id() === $boxId) {
                return $box->title();
            }
        }

        return '';
    }

    /**
     * The label of the group a sub-field belongs to, from its metabox.
     *
     * @param array<string, mixed> $sub A group sub-field's registry entry.
     */
    private static function groupLabel(array $sub, string $group): string
    {
        $boxId = strstr((string) ($sub['qualified_id'] ?? ''), '.', true);
        foreach (Metabox::instances() as $box) {
            if ($boxId !== false && $box->id() !== $boxId) {
                continue;
            }
            foreach ($box->fields() as $field) {
                if (($field['id'] ?? null) === $group && ($field['type'] ?? '') === 'group') {
                    return (string) ($field['label'] ?? $group);
                }
            }
        }

        return $group;
    }

    /** @return list<array<string, mixed>> */
    private static function options(): array
    {
        $groups  = OptionsPage::getGroupRegistry();
        $entries = [];

        foreach (OptionsPage::getFieldRegistry() as $name => $config) {
            foreach (array_keys($groups) as $groupName) {
                if (str_starts_with((string) $name, $groupName . '_')) {
                    continue 2; // A group's sub-field: listed below, with `sub`.
                }
            }
            array_push($entries, ...self::entriesFor($config, (string) ($config['id'] ?? ''), null, 'option', null, (string) ($config['option_page_title'] ?? '')));
        }

        foreach ($groups as $group) {
            foreach (is_array($group['fields'] ?? null) ? $group['fields'] : [] as $sub) {
                if (is_array($sub)) {
                    $sub += ['bindings' => $group['bindings'] ?? null];
                    array_push($entries, ...self::entriesFor($sub, (string) $group['id'], (string) ($sub['id'] ?? ''), 'option', (string) ($group['label'] ?? $group['id']), (string) ($group['option_page_title'] ?? '')));
                }
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private static function entriesFor(array $config, string $field, ?string $sub, string $from, ?string $groupLabel, string $fieldset = ''): array
    {
        $type = (string) ($config['type'] ?? 'text');
        $flag = $config['bindings'] ?? null;
        if (
            $field === ''
            || in_array($type, AttributeMap::UNBINDABLE, true)
            || ($type === 'post_select' && !empty($config['multiple']))
            || ($from === 'user' ? $flag !== true : $flag === false)
        ) {
            return [];
        }

        $label = (string) ($config['label'] ?? $config['id'] ?? $field);
        if ($groupLabel !== null) {
            $label = $groupLabel . ' › ' . $label;
        }
        $args = array_filter(['field' => $field, 'from' => $from, 'sub' => $sub], static fn ($v): bool => $v !== null && $v !== '');

        $entries = [['label' => $label, 'args' => $args, 'type' => 'string', 'fieldType' => $type, 'fieldset' => $fieldset]];
        if (in_array($type, ['image', 'post_select'], true)) {
            /* translators: %s: a field label. The numeric entry binds core/image's attachment ID. */
            $entries[] = ['label' => sprintf(__('%s (image ID)', 'taw-core'), $label), 'args' => $args, 'type' => 'number', 'fieldType' => $type, 'fieldset' => $fieldset];
        }

        return $entries;
    }

    /** @return list<string> */
    private static function taxonomies(): array
    {
        $taxonomies = [];
        foreach (Metabox::instances() as $box) {
            foreach (Metabox::screensToTaxonomies($box->screens()) as $taxonomy) {
                $taxonomies[$taxonomy] = true;
            }
        }

        return array_keys($taxonomies);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function unique(array $entries): array
    {
        $seen = [];

        return array_values(array_filter($entries, static function (array $entry) use (&$seen): bool {
            $key = wp_json_encode([$entry['args'], $entry['type']]);

            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
    }
}
