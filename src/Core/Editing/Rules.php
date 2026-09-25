<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Validation for editing-policy data, shared by the PHP builders
 * (EditingPolicy::problems(), PostType::problems()) and the JSON Validator,
 * so both accept exactly the same thing.
 *
 * Every error is "<json pointer>: <message>", like Schema\Validator's.
 */
final class Rules
{
    /** Keys of an editing policy besides the common definition keys. */
    public const POLICY_KEYS = ['preset', 'bypass', 'themeBlocks', 'layers'];

    /**
     * Keys that are planned but not built yet. They're rejected with a
     * pointer to the plan instead of "unknown key", so nobody thinks they're
     * a typo.
     */
    private const RESERVED = [
        'allowBound' => 'is reserved for Block Bindings (data layer Phase 4) and not available yet',
    ];

    /** A block name or a glob of them: "core/*", "core/paragraph", "*". */
    private const BLOCK_GLOB = '/^(\*|[a-z0-9-]+\/(\*|[a-z0-9-]+))$/';

    private const BLOCK_NAME = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

    private const POST_TYPE = '/^[a-z0-9_-]{1,20}$/';

    private const CAPABILITY = '/^[a-z0-9_]+$/';

    /**
     * @param array<string, mixed> $policy preset / bypass / layers.
     * @return list<string>
     */
    public static function validatePolicy(array $policy): array
    {
        $errors = [];

        if (array_key_exists('preset', $policy) && !self::isLevel($policy['preset'])) {
            $errors[] = '/preset: ' . self::levelMessage();
        }

        if (array_key_exists('bypass', $policy)) {
            $errors = [...$errors, ...self::validateBypass($policy['bypass'])];
        }

        if (array_key_exists('themeBlocks', $policy)) {
            $errors = [...$errors, ...($policy['themeBlocks'] === null
                ? ['/themeBlocks: must be a list of block names or globs (e.g. "acme/*")']
                : self::validateAllow($policy['themeBlocks'], '/themeBlocks'))];
        }

        if (array_key_exists('layers', $policy)) {
            $errors = [...$errors, ...self::validateLayers($policy['layers'])];
        }

        return $errors;
    }

    /**
     * A content rule: a level name, or an object of overrides.
     *
     * @return list<string>
     */
    public static function validateContentRule(mixed $rule, string $at): array
    {
        if (is_string($rule)) {
            return self::isLevel($rule) ? [] : ["{$at}: " . self::levelMessage()];
        }

        if (!self::isObject($rule)) {
            return ["{$at}: must be a level name or an object"];
        }

        $errors = self::validateObjectKeys($rule, $at, ['level', ...Presets::CONTENT_KEYS]);

        if (array_key_exists('level', $rule) && !self::isLevel($rule['level'])) {
            $errors[] = "{$at}/level: " . self::levelMessage();
        }

        if (array_key_exists('allow', $rule)) {
            $errors = [...$errors, ...self::validateAllow($rule['allow'], "{$at}/allow")];
        }

        if (array_key_exists('template', $rule)) {
            $errors = [...$errors, ...self::validateTemplate($rule['template'], "{$at}/template")];
        }

        if (array_key_exists('lock', $rule) && $rule['lock'] !== false && !in_array($rule['lock'], Presets::LOCKS, true)) {
            $errors[] = "{$at}/lock: must be false or one of " . implode(', ', Presets::LOCKS);
        }

        if (array_key_exists('newPostsOnly', $rule) && !is_bool($rule['newPostsOnly'])) {
            $errors[] = "{$at}/newPostsOnly: must be true or false";
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateBypass(mixed $bypass): array
    {
        if (!self::isObject($bypass)) {
            return ['/bypass: must be an object'];
        }

        $errors = self::validateObjectKeys($bypass, '/bypass', ['capability']);

        if (array_key_exists('capability', $bypass)
            && (!is_string($bypass['capability']) || preg_match(self::CAPABILITY, $bypass['capability']) !== 1)
        ) {
            $errors[] = '/bypass/capability: must be a capability name (lowercase letters, numbers, underscores)';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateLayers(mixed $layers): array
    {
        if (!self::isObject($layers)) {
            return ['/layers: must be an object'];
        }

        $errors = [];
        foreach ($layers as $layer => $value) {
            $at = "/layers/{$layer}";

            if (!in_array($layer, Presets::LAYERS, true)) {
                $errors[] = "{$at}: unknown layer (allowed: " . implode(', ', Presets::LAYERS) . ')';
                continue;
            }

            $errors = [...$errors, ...($layer === 'content'
                ? self::validateContentLayer($value, $at)
                : self::validateBooleanLayer((string) $layer, $value, $at))];
        }

        return $errors;
    }

    /**
     * The content layer: a level for the default post types, or a map of
     * post type → content rule.
     *
     * @return list<string>
     */
    private static function validateContentLayer(mixed $value, string $at): array
    {
        if (is_string($value)) {
            return self::isLevel($value) ? [] : ["{$at}: " . self::levelMessage()];
        }

        if (!self::isObject($value)) {
            return ["{$at}: must be a level name or an object of post type → rule"];
        }

        $errors = [];
        foreach ($value as $postType => $rule) {
            if (preg_match(self::POST_TYPE, (string) $postType) !== 1) {
                $errors[] = "{$at}/{$postType}: not a valid post type key";
                continue;
            }
            $errors = [...$errors, ...self::validateContentRule($rule, "{$at}/{$postType}")];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateBooleanLayer(string $layer, mixed $value, string $at): array
    {
        if (is_string($value)) {
            return self::isLevel($value) ? [] : ["{$at}: " . self::levelMessage()];
        }

        if (!self::isObject($value)) {
            return ["{$at}: must be a level name or an object of settings"];
        }

        $errors = self::validateObjectKeys($value, $at, ['level', ...Presets::SETTINGS[$layer]]);

        foreach ($value as $key => $setting) {
            if ($key === 'level') {
                if (!self::isLevel($setting)) {
                    $errors[] = "{$at}/level: " . self::levelMessage();
                }
            } elseif (in_array($key, Presets::SETTINGS[$layer], true) && !is_bool($setting)) {
                $errors[] = "{$at}/{$key}: must be true (allowed) or false (locked)";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateAllow(mixed $allow, string $at): array
    {
        if ($allow === null) {
            return [];
        }

        if (!is_array($allow) || !array_is_list($allow)) {
            return ["{$at}: must be a list of block names or globs (e.g. \"core/*\"), or null for every block"];
        }

        $errors = [];
        foreach ($allow as $i => $pattern) {
            if (!is_string($pattern) || preg_match(self::BLOCK_GLOB, $pattern) !== 1) {
                $errors[] = "{$at}/{$i}: must be a block name or glob such as \"core/paragraph\" or \"core/*\"";
            }
        }

        return $errors;
    }

    /**
     * A block template: a list of [name, attributes?, innerBlocks?] entries,
     * the format register_post_type()'s "template" argument uses.
     *
     * @return list<string>
     */
    private static function validateTemplate(mixed $template, string $at): array
    {
        if ($template === null) {
            return [];
        }

        if (!is_array($template) || !array_is_list($template)) {
            return ["{$at}: must be a list of [\"block/name\", {attributes}, [inner blocks]] entries"];
        }

        $errors = [];
        foreach ($template as $i => $entry) {
            $entryAt = "{$at}/{$i}";

            if (!is_array($entry) || !array_is_list($entry) || $entry === [] || count($entry) > 3) {
                $errors[] = "{$entryAt}: must be [\"block/name\", {attributes}, [inner blocks]]";
                continue;
            }
            if (!is_string($entry[0]) || preg_match(self::BLOCK_NAME, $entry[0]) !== 1) {
                $errors[] = "{$entryAt}/0: must be a block name such as \"core/heading\"";
            }
            if (isset($entry[1]) && !self::isObject($entry[1])) {
                $errors[] = "{$entryAt}/1: block attributes must be an object";
            }
            if (isset($entry[2])) {
                $errors = [...$errors, ...self::validateTemplate($entry[2], "{$entryAt}/2")];
            }
        }

        return $errors;
    }

    /**
     * Unknown and reserved keys of an object.
     *
     * @param array<array-key, mixed> $object
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function validateObjectKeys(array $object, string $at, array $allowed): array
    {
        $errors = [];
        foreach (array_keys($object) as $key) {
            if (isset(self::RESERVED[$key])) {
                $errors[] = "{$at}/{$key}: " . self::RESERVED[$key];
            } elseif (!in_array($key, $allowed, true)) {
                $errors[] = "{$at}/{$key}: unknown key (allowed: " . implode(', ', $allowed) . ')';
            }
        }

        return $errors;
    }

    private static function isLevel(mixed $value): bool
    {
        return is_string($value) && in_array($value, Presets::LEVELS, true);
    }

    private static function levelMessage(): string
    {
        return 'must be one of ' . implode(', ', Presets::LEVELS);
    }

    /**
     * A decoded JSON object (see Schema\Validator::isObject()).
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
