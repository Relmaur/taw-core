<?php

declare(strict_types=1);

namespace TAW\Core\Lens;

use TAW\Core\Assets\Vite;
use TAW\Core\I18n\Translations;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Helpers\Framework;
use TAW\Support\ViteLoader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TAW Lens (umbrella ADR-0006, taw-core ADR-0020): the dev-only page script that lets a developer point at
 * anything on a classic TAW site and tell Claude what to change.
 *
 * The gate, all of it, on the server: Lens loads only on the frontend, only while the theme's Vite dev
 * server is running (the same check as CSS Studio, ViteLoader::isDevServerRunning()), only when the theme's
 * "Enable Lens" toggle is on (TAW Settings → Developer Tools, option `lens_enabled`), and only for a
 * logged-in user who can edit the theme (`edit_theme_options`). Nothing is printed otherwise: Lens never
 * reaches production, and the page is byte-identical with Lens off.
 *
 * Wired by Theme::boot() (classic themes), never by Boot::data(): it injects frontend output.
 */
final class Lens
{
    /** The theme's toggle: a checkbox field on the TAW Settings options page (taw-theme inc/options.php). */
    public const OPTION_FIELD = 'lens_enabled';

    /** Who may load Lens: the people who may change the theme. */
    public const CAPABILITY = 'edit_theme_options';

    public const SCRIPT_HANDLE = 'taw-lens';

    public const SCRIPT_SOURCE = 'src/index.tsx';

    /** WordPress scripts the page script uses: translations only. */
    public const SCRIPT_DEPS = ['wp-i18n'];

    private static bool $registered = false;

    private static ?Vite $vite = null;

    /** @var (\Closure(): bool)|null */
    private static ?\Closure $devServer = null;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * Whether Lens loads on this request. Cheapest check first: the toggle (an autoloaded option), then the
     * user, then the dev server (an HTTP probe, cached for the request).
     */
    public static function active(): bool
    {
        if (is_admin() || wp_doing_ajax() || is_customize_preview()) {
            return false;
        }
        if (!(bool) OptionsPage::get(self::OPTION_FIELD)) {
            return false;
        }
        if (!is_user_logged_in() || !current_user_can(self::CAPABILITY)) {
            return false;
        }

        return self::$devServer !== null ? (self::$devServer)() : ViteLoader::isDevServerRunning();
    }

    /** wp_enqueue_scripts: the page script, with its config and translations, under the gate. */
    public static function enqueue(): void
    {
        if (!self::active()) {
            return;
        }
        if (!self::vite()->script(self::SCRIPT_HANDLE, self::SCRIPT_SOURCE, self::SCRIPT_DEPS)) {
            return;
        }

        $before = 'window.tawLens = ' . wp_json_encode(self::config()) . ';';
        $localeData = Translations::scriptLocaleData();
        if ($localeData !== null) {
            $before .= sprintf(' wp.i18n.setLocaleData(%s, %s);', wp_json_encode($localeData), wp_json_encode(Translations::DOMAIN));
        }
        wp_add_inline_script(self::SCRIPT_HANDLE, $before, 'before');
    }

    /**
     * What the page script needs to know about the site. No secrets: the bridge token arrives with the chat
     * (build step 4), and the page itself is only ever served to this logged-in developer.
     *
     * @return array{host: string, version: string}
     */
    public static function config(): array
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $port = wp_parse_url(home_url(), PHP_URL_PORT);

        return [
            'host'    => is_string($host) ? $host . (is_int($port) ? ':' . $port : '') : '',
            'version' => Framework::version(),
        ];
    }

    /** @internal Tests swap in an adapter on a temp build. */
    public static function useVite(?Vite $vite): void
    {
        self::$vite = $vite;
    }

    /**
     * @internal Tests stand in for the dev-server probe.
     * @param (\Closure(): bool)|null $check
     */
    public static function useDevServerCheck(?\Closure $check): void
    {
        self::$devServer = $check;
    }

    /** @internal */
    public static function resetForTests(): void
    {
        self::$registered = false;
        self::$vite = null;
        self::$devServer = null;
    }

    private static function vite(): Vite
    {
        return self::$vite ??= new Vite(Framework::path(), Framework::url(), 'assets/lens');
    }
}
