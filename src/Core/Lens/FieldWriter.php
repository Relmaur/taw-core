<?php

declare(strict_types=1);

namespace TAW\Core\Lens;

use TAW\Core\Log\Logger;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Metabox\Store\MetaStore;
use TAW\Core\Metabox\Store\PostMetaStore;
use TAW\Core\Metabox\Store\TermMetaStore;
use TAW\Core\Metabox\Store\UserMetaStore;
use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Editing a field in place (umbrella ADR-0006, Lens build step 5): `POST taw/v1/lens/field` writes one text or
 * rich-text field, or one repeater row's sub-field, from the page.
 *
 * The page names the field the way the source map read it (store, object, key, and a row and sub-field) and
 * sends `expect`, the value it showed. The write goes through the same primitives as `taw fields:set` and an
 * admin save (Metabox::writeTo(), OptionsPage::writeOption()), so it is sanitized exactly the same way.
 *
 * - Only fields the source map marks editable: text, textarea and wysiwyg, never a `'sanitize' => 'code'` field.
 * - The developer needs the object's own capability (edit_post, edit_term, edit_user, manage_options for options),
 *   on top of the Lens gate the route already checks.
 * - `expect` must still be what is stored (or the default the page showed): if anyone changed the field since the
 *   page loaded, nothing is written and the answer says so (409).
 * - The answer carries `previous`, so the page can undo: `null` means nothing was stored (the page showed the
 *   field's default), and writing `null` back removes the value again.
 */
final class FieldWriter
{
    /** Field types the page can edit as text. */
    public const EDITABLE = ['text', 'textarea', 'wysiwyg'];

    /** Values longer than this aren't something one edit on the page typed. */
    public const VALUE_MAX = 100000;

    /**
     * How a field is edited on the page: `text` (plain), `rich` (wysiwyg, with its formats), or null.
     *
     * @param array<string, mixed> $config
     */
    public static function editMode(array $config): ?string
    {
        $type = (string) ($config['type'] ?? 'text');
        if (!in_array($type, self::EDITABLE, true) || ($config['sanitize'] ?? '') === 'code') {
            return null;
        }

        return $type === 'wysiwyg' ? 'rich' : 'text';
    }

    /**
     * The formats a rich-text field allows on the page: bold, italic and links always (the classic editor's
     * teeny toolbar has them), lists when the field's blocks include them, the same rule as the data panel's
     * mini editor (`blocks`, else `teeny`, else the default set).
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function formats(array $config): array
    {
        $formats = ['bold', 'italic', 'link'];
        $blocks = is_array($config['blocks'] ?? null) && $config['blocks'] !== [] ? $config['blocks'] : null;
        if ($blocks === null || in_array('core/list', $blocks, true)) {
            $formats[] = 'lists';
        }

        return $formats;
    }

    /**
     * @param array<string, mixed> $body The request's JSON body.
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function write(array $body): array
    {
        $store = $body['store'] ?? null;
        $key = $body['key'] ?? null;
        $object = $body['object'] ?? null;
        $row = $body['row'] ?? null;
        $sub = $body['sub'] ?? null;
        $value = $body['value'] ?? null;
        $expect = $body['expect'] ?? null;

        if (
            !in_array($store, ['post', 'term', 'user', 'option'], true)
            || !is_string($key) || $key === ''
            || (!is_int($object) && !is_string($object))
            || ($row !== null && (!is_int($row) || $row < 0))
            || ($row !== null) !== (is_string($sub) && $sub !== '')
            || ($value !== null && (!is_string($value) || strlen($value) > self::VALUE_MAX))
            || ($value === null && $row !== null)
            || ($expect !== null && !is_string($expect))
        ) {
            return self::fail(422, 'invalid', __('Lens sent something it shouldn’t have. Reload the page and try again.', 'taw-core'));
        }

        $target = self::resolve($store, $object, $key);
        if (isset($target['status'])) {
            return $target;
        }
        ['config' => $config, 'meta' => $meta, 'id' => $id, 'name' => $name] = $target;

        $fieldConfig = $config;
        if ($row !== null) {
            $fieldConfig = self::subConfig($config, (string) $sub);
            if ($fieldConfig === null) {
                return self::fail(404, 'unknown', __('That field isn’t on this page any more. Reload the page.', 'taw-core'));
            }
        }
        if (self::editMode($fieldConfig) === null) {
            return self::fail(422, 'not-editable', __('This field can’t be edited on the page. Open it in wp-admin.', 'taw-core'));
        }

        $before = self::shown($store, $meta, $id, $config);
        $current = $row !== null ? self::cell($before['value'], $row, (string) $sub) : self::text($before['value']);
        if ($row !== null && $current === null) {
            return self::fail(409, 'changed', __('That row isn’t there any more. Reload the page to edit what’s stored now.', 'taw-core'));
        }
        if ($expect !== null && $current !== $expect) {
            return self::fail(409, 'changed', __('This field changed since the page loaded. Reload the page to edit what’s stored now.', 'taw-core'), ['current' => $current]);
        }

        if ($value === null) {
            // Undoing a save over a default: nothing was stored, so nothing is stored again.
            if ($store === 'option') {
                delete_option($name);
            } else {
                $meta->delete($id, $name);
            }
        } elseif ($row !== null) {
            $rows = self::rows($before['value']);
            $rows[$row][(string) $sub] = $value;
            self::store($store, $meta, $id, $config, $rows);
        } else {
            self::store($store, $meta, $id, $config, $value);
        }

        $after = self::shown($store, $meta, $id, $config);
        $saved = $row !== null ? self::cell($after['value'], $row, (string) $sub) : self::text($after['value']);

        Logger::info('lens.field_saved', 'Lens saved a field edited on the page.', [
            'store'  => $store,
            'object' => $object,
            'field'  => $row !== null ? sprintf('%s[%d].%s', $key, $row, (string) $sub) : $key,
            'removed' => $value === null,
        ]);

        return ['status' => 200, 'body' => [
            'saved'    => $saved ?? '',
            'default'  => $after['default'],
            'previous' => $row === null && $before['default'] ? null : $current,
        ]];
    }

    /**
     * The field's config and where it's stored, or the failure to answer with.
     *
     * @return array{config: array<string, mixed>, meta: MetaStore|null, id: int, name: string}|array{status: int, body: array<string, mixed>}
     */
    private static function resolve(string $store, int|string $object, string $key): array
    {
        if ($store === 'option') {
            if (!current_user_can('manage_options')) {
                return self::fail(403, 'forbidden', __('You can’t change this site’s options.', 'taw-core'));
            }
            foreach (OptionsPage::getFieldRegistry() as $name => $config) {
                if (($config['id'] ?? null) === $key && (string) ($config['option_page'] ?? '') === (string) $object) {
                    return ['config' => $config, 'meta' => null, 'id' => 0, 'name' => (string) $name];
                }
            }

            return self::fail(404, 'unknown', __('That field isn’t on this page any more. Reload the page.', 'taw-core'));
        }

        $id = (int) $object;
        [$meta, $subtype, $capability] = match ($store) {
            'post' => [new PostMetaStore(), $id > 0 ? (string) get_post_type($id) : '', 'edit_post'],
            'term' => [new TermMetaStore(), $id > 0 ? (string) (get_term($id)->taxonomy ?? '') : '', 'edit_term'],
            default => [new UserMetaStore(), '', 'edit_user'],
        };
        if ($id <= 0 || ($store !== 'user' && $subtype === '') || ($store === 'user' && get_userdata($id) === false)) {
            return self::fail(404, 'unknown', __('What this field is stored on isn’t there any more. Reload the page.', 'taw-core'));
        }
        if (!current_user_can($capability, $id)) {
            return self::fail(403, 'forbidden', __('You can’t edit what this field is stored on.', 'taw-core'));
        }

        $matches = Metabox::fieldMatches($store, $subtype, $key);
        if (count($matches) !== 1) {
            return $matches === []
                ? self::fail(404, 'unknown', __('That field isn’t on this page any more. Reload the page.', 'taw-core'))
                : self::fail(409, 'ambiguous', __('Two fields share this name here. Edit it in wp-admin.', 'taw-core'));
        }

        return ['config' => $matches[0], 'meta' => $meta, 'id' => $id, 'name' => Metabox::metaKeyOf($matches[0])];
    }

    /**
     * What the page shows for the field: the stored value, or the field's default when nothing is stored (the
     * same rule the source map records with).
     *
     * @param array<string, mixed> $config
     * @return array{value: mixed, default: bool}
     */
    private static function shown(string $store, ?MetaStore $meta, int $id, array $config): array
    {
        if ($store === 'option') {
            $name = (string) ($config['prefix'] ?? '_taw_') . (string) ($config['id'] ?? '');
            $value = get_option($name, null);
            $fallback = OptionsPage::defaultOf($name);
            if ($fallback !== null && Metabox::isUnset($value, $config)) {
                return ['value' => $fallback, 'default' => true];
            }

            return ['value' => $value, 'default' => false];
        }

        $key = Metabox::metaKeyOf($config);
        $raw = get_metadata_raw($store, $id, $key, true);
        if ($store !== 'post') {
            return ['value' => $raw ?? '', 'default' => false];
        }
        $shown = Metabox::withDefault($id, $key, $raw);

        return ['value' => $shown ?? '', 'default' => $shown !== $raw];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function store(string $store, ?MetaStore $meta, int $id, array $config, mixed $value): void
    {
        if ($store === 'option') {
            OptionsPage::writeOption($config, $value);
        } elseif ($meta !== null) {
            Metabox::writeTo($meta, $id, $config, $value);
        }
    }

    /**
     * A repeater's sub-field config.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private static function subConfig(array $config, string $sub): ?array
    {
        if (($config['type'] ?? '') !== 'repeater' || !is_array($config['fields'] ?? null)) {
            return null;
        }
        foreach ($config['fields'] as $field) {
            if (is_array($field) && ($field['id'] ?? null) === $sub) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        $rows = is_string($value) ? json_decode($value, true) : $value;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private static function cell(mixed $value, int $row, string $sub): ?string
    {
        $cell = self::rows($value)[$row][$sub] ?? null;

        return is_string($cell) || is_int($cell) || is_float($cell) ? (string) $cell : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function fail(int $status, string $code, string $message, array $extra = []): array
    {
        return ['status' => $status, 'body' => ['error' => $code, 'message' => $message] + $extra];
    }
}
