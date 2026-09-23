<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Registry;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Turns an EditingPolicy definition (plus post types' own "editing" rules
 * and the TAW_EDITING_PRESET constant) into the effective Policy.
 *
 * Resolution order, lowest first (ADR-0005 § 3):
 *
 *   1. preset          — the constant if it's valid, else the definition's
 *                        preset, else Presets::DEFAULT_LEVEL;
 *   2. layer level     — a layer set to a level name, or {"level": …};
 *   3. individual keys — the rest of a layer's object;
 *   4. post type rule  — PostType::editing() / JSON "editing" (content only);
 *   5. site content map — layers.content.<post type> in the policy.
 *
 * Pure: no WordPress calls, so the same answer comes out of bin/taw, unit
 * tests and a live request.
 */
final class Resolver
{
    public const DEFAULT_BYPASS_CAPABILITY = 'taw_unlock_editing';

    /**
     * @param array<string, string|array<string, mixed>> $postTypeRules post type => its own editing rule.
     * @param mixed $presetOverride The TAW_EDITING_PRESET value, or null when it isn't defined.
     */
    public static function resolve(?EditingPolicy $definition, array $postTypeRules = [], mixed $presetOverride = null): Policy
    {
        $warnings = [];
        [$preset, $source] = [Presets::DEFAULT_LEVEL, 'default'];

        if ($definition?->presetLevel() !== null && in_array($definition->presetLevel(), Presets::LEVELS, true)) {
            [$preset, $source] = [$definition->presetLevel(), 'definition'];
        }

        if ($presetOverride !== null) {
            if (is_string($presetOverride) && in_array($presetOverride, Presets::LEVELS, true)) {
                [$preset, $source] = [$presetOverride, 'constant'];
            } else {
                $warnings[] = sprintf(
                    'TAW_EDITING_PRESET must be one of %s; ignoring %s.',
                    implode(', ', Presets::LEVELS),
                    is_string($presetOverride) ? '"' . $presetOverride . '"' : gettype($presetOverride)
                );
            }
        }

        $layers = $definition?->layers() ?? [];

        return new Policy(
            $preset,
            $source,
            $definition?->bypassCapability() ?? self::DEFAULT_BYPASS_CAPABILITY,
            self::booleanLayer('site', $preset, $layers['site'] ?? null),
            self::booleanLayer('design', $preset, $layers['design'] ?? null),
            self::booleanLayer('features', $preset, $layers['features'] ?? null),
            self::contentRules($preset, $postTypeRules, $layers['content'] ?? null),
            $warnings,
        );
    }

    /**
     * The policy for this request, from the frozen schema registry and
     * wp-config.php constants.
     */
    public static function fromRegistry(Registry $registry): Policy
    {
        $postTypeRules = [];
        foreach ($registry->postTypes() as $postType) {
            if ($postType->editingRule() !== null) {
                $postTypeRules[$postType->key()] = $postType->editingRule();
            }
        }

        return self::resolve(
            $registry->editing(),
            $postTypeRules,
            defined('TAW_EDITING_PRESET') ? constant('TAW_EDITING_PRESET') : null
        );
    }

    /**
     * @return array<string, bool>
     */
    private static function booleanLayer(string $layer, string $preset, mixed $value): array
    {
        return self::apply(Presets::layer($layer, $preset), $layer, $value);
    }

    /**
     * @param array<string, string|array<string, mixed>> $postTypeRules
     * @return array<string, array<string, mixed>>
     */
    private static function contentRules(string $preset, array $postTypeRules, mixed $siteContent): array
    {
        // A level for the whole content layer applies to the default targets.
        if (is_string($siteContent)) {
            $siteContent = array_fill_keys(Presets::DEFAULT_TARGETS, $siteContent);
        }
        $siteContent = is_array($siteContent) ? $siteContent : [];

        $postTypes = array_values(array_unique([
            ...Presets::DEFAULT_TARGETS,
            ...array_map('strval', array_keys($postTypeRules)),
            ...array_map('strval', array_keys($siteContent)),
        ]));

        $rules = [];
        foreach ($postTypes as $postType) {
            $level = in_array($postType, Presets::DEFAULT_TARGETS, true) ? $preset : 'open';
            $rule  = Presets::layer('content', $level);
            $rule  = self::apply($rule, 'content', $postTypeRules[$postType] ?? null);
            $rules[$postType] = self::apply($rule, 'content', $siteContent[$postType] ?? null);
        }

        return $rules;
    }

    /**
     * Apply one override on top of $base: a level name replaces the layer's
     * settings with that level's; an object starts from its own "level" (if
     * any) and replaces the keys it names. Anything else changes nothing
     * (invalid values never get this far: Rules rejects them on add()).
     *
     * @template T of array<string, mixed>
     * @param T $base
     * @return T
     */
    private static function apply(array $base, string $layer, mixed $value): array
    {
        if (is_string($value) && in_array($value, Presets::LEVELS, true)) {
            /** @var T */
            return Presets::layer($layer, $value);
        }

        if (!is_array($value)) {
            return $base;
        }

        if (isset($value['level']) && is_string($value['level']) && in_array($value['level'], Presets::LEVELS, true)) {
            $base = Presets::layer($layer, $value['level']);
        }
        unset($value['level']);

        /** @var T */
        return array_replace($base, array_intersect_key($value, $base));
    }
}
