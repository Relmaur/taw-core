<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * A classic theme's build/analysis configs (vite.config.js, phpstan.neon).
 *
 * The base ships with taw/core (`classicTheme()` in resources/vite/taw-vite.mjs,
 * resources/phpstan/classic.neon) and updates with it; the theme keeps a short
 * file of its own (marker `taw:config`) that loads the base and adds the
 * site's settings. Updates never change a marked file.
 *
 * Converting an existing theme replaces a file only when it is byte-identical
 * to a version the taw-theme scaffold once shipped (resources/configs/known.json,
 * every version in its git history): an unedited copy. A file the site edited
 * is left alone and reported as `custom`, with the by-hand steps — never
 * guessed at (on the live fleet, 2026-10-09: two sites' phpstan.neon and one
 * site's vite.config.js were their own).
 *
 * Pure filesystem work, no WordPress (pre-boot: no ABSPATH guard).
 */
final class ConfigFiles
{
    public const MARKER = 'taw:config';

    /** @var array<string, string> theme path => template (resources/configs/classic/…) */
    public const FILES = [
        'vite.config.js' => 'vite.config.js',
        'phpstan.neon' => 'phpstan.neon',
    ];

    /** What to do by hand with a file the site edited, per path. */
    public const BY_HAND = [
        'vite.config.js' => "Replace it with vendor/taw/core/resources/configs/classic/vite.config.js, then put this site's own settings in the object passed to mergeConfig (compare with git: what differs from classicTheme() in vendor/taw/core/resources/vite/taw-vite.mjs is the site's).",
        'phpstan.neon' => "Replace it with vendor/taw/core/resources/configs/classic/phpstan.neon, then add this site's own lines (scanFiles, ignoreErrors, extra paths) under parameters: they merge with vendor/taw/core/resources/phpstan/classic.neon.",
    ];

    public function __construct(private string $themeDir)
    {
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/resources/configs';
    }

    /**
     * @return array{convert: list<string>, custom: list<string>, current: list<string>}
     */
    public function plan(): array
    {
        $known = self::known();
        $plan = ['convert' => [], 'custom' => [], 'current' => []];
        foreach (array_keys(self::FILES) as $path) {
            $file = $this->themeDir . '/' . $path;
            if (!is_file($file)) {
                continue; // a theme without it (no Vite, no phpstan) gets none
            }
            $contents = (string) file_get_contents($file);
            if (str_contains($contents, self::MARKER)) {
                $plan['current'][] = $path;
            } elseif (in_array(hash('sha256', $contents), $known[$path] ?? [], true)) {
                $plan['convert'][] = $path;
            } else {
                $plan['custom'][] = $path;
            }
        }

        return $plan;
    }

    /**
     * Replaces the plan's unedited copies with the short versions.
     *
     * @param array{convert: list<string>, custom: list<string>, current: list<string>} $plan
     * @return list<string> the paths written
     */
    public function apply(array $plan): array
    {
        foreach ($plan['convert'] as $path) {
            copy(self::root() . '/classic/' . self::FILES[$path], $this->themeDir . '/' . $path);
        }

        return $plan['convert'];
    }

    /** @return array<string, list<string>> */
    private static function known(): array
    {
        $data = json_decode((string) file_get_contents(self::root() . '/known.json'), true);

        return is_array($data['classic'] ?? null) ? $data['classic'] : [];
    }
}
