<?php

declare(strict_types=1);

namespace TAW\Core\I18n;

use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * taw-core's own text domain, `taw-core` (data layer Phase 2, Step 5).
 *
 * - Translations ship in the package, `languages/taw-core-<locale>.l10n.php`
 *   (source `.po` beside it). The path is given to WordPress's textdomain
 *   registry, so the file loads lazily in the request's locale, the way
 *   load_plugin_textdomain() does it.
 * - Before v1.56.0 these strings used the theme's `taw-theme` domain, and
 *   sites translated some of them in their own `languages/`. The theme's
 *   translation wins when it has one, so no site's wording changes; the
 *   bundled translation fills in the rest.
 * - The data panel's JS strings get the same translations through
 *   {@see self::scriptLocaleData()}.
 */
final class Translations
{
    public const DOMAIN = 'taw-core';

    /** The theme domain taw-core's strings used before, asked first. */
    public const FALLBACK_DOMAIN = 'taw-theme';

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        global $wp_textdomain_registry;
        if ($wp_textdomain_registry instanceof \WP_Textdomain_Registry) {
            $wp_textdomain_registry->set_custom_path(self::DOMAIN, Framework::path('languages'));
        }

        add_filter('gettext_' . self::DOMAIN, [self::class, 'gettext'], 10, 2);
        add_filter('gettext_with_context_' . self::DOMAIN, [self::class, 'gettextWithContext'], 10, 3);
        add_filter('ngettext_' . self::DOMAIN, [self::class, 'ngettext'], 10, 4);
        add_filter('ngettext_with_context_' . self::DOMAIN, [self::class, 'ngettextWithContext'], 10, 5);
    }

    /** @internal For tests. */
    public static function reset(): void
    {
        self::$registered = false;
    }

    public static function gettext(string $translation, string $text): string
    {
        $theme = self::fallback()->translate($text);

        return $theme !== $text ? $theme : $translation;
    }

    public static function gettextWithContext(string $translation, string $text, string $context): string
    {
        $theme = self::fallback()->translate($text, $context);

        return $theme !== $text ? $theme : $translation;
    }

    public static function ngettext(string $translation, string $single, string $plural, int|string|float|null $number): string
    {
        return self::plural($translation, $single, $plural, (int) $number, null);
    }

    public static function ngettextWithContext(string $translation, string $single, string $plural, int|string|float|null $number, string $context): string
    {
        return self::plural($translation, $single, $plural, (int) $number, $context);
    }

    private static function plural(string $translation, string $single, string $plural, int $number, ?string $context): string
    {
        $theme = self::fallback()->translate_plural($single, $plural, $number, $context);
        $untranslated = $number === 1 ? $single : $plural;

        return $theme !== $untranslated ? $theme : $translation;
    }

    /**
     * The `taw-core` translations as @wordpress/i18n locale data, for
     * `wp.i18n.setLocaleData(data, 'taw-core')`; null when there are none
     * (English, or a locale taw-core doesn't ship).
     *
     * @return array<string, mixed>|null
     */
    public static function scriptLocaleData(): ?array
    {
        $translations = get_translations_for_domain(self::DOMAIN);
        $entries = $translations->entries ?? [];
        if ($entries === []) {
            return null;
        }

        $data = ['' => ['domain' => self::DOMAIN, 'lang' => determine_locale()]];
        foreach ($entries as $entry) {
            if ($entry->singular === '') {
                continue;
            }
            $key = ($entry->context !== null && $entry->context !== '' ? $entry->context . "\4" : '') . $entry->singular;
            // Singulars go through translate(), so the theme's wording wins here too.
            $data[$key] = $entry->is_plural
                ? array_values($entry->translations)
                : [$entry->context ? translate_with_gettext_context($entry->singular, $entry->context, self::DOMAIN) : translate($entry->singular, self::DOMAIN)];
        }

        return $data;
    }

    /**
     * @return \Translations|\NOOP_Translations (WP_Translations since 6.5, a Translations subclass)
     */
    private static function fallback(): object
    {
        return get_translations_for_domain(self::FALLBACK_DOMAIN);
    }
}
