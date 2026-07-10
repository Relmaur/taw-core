<?php

declare(strict_types=1);

namespace TAW\CLI;

/**
 * Shared helper for CLI commands that need WordPress fully booted
 * (InspectCommand, FieldsGetCommand, FieldsSetCommand). Locates
 * wp-load.php by walking up from the theme directory — the theme is
 * always at wp-content/themes/<theme>, so this is normally 3 levels up,
 * with a few extra levels of headroom for unusual layouts.
 */
final class WpLoader
{
    public static function locate(string $themeDir): ?string
    {
        $dir = $themeDir;

        for ($i = 0; $i < 6; $i++) {
            $dir = dirname($dir);
            $candidate = $dir . '/wp-load.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
