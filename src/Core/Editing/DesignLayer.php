<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class definition (see Editing\Presets).

/**
 * The design layer (ADR-0005 § 6): which custom (non-preset) values the
 * design tools allow. Applied to the theme's theme.json data, so it's
 * site-wide and applies to everyone, bypass users included. theme.json is
 * global and cached, and per-user variants would fight that cache.
 *
 * Every locked setting is written as an explicit false: "appearanceTools"
 * expands into individual flags when theme.json loads, so switching it off
 * later wouldn't undo them (the ml-theme applyDesignSettings lesson).
 */
final class DesignLayer
{
    /**
     * Design setting → the theme.json settings paths it switches off.
     */
    public const THEME_JSON = [
        'customColors'     => [['color', 'custom']],
        'customGradients'  => [['color', 'customGradient']],
        'customFontSizes'  => [['typography', 'customFontSize']],
        'dropCap'          => [['typography', 'dropCap']],
        'customSpacing'    => [['spacing', 'customSpacingSize']],
        'customLineHeight' => [['typography', 'lineHeight']],
        'border'           => [['border', 'color'], ['border', 'radius'], ['border', 'style'], ['border', 'width']],
        'shadow'           => [['shadow', 'defaultPresets']],
        'duotone'          => [['color', 'customDuotone']],
    ];

    public function __construct(private readonly Policy $policy)
    {
    }

    public function register(): void
    {
        if ($this->settings() === []) {
            return;
        }

        add_filter('wp_theme_json_data_theme', [$this, 'filterThemeJson']);

        // Something may have resolved theme.json before init:7; drop that
        // copy so the next read goes through the filter.
        if (function_exists('wp_clean_theme_json_cache')) {
            wp_clean_theme_json_cache();
        }
    }

    /**
     * wp_theme_json_data_theme.
     */
    public function filterThemeJson(mixed $themeJson): mixed
    {
        if (!is_object($themeJson) || !method_exists($themeJson, 'update_with')) {
            return $themeJson;
        }

        return $themeJson->update_with(['version' => 3, 'settings' => $this->settings()]);
    }

    /**
     * The theme.json "settings" overrides for the locked design settings.
     *
     * @return array<string, array<string, bool>>
     */
    public function settings(): array
    {
        $settings = [];

        foreach (self::THEME_JSON as $setting => $paths) {
            if ($this->policy->design[$setting] ?? true) {
                continue;
            }
            foreach ($paths as [$section, $key]) {
                $settings[$section][$key] = false;
            }
        }

        return $settings;
    }
}
