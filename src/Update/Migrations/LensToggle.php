<?php

declare(strict_types=1);

namespace TAW\Update\Migrations;

use TAW\Update\MigrationResult;

/**
 * taw/core 1.101.0: TAW Lens (umbrella ADR-0006) loads only when the theme's "Enable Lens" toggle is on, a
 * checkbox beside "Enable CSS Studio" on the TAW Settings page's Developer Tools tab (`inc/options.php`).
 *
 * inc/options.php belongs to the site, so this edits it only where it still has the scaffold's shape: one
 * `css_studio_enabled` field line (the toggle goes right after it) and, when the page has tabs, one
 * Developer Tools tab line listing its fields. Anything else gets a manual step and no write. The result
 * must parse as PHP, or nothing is written. A theme without CSS Studio's toggle (no Developer Tools) has
 * nothing to do.
 */
final class LensToggle implements \TAW\Update\Migration
{
    public const FILE = 'inc/options.php';

    public function id(): string
    {
        return '1.101.0/lens-toggle';
    }

    public function title(): string
    {
        return 'Add the "Enable Lens" toggle to TAW Settings → Developer Tools';
    }

    public function themeKind(): string
    {
        return 'classic';
    }

    public function explain(): string
    {
        return <<<'TXT'
            What: adds an "Enable Lens" checkbox (option lens_enabled) to the TAW Settings options page in
            inc/options.php, right after "Enable CSS Studio", and adds it to the Developer Tools tab. Lens
            loads only while that box is ticked, the Vite dev server runs, and the user can edit the theme.
            The box starts unticked, so nothing changes on the site until someone ticks it.

            Why: TAW Lens (point at anything on the page and tell Claude what to change) ships with taw/core,
            but each site decides whether it runs, like CSS Studio.

            By hand: in inc/options.php, add this field after the css_studio_enabled one:
                ['id' => 'lens_enabled', 'label' => __('Enable Lens', 'taw-theme'), 'type' => 'checkbox'],
            and add 'lens_enabled' to the Developer Tools tab's fields list. Use the theme's own text domain.

            Undo: remove the field and its entry in the tab. Lens then never loads (an unset option is off).
            TXT;
    }

    public function pending(string $themeDir): bool
    {
        $code = self::read($themeDir);

        return $code !== null && str_contains($code, "'css_studio_enabled'") && !str_contains($code, "'lens_enabled'");
    }

    public function run(string $themeDir): MigrationResult
    {
        $code = self::read($themeDir);
        if ($code === null || !$this->pending($themeDir)) {
            return new MigrationResult();
        }
        $manual = [self::FILE . " doesn't have the scaffold's shape, so the Enable Lens toggle wasn't added. By hand: taw upgrade --explain " . $this->id()];

        $updated = self::withToggle($code);
        if ($updated === null) {
            return new MigrationResult([], $manual);
        }
        try {
            \PhpToken::tokenize($updated, TOKEN_PARSE);
        } catch (\ParseError) {
            return new MigrationResult([], $manual);
        }
        if (file_put_contents($themeDir . '/' . self::FILE, $updated) === false) {
            return new MigrationResult([], [self::FILE . " couldn't be written. By hand: taw upgrade --explain " . $this->id()]);
        }

        return new MigrationResult([self::FILE]);
    }

    /**
     * The file with the toggle added, or null when its shape isn't the scaffold's.
     * @internal Public for tests.
     */
    public static function withToggle(string $code): ?string
    {
        // The field: one line `['id' => 'css_studio_enabled', … ],`; the toggle copies its indent and text domain.
        $field = '/^([ \t]*)\[\s*\'id\'\s*=>\s*\'css_studio_enabled\'.*\],[ \t]*$/m';
        if (preg_match_all($field, $code, $found) !== 1) {
            return null;
        }
        $line = $found[0][0];
        $domain = preg_match("/__\\(\\s*'[^']*'\\s*,\\s*'([a-z0-9_-]+)'\\s*\\)/i", $line, $m) === 1 ? $m[1] : 'taw-theme';
        $toggle = $found[1][0] . "['id' => 'lens_enabled', 'label' => __('Enable Lens', '" . $domain . "'), 'type' => 'checkbox'],";
        $code = str_replace($line, $line . "\n" . $toggle, $code);

        if (!str_contains($code, "'tabs'")) {
            return $code;
        }
        // The tab: one line with 'devtools' and a fields list holding css_studio_enabled.
        $tab = '/(\'id\'\s*=>\s*\'devtools\'[^\n]*\'fields\'\s*=>\s*\[)([^\]\n]*\'css_studio_enabled\'[^\]\n]*)(\])/';
        if (preg_match_all($tab, $code) !== 1) {
            return null;
        }

        return preg_replace_callback($tab, static fn (array $m): string => $m[1] . rtrim($m[2], " ,") . ", 'lens_enabled'" . $m[3], $code);
    }

    private static function read(string $themeDir): ?string
    {
        $path = $themeDir . '/' . self::FILE;
        if (!is_file($path)) {
            return null;
        }
        $code = file_get_contents($path);

        return $code === false ? null : $code;
    }
}
