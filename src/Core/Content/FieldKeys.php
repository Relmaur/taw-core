<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No ABSPATH guard: pure class, autoloaded by the content:* CLI commands
// before WordPress boots (see FieldCodec).

/**
 * How a record's `fields` keys map to meta keys in a content snapshot
 * (interchange format 1.2, ADR-0008).
 *
 * - A field with the default `_taw_` prefix is keyed by its bare id
 *   (`hero_heading`), exactly as in format 1.0 and 1.1.
 * - A field with any other prefix is keyed by its full meta key
 *   (`_book_author`), so it can't be mistaken for a `_taw_` field.
 *
 * `$registered` is {@see \TAW\Core\Metabox\Metabox::fieldsFor()} for the
 * record's post type (meta key → config). `$bareConfig` looks up the bare
 * registry, for `_taw_` values whose field isn't registered for that post
 * type (as the exporter always did).
 *
 * Pure: no WordPress calls, so the key rules are unit-testable.
 */
final class FieldKeys
{
    public const DEFAULT_PREFIX = '_taw_';

    /**
     * The snapshot key of a registered field.
     *
     * @param array<string, mixed> $config A config from `fieldsFor()`.
     */
    public static function keyOf(array $config): string
    {
        return ($config['prefix'] ?? self::DEFAULT_PREFIX) === self::DEFAULT_PREFIX
            ? (string) $config['field_key']
            : (string) $config['meta_key'];
    }

    /**
     * Export side: the snapshot key and config for a stored meta key, or
     * null when the meta key isn't a TAW field.
     *
     * @param array<string, array<string, mixed>> $registered
     * @param callable(string): (array<string, mixed>|null) $bareConfig
     * @return array{key: string, config: array<string, mixed>}|null
     */
    public static function forMetaKey(string $metaKey, array $registered, callable $bareConfig): ?array
    {
        if (isset($registered[$metaKey])) {
            return ['key' => self::keyOf($registered[$metaKey]), 'config' => $registered[$metaKey]];
        }

        if (!str_starts_with($metaKey, self::DEFAULT_PREFIX)) {
            return null;
        }

        $fieldId = substr($metaKey, strlen(self::DEFAULT_PREFIX));

        return ['key' => $fieldId, 'config' => self::fallbackConfig($fieldId, $bareConfig)];
    }

    /**
     * Import side: the meta key and config a snapshot key writes to.
     * Accepts every format: a 1.0/1.1 bare id resolves as it always did.
     *
     * @param array<string, array<string, mixed>> $registered
     * @param callable(string): (array<string, mixed>|null) $bareConfig
     * @return array{meta_key: string, config: array<string, mixed>}
     */
    public static function forKey(string $key, array $registered, callable $bareConfig): array
    {
        $match = null;
        foreach ($registered as $config) {
            if (self::keyOf($config) !== $key) {
                continue;
            }
            // A `_taw_` field keyed by its bare id wins over another prefix's
            // meta key that happens to spell the same (see forMetaKey()).
            if ($match === null || ($config['prefix'] ?? self::DEFAULT_PREFIX) === self::DEFAULT_PREFIX) {
                $match = $config;
            }
        }

        if ($match !== null) {
            return ['meta_key' => (string) $match['meta_key'], 'config' => $match];
        }

        $config = self::fallbackConfig($key, $bareConfig);

        return ['meta_key' => (string) $config['meta_key'], 'config' => $config];
    }

    /**
     * A `_taw_` value with no field registered for the post type: the bare
     * registry's config when it's a `_taw_` field, else plain text.
     *
     * @param callable(string): (array<string, mixed>|null) $bareConfig
     * @return array<string, mixed>
     */
    private static function fallbackConfig(string $fieldId, callable $bareConfig): array
    {
        $config = $bareConfig($fieldId);

        if (!is_array($config) || ($config['prefix'] ?? self::DEFAULT_PREFIX) !== self::DEFAULT_PREFIX) {
            $config = ['type' => 'text', 'id' => $fieldId];
        }

        return array_merge($config, [
            'prefix'    => self::DEFAULT_PREFIX,
            'field_key' => $fieldId,
            'meta_key'  => self::DEFAULT_PREFIX . $fieldId,
        ]);
    }
}
