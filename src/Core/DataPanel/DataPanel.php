<?php

declare(strict_types=1);

namespace TAW\Core\DataPanel;

use TAW\Core\Assets\Vite;
use TAW\Core\I18n\Translations;
use TAW\Core\Icons\Lucide;
use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\Registry;
use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The "TAW Data" sidebar's server side (ADR-0007). Off by default.
 *
 * Boot::data() calls register(), which adds one check on init (late, once
 * every Metabox exists: fieldsets compile at init:8, MetaBlocks at init:10).
 * If no fieldset resolves to "panel", that check removes itself and nothing
 * else is ever hooked, so a site that doesn't use the panel is unchanged.
 * Otherwise it hooks:
 *
 *   taw_metabox_ui              panel fieldsets get no metabox in the block editor
 *   block_editor_settings_all   the descriptor, as settings.tawDataPanel
 *   enqueue_block_editor_assets the panel's script (assets/data-panel/, a committed build)
 *   rest_pre_insert_{type}      readonly / required / validate checks (400)
 *   rest_after_insert_{type}    clear values whose conditions aren't met
 *
 * Only in the block editor: the classic editor keeps its metaboxes.
 */
final class DataPanel
{
    public const CHECK_PRIORITY = 999;

    public const SETTINGS_KEY = 'tawDataPanel';

    public const ERROR_CODE = 'taw_data_invalid';

    public const SCRIPT_HANDLE = 'taw-data-panel';

    /** Source entry in resources/data-panel/ (its manifest key). */
    public const SCRIPT_SOURCE = 'src/index.tsx';

    /** WordPress scripts the panel imports (see resources/data-panel/src). */
    public const SCRIPT_DEPS = [
        'react', 'wp-plugins', 'wp-editor', 'wp-data', 'wp-components', 'wp-element', 'wp-i18n',
        // Media, post search, icons and the wysiwyg mini block editor.
        'wp-block-editor', 'wp-blocks', 'wp-core-data', 'wp-api-fetch', 'wp-autop', 'wp-html-entities',
    ];

    private static ?Vite $vite = null;

    private static bool $registered = false;

    /** @var list<Metabox>|null */
    private static ?array $panel = null;

    /** @var list<string> Resolver warnings (e.g. a bad TAW_DATA_UI), for the editor. */
    private static array $warnings = [];

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('init', [self::class, 'check'], self::CHECK_PRIORITY);
    }

    /**
     * init:999 — decide once whether the panel is in use.
     */
    public static function check(): void
    {
        remove_action('init', [self::class, 'check'], self::CHECK_PRIORITY);

        $panel = self::panelMetaboxes();
        if ($panel === []) {
            return;
        }

        add_filter('taw_metabox_ui', [self::class, 'placement'], 10, 3);
        add_filter('block_editor_settings_all', [self::class, 'editorSettings'], 10, 2);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueAssets']);

        $postTypes = [];
        foreach ($panel as $box) {
            foreach (Metabox::screensToPostTypes($box->screens()) as $postType) {
                $postTypes[$postType] = true;
            }
        }
        foreach (array_keys($postTypes) as $postType) {
            add_filter("rest_pre_insert_{$postType}", [self::class, 'validateRest'], 10, 2);
            add_action("rest_after_insert_{$postType}", [self::class, 'clearHidden'], 10, 3);
        }
    }

    /**
     * The metaboxes shown in the panel: they resolve to "panel" and every one
     * of their field types is supported.
     *
     * @return list<Metabox>
     */
    public static function panelMetaboxes(): array
    {
        if (self::$panel !== null) {
            return self::$panel;
        }

        $constant    = defined('TAW_DATA_UI') ? constant('TAW_DATA_UI') : null;
        $siteDefault = Registry::instance()->settings()?->fieldsetUiValue();

        $panel    = [];
        $warnings = [];
        foreach (Metabox::instances() as $box) {
            $resolved = Ui::resolve($box->ui(), $constant, $siteDefault);
            if ($resolved['warning'] !== null) {
                $warnings[$resolved['warning']] = true;
            }
            if ($resolved['ui'] === Ui::PANEL && Descriptor::supports($box)) {
                $panel[] = $box;
            }
        }

        self::$warnings = array_keys($warnings);

        return self::$panel = $panel;
    }

    /**
     * taw_metabox_ui: panel fieldsets skip add_meta_box() in the block editor.
     */
    public static function placement(string $ui, Metabox $box, \WP_Post $post): string
    {
        if (in_array($box, self::panelMetaboxes(), true) && use_block_editor_for_post($post)) {
            return Ui::PANEL;
        }

        return $ui;
    }

    /**
     * block_editor_settings_all: the descriptor for the post being edited.
     * Fieldsets scoped to a template are sent with `templates` and `active`,
     * so the panel can follow a template change without a reload.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function editorSettings(array $settings, mixed $context): array
    {
        $post = is_object($context) && isset($context->post) && $context->post instanceof \WP_Post ? $context->post : null;
        if ($post === null || !use_block_editor_for_post($post)) {
            return $settings;
        }

        $fieldsets = self::fieldsetsFor($post);
        if ($fieldsets !== []) {
            $settings[self::SETTINGS_KEY] = [
                'version'   => 1,
                'postType'  => $post->post_type,
                'fieldsets' => $fieldsets,
                'warnings'  => self::$warnings,
                'icons'     => Lucide::isEnabled(),
            ];
        }

        return $settings;
    }

    /**
     * The descriptors for a post: fieldsets that apply now, plus
     * template-scoped ones (inactive) so a template change can show them.
     *
     * @return list<array<string, mixed>>
     */
    public static function fieldsetsFor(\WP_Post $post): array
    {
        $fieldsets = [];
        foreach (self::panelMetaboxes() as $box) {
            $applies = $box->appliesTo($post);
            if (!$applies && $box->templateScreens() === []) {
                continue;
            }
            $descriptor = Descriptor::fieldset($box);
            if ($descriptor !== null) {
                // active: applies now. always: applies whatever the template
                // (so the panel can re-check only the template when it changes).
                $fieldsets[] = $descriptor + ['active' => $applies, 'always' => $box->appliesTo($post, false)];
            }
        }

        return $fieldsets;
    }

    /**
     * enqueue_block_editor_assets: the panel, in the post editor, for a post
     * with panel fieldsets. Built into assets/data-panel/ (committed), or
     * served by `npm run dev` in resources/data-panel/ while it runs.
     */
    public static function enqueueAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $post   = get_post();
        if ($screen === null || $screen->base !== 'post' || !$post instanceof \WP_Post || !use_block_editor_for_post($post)) {
            return;
        }
        if (self::fieldsetsFor($post) === []) {
            return;
        }

        self::vite()->script(self::SCRIPT_HANDLE, self::SCRIPT_SOURCE, self::SCRIPT_DEPS);

        $localeData = Translations::scriptLocaleData();
        if ($localeData !== null) {
            wp_add_inline_script(
                self::SCRIPT_HANDLE,
                sprintf('wp.i18n.setLocaleData(%s, %s);', wp_json_encode($localeData), wp_json_encode(Translations::DOMAIN)),
                'before'
            );
        }
    }

    /** @internal Tests swap in an adapter on a temp build. */
    public static function useVite(?Vite $vite): void
    {
        self::$vite = $vite;
    }

    /**
     * The adapter for taw-core's committed build (assets/data-panel), shared
     * by the panel and the Block Bindings editor script.
     */
    public static function assets(): Vite
    {
        return self::vite();
    }

    private static function vite(): Vite
    {
        return self::$vite ??= new Vite(Framework::path(), Framework::url(), 'assets/data-panel');
    }

    /**
     * rest_pre_insert_{post_type}: refuse a save that breaks a panel
     * fieldset's rules. Autosaves are never refused.
     */
    public static function validateRest(mixed $prepared, mixed $request): mixed
    {
        if (!$request instanceof \WP_REST_Request || is_wp_error($prepared) || self::isAutosave($request)) {
            return $prepared;
        }

        $post = self::postFor($request, $prepared);
        if ($post === null) {
            return $prepared;
        }

        $errors = [];
        $drop   = [];
        foreach (self::applicable($post) as $box) {
            $result = Validation::check($box, self::requestMeta($request), self::requestFields($request), self::storedReader($post->ID, $box->prefix()));
            $errors = [...$errors, ...$result['errors']];
            $drop   = [...$drop, ...$result['drop']];
        }

        if ($errors === []) {
            self::leaveOut($request, $drop);
            return $prepared;
        }

        return new \WP_Error(
            self::ERROR_CODE,
            implode(' ', array_column($errors, 'message')),
            ['status' => 400, 'fields' => $errors]
        );
    }

    /**
     * Take read-only values the request resent unchanged out of it, so the
     * REST controller doesn't write them (the request object is shared with
     * the controller, which updates meta and fields after this filter).
     *
     * @param list<array{meta: string}|array{field: string}> $bindings
     */
    private static function leaveOut(\WP_REST_Request $request, array $bindings): void
    {
        if ($bindings === []) {
            return;
        }
        $meta = self::requestMeta($request);
        foreach ($bindings as $binding) {
            if (isset($binding['meta'])) {
                unset($meta[$binding['meta']]);
            } else {
                $request->set_param($binding['field'], null);
            }
        }
        if (is_array($request['meta'] ?? null)) {
            $request->set_param('meta', $meta);
        }
    }

    /**
     * rest_after_insert_{post_type}: delete values of fields whose conditions
     * aren't met, as Metabox::save() does.
     */
    public static function clearHidden(mixed $post, mixed $request, mixed $creating = false): void
    {
        if (!$post instanceof \WP_Post || !$request instanceof \WP_REST_Request || self::isAutosave($request)) {
            return;
        }

        foreach (self::applicable($post) as $box) {
            $result = Validation::check($box, [], [], self::storedReader($post->ID, $box->prefix()));
            foreach ($result['clear'] as $metaKey) {
                delete_post_meta($post->ID, $metaKey);
            }
        }
    }

    /** @internal Tests only. */
    public static function resetForTests(): void
    {
        self::$registered = false;
        self::$vite       = null;
        self::$panel      = null;
        self::$warnings   = [];
    }

    /**
     * @return list<Metabox>
     */
    private static function applicable(\WP_Post $post): array
    {
        return array_values(array_filter(
            self::panelMetaboxes(),
            static fn (Metabox $box): bool => $box->appliesTo($post)
        ));
    }

    private static function isAutosave(\WP_REST_Request $request): bool
    {
        return str_contains($request->get_route(), '/autosaves');
    }

    /**
     * The post being saved, with the request's type/slug/template applied, so
     * applicability follows what's being saved. New posts are checked by
     * post type.
     */
    private static function postFor(\WP_REST_Request $request, mixed $prepared): ?\WP_Post
    {
        $id   = (int) ($request['id'] ?? 0);
        $post = $id > 0 ? get_post($id) : null;

        if (!$post instanceof \WP_Post) {
            $type = is_object($prepared) && isset($prepared->post_type) ? (string) $prepared->post_type : '';
            if ($type === '') {
                return null;
            }
            $post = new \WP_Post((object) ['ID' => 0, 'post_type' => $type, 'post_name' => '']);
        } else {
            $post = clone $post;
        }

        if (is_object($prepared) && isset($prepared->post_name)) {
            $post->post_name = (string) $prepared->post_name;
        }

        return $post;
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestMeta(\WP_REST_Request $request): array
    {
        $meta = $request['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestFields(\WP_REST_Request $request): array
    {
        $fields = [];
        foreach ($request->get_params() as $key => $value) {
            if (str_starts_with((string) $key, 'taw_')) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * @return callable(string, string): mixed
     */
    private static function storedReader(int $postId, string $prefix): callable
    {
        return static function (string $kind, string $key) use ($postId, $prefix): mixed {
            if ($postId <= 0) {
                return null;
            }
            if ($kind === 'meta') {
                return get_post_meta($postId, $key, true);
            }

            // taw_<id> → the decoded value, like the REST field returns it,
            // with this box's own config for this post type (ADR-0008).
            if (!str_starts_with($key, 'taw_')) {
                return null;
            }
            $config = Metabox::fieldFor('post', (string) get_post_type($postId), $prefix . substr($key, strlen('taw_')));

            return $config === null
                ? null
                : \TAW\Core\Content\FieldCodec::decode($config, get_post_meta($postId, Metabox::metaKeyOf($config), true));
        };
    }
}
