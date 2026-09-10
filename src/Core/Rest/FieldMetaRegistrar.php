<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Content\FieldCodec;
use TAW\Core\Metabox\Metabox;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exposes every registered TAW field over the REST API.
 *
 * For each Metabox field, on every post type its metabox attaches to:
 *   - scalar fields  → `register_post_meta()` with `show_in_rest`, the
 *     field's own sanitizer, and an `auth_callback` gated on
 *     `edit_post` for that specific post.
 *   - structured fields (repeater / files / post_select — stored as a JSON
 *     string) → the raw meta is registered as a string *and* a
 *     `register_rest_field()` computed field `taw_<id>` exposes the decoded
 *     object/array shape (and re-encodes on write), so the physical storage
 *     `Metabox::get_repeater()` expects stays intact.
 *
 * This is what unblocks headless front-ends and external integrations
 * reading/writing TAW content over `wp/v2`. It does **not** add any editing
 * UI — classic metaboxes stay desktop-only; on-phone editing is the Visual
 * Editor's job.
 *
 * Opt out entirely with `add_filter('taw_register_meta_in_rest', '__return_false')`.
 */
final class FieldMetaRegistrar
{
    private const SCALAR_REST_TYPE = [
        'text' => 'string', 'textarea' => 'string', 'url' => 'string', 'select' => 'string',
        'color' => 'string', 'datepicker' => 'string', 'icon' => 'string', 'wysiwyg' => 'string',
        'number' => 'number', 'range' => 'number',
        'checkbox' => 'boolean',
        'image' => 'integer',
    ];

    public static function register(): void
    {
        if (!apply_filters('taw_register_meta_in_rest', true)) {
            return;
        }

        add_action('init', [self::class, 'registerPostMeta'], 20);
    }

    public static function registerPostMeta(): void
    {
        foreach (Metabox::getFieldRegistry() as $fieldId => $config) {
            $type = $config['type'] ?? 'text';

            // Group parents own no meta of their own — the compound sub-field
            // entries ('<group>_<sub>') carry the real types and are handled
            // as their own registry entries.
            if ($type === 'group') {
                continue;
            }

            $postTypes = self::resolvePostTypes(is_array($config['screens'] ?? null) ? $config['screens'] : []);
            if ($postTypes === []) {
                continue;
            }

            $metaKey = (string) ($config['prefix'] ?? '_taw_') . $fieldId;

            foreach ($postTypes as $postType) {
                if (in_array($type, FieldCodec::STRUCTURED_TYPES, true)) {
                    self::registerStructured($postType, $metaKey, (string) $fieldId, $config);
                } else {
                    self::registerScalar($postType, $metaKey, (string) $type, $config);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function registerScalar(string $postType, string $metaKey, string $type, array $config): void
    {
        register_post_meta($postType, $metaKey, [
            'type'              => self::SCALAR_REST_TYPE[$type] ?? 'string',
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => static fn ($value) => Metabox::sanitizeValue($config, $value),
            'auth_callback'     => static fn ($allowed, $meta, $objectId): bool => current_user_can('edit_post', (int) $objectId),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function registerStructured(string $postType, string $metaKey, string $fieldId, array $config): void
    {
        // Raw JSON string, still readable/writable as a string over wp/v2.
        register_post_meta($postType, $metaKey, [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => static fn ($allowed, $meta, $objectId): bool => current_user_can('edit_post', (int) $objectId),
        ]);

        // Decoded object/array shape as a computed field.
        register_rest_field($postType, 'taw_' . $fieldId, [
            'get_callback'    => static function (array $object) use ($metaKey, $config) {
                return FieldCodec::decode($config, get_post_meta((int) $object['id'], $metaKey, true));
            },
            'update_callback' => static function ($value, \WP_Post $object) use ($fieldId, $config): void {
                if (!current_user_can('edit_post', $object->ID)) {
                    return;
                }
                Metabox::writeMeta($object->ID, $config + ['id' => $fieldId], $value);
            },
            'schema'          => self::structuredSchema((string) ($config['type'] ?? 'repeater')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function structuredSchema(string $type): array
    {
        return match ($type) {
            'files'       => ['type' => 'array', 'items' => ['type' => 'integer']],
            'post_select' => ['type' => ['integer', 'array', 'null']],
            default       => ['type' => 'array', 'items' => ['type' => 'object']], // repeater
        };
    }

    /**
     * Resolve a metabox `screens` list (post types / page slugs / template
     * filenames) to concrete post types. Template and slug screens are
     * page-scoped by TAW convention, so they resolve to `page`.
     *
     * @param list<string> $screens
     * @return list<string>
     */
    private static function resolvePostTypes(array $screens): array
    {
        $types = Metabox::screensToPostTypes(array_map('strval', $screens));

        /**
         * Filter: the post types a field's REST meta registers on.
         *
         * @param list<string> $types
         * @param list<string> $screens
         */
        $types = apply_filters('taw_field_meta_post_types', array_values(array_unique($types)), $screens);

        return array_values(array_unique(array_map('strval', $types)));
    }
}
