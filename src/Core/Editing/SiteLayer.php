<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class definition (see Editing\Presets).

/**
 * The site-structure layer (ADR-0005 + addendum): what a locked user can
 * change in the Site Editor.
 *
 *   templates      → /wp/v2/templates         (wp_template)
 *   templateParts  → /wp/v2/template-parts    (wp_template_part)
 *   globalStyles   → /wp/v2/global-styles     (wp_global_styles)
 *   navigation     → /wp/v2/navigation        (wp_navigation)
 *   templateMode   → the post editor's "edit template" (supportsTemplateMode)
 *   siteEditor     → Appearance → Editor / Patterns and site-editor.php
 *
 * Writes are refused at REST dispatch: every Site Editor save goes through
 * REST (rest_pre_dispatch also runs for each request in a batch), and the
 * REST controllers check edit_theme_options directly, so re-mapping post
 * type capabilities wouldn't stop them. Reads are never blocked: the post
 * editor needs global styles and templates to render.
 */
final class SiteLayer
{
    /** Setting → REST route prefix. */
    public const ROUTES = [
        'templates'     => '/wp/v2/templates',
        'templateParts' => '/wp/v2/template-parts',
        'globalStyles'  => '/wp/v2/global-styles',
        'navigation'    => '/wp/v2/navigation',
    ];

    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly Policy $policy,
        private readonly Bypass $bypass,
    ) {
    }

    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'guardRest'], 10, 3);
        add_filter('block_editor_settings_all', [$this, 'editorSettings'], 20, 2);
        add_action('admin_menu', [$this, 'hideSiteEditor'], 999);
        add_action('load-site-editor.php', [$this, 'refuseSiteEditor']);
    }

    /**
     * rest_pre_dispatch: refuse writes to a locked area.
     */
    public function guardRest(mixed $result, mixed $server, mixed $request): mixed
    {
        if ($result !== null || !$request instanceof \WP_REST_Request) {
            return $result;
        }
        if (in_array(strtoupper($request->get_method()), self::READ_METHODS, true)) {
            return $result;
        }

        $area = self::areaOf($request->get_route());
        if ($area === null || $this->allowed($area) || $this->bypass->active()) {
            return $result;
        }

        return new \WP_Error(
            'taw_editing_site_locked',
            __('The site\'s editing policy doesn\'t allow changing this part of the site design.', 'taw-core'),
            ['status' => 403, 'area' => $area]
        );
    }

    /**
     * The setting a REST route belongs to, or null for any other route.
     */
    public static function areaOf(string $route): ?string
    {
        foreach (self::ROUTES as $area => $prefix) {
            if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
                return $area;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function editorSettings(array $settings, mixed $context = null): array
    {
        if (!$this->allowed('templateMode') && !$this->bypass->active()) {
            $settings['supportsTemplateMode'] = false;
        }

        return $settings;
    }

    public function hideSiteEditor(): void
    {
        if ($this->allowed('siteEditor') || $this->bypass->active()) {
            return;
        }

        remove_submenu_page('themes.php', 'site-editor.php');
        remove_submenu_page('themes.php', 'site-editor.php?p=/pattern');
    }

    public function refuseSiteEditor(): void
    {
        if ($this->allowed('siteEditor') || $this->bypass->active()) {
            return;
        }

        wp_die(
            esc_html__('The site\'s editing policy doesn\'t allow using the Site Editor.', 'taw-core'),
            esc_html__('Site Editor locked', 'taw-core'),
            ['response' => 403, 'back_link' => true]
        );
    }

    private function allowed(string $setting): bool
    {
        return $this->policy->site[$setting] ?? true;
    }
}
