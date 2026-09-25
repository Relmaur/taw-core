<?php

declare(strict_types=1);

namespace TAW\Core\Assets;

use TAW\Helpers\Framework;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A Vite adapter for one project root (ADR-0006): a block theme, or a
 * package with its own build (taw/core's data panel).
 *
 *   $vite = Vite::theme();
 *   $vite->script('my-theme-main', 'src/js/main.ts');
 *   $vite->style('my-theme-editor', 'src/scss/editor.scss', [], false);
 *   $vite->block(get_theme_file_path('src/blocks/hero'));
 *
 * Dev: assets come from the project's own dev server, found through its hot
 * file (`{outDir}/hot`, written by the `hotFile()` plugin in
 * resources/vite/taw-vite.mjs; see DevServer).
 * Build: hashed files are looked up in `{outDir}/.vite/manifest.json`.
 * Neither: nothing is enqueued, and administrators get one notice asking
 * for a build. The page still renders.
 *
 * Every script it registers is an ES module. `type="module"` is added through
 * wp_script_attributes, so the tag keeps its id, inline scripts and
 * translations (a script_loader_tag rewrite would drop them).
 *
 * Unlike the classic TAW\Support\ViteLoader, this adds no hooks until it
 * registers something, and it's never booted automatically.
 */
final class Vite
{
    /** Script dependencies for a block's editor script when none are given. */
    public const EDITOR_SCRIPT_DEPS = [
        'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data',
    ];

    /** Who sees the "assets aren't built" notice. */
    private const NOTICE_CAPABILITY = 'edit_theme_options';

    private const CACHE_GROUP = 'taw_core';

    private const SCRIPT_EXTENSIONS = ['js', 'mjs', 'jsx', 'ts', 'mts', 'tsx'];

    private const STYLE_EXTENSIONS = ['css', 'scss', 'sass', 'less'];

    /** block.json asset fields: field → whether it holds scripts. */
    private const BLOCK_FIELDS = [
        'editorScript' => true, 'script' => true, 'viewScript' => true,
        'editorStyle'  => false, 'style' => false, 'viewStyle' => false,
    ];

    private static ?self $theme = null;

    /** @var array<string, true> Handles printed as type="module", across instances. */
    private static array $moduleHandles = [];

    private static bool $attributesFilterAdded = false;

    private readonly string $dir;

    private readonly string $url;

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** @var array<string, list<string>> Script handle → the style handles of its extracted CSS. */
    private array $cssHandles = [];

    /** @var array<string, true> Sources missing from the build. */
    private array $missing = [];

    private bool $noticeHooked = false;

    /**
     * @param string $dir    Absolute path of the Vite project root (where vite.config.js lives).
     * @param string $url    Public URL of that directory.
     * @param string $outDir Vite's build.outDir, relative to $dir.
     */
    public function __construct(string $dir, string $url, private readonly string $outDir = 'dist')
    {
        $this->dir = rtrim($dir, '/');
        $this->url = rtrim($url, '/');
    }

    /**
     * The adapter for the active parent theme (the one that ships the build).
     */
    public static function theme(): self
    {
        return self::$theme ??= new self(Framework::themePath(), Framework::themeUrl());
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function isDev(): bool
    {
        return DevServer::isRunning([$this->outPath('hot')]);
    }

    /**
     * The dev server origin from the hot file, or null.
     */
    public function devOrigin(): ?string
    {
        return $this->isDev() ? DevServer::hotFileUrl([$this->outPath('hot')]) : null;
    }

    /**
     * The decoded build manifest ([] when there's no usable build).
     *
     * Cached in the object cache under a key that includes the file's mtime,
     * so a new build can never be served stale file names.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->outPath('.vite/manifest.json');
        if (!is_file($path)) {
            return $this->manifest = [];
        }

        $key    = 'taw_vite_' . md5($path) . '_' . (string) filemtime($path);
        $cached = wp_cache_get($key, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $this->manifest = $cached;
        }

        // A half-written manifest (mid-deploy) must not break every page:
        // treat it as "no build" and don't cache it.
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return $this->manifest = [];
        }

        wp_cache_set($key, $decoded, self::CACHE_GROUP, DAY_IN_SECONDS);

        return $this->manifest = $decoded;
    }

    /**
     * A built entry: its file and the CSS Vite extracted from it.
     *
     * @param string $source Path relative to the project root, e.g. 'src/js/main.ts'.
     * @return array{file: string, css: list<string>}|null
     */
    public function entry(string $source): ?array
    {
        $entry = $this->manifest()[ltrim($source, '/')] ?? null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null) || $entry['file'] === '') {
            return null;
        }

        $css = [];
        foreach ((array) ($entry['css'] ?? []) as $file) {
            if (is_string($file) && $file !== '') {
                $css[] = $file;
            }
        }

        return ['file' => $entry['file'], 'css' => $css];
    }

    /**
     * The URL to load a source from: the dev server, or its built file.
     * Null when it isn't built (the notice then names it).
     */
    public function url(string $source): ?string
    {
        $origin = $this->devOrigin();
        if ($origin !== null) {
            return rtrim($origin, '/') . '/' . ltrim($source, '/');
        }

        $entry = $this->entry($source);
        if ($entry === null) {
            $this->reportMissing($source);

            return null;
        }

        return $this->distUrl($entry['file']);
    }

    /**
     * Register (and by default enqueue) a script entry as an ES module.
     * CSS that Vite extracts from it is registered as "{handle}-style",
     * "{handle}-style-1", … and enqueued with it.
     *
     * @param list<string> $deps
     * @return bool Whether the script was registered.
     */
    public function script(string $handle, string $source, array $deps = [], bool $enqueue = true): bool
    {
        $origin = $this->devOrigin();

        if ($origin !== null) {
            $client = $this->registerDevClient($origin);
            wp_register_script($handle, rtrim($origin, '/') . '/' . ltrim($source, '/'), [$client, ...$deps], null, true);
        } else {
            $entry = $this->entry($source);
            if ($entry === null) {
                $this->reportMissing($source);

                return false;
            }

            wp_register_script($handle, $this->distUrl($entry['file']), $deps, null, true);

            $this->cssHandles[$handle] = [];
            foreach ($entry['css'] as $i => $file) {
                $styleHandle = $i === 0 ? "{$handle}-style" : "{$handle}-style-{$i}";
                wp_register_style($styleHandle, $this->distUrl($file), [], null);
                $this->cssHandles[$handle][] = $styleHandle;
                if ($enqueue) {
                    wp_enqueue_style($styleHandle);
                }
            }
        }

        $this->markModule($handle);
        if ($enqueue) {
            wp_enqueue_script($handle);
        }

        return true;
    }

    /**
     * Register (and by default enqueue) a stylesheet entry (css/scss…).
     * In dev, Vite compiles it on request.
     *
     * @param list<string> $deps
     */
    public function style(string $handle, string $source, array $deps = [], bool $enqueue = true): bool
    {
        $url = $this->url($source);
        if ($url === null) {
            return false;
        }

        wp_register_style($handle, $url, $deps, null);
        if ($enqueue) {
            wp_enqueue_style($handle);
        }

        return true;
    }

    /**
     * The style handles registered for a script's extracted CSS (build only).
     *
     * @return list<string>
     */
    public function cssHandlesFor(string $scriptHandle): array
    {
        return $this->cssHandles[$scriptHandle] ?? [];
    }

    /**
     * Register a block from its block.json, loading its `file:` assets
     * (index.tsx, view.ts, style.scss…) through Vite.
     *
     * Plain handles in block.json are left alone. A `file:` asset that isn't
     * built is dropped (with the notice) instead of letting WordPress register
     * the raw source file. CSS extracted from the editor script is added to
     * editorStyle, and from script/viewScript to style/viewStyle.
     *
     * @param string       $blockDir         Folder holding block.json.
     * @param array<string, mixed> $args     Passed to register_block_type().
     * @param list<string> $editorScriptDeps WordPress scripts the editor script needs.
     * @return \WP_Block_Type|false
     */
    public function block(string $blockDir, array $args = [], array $editorScriptDeps = self::EDITOR_SCRIPT_DEPS): \WP_Block_Type|false
    {
        $file = rtrim($blockDir, '/') . '/block.json';
        $real = realpath($file);
        if ($real === false) {
            return false;
        }

        $metadata = json_decode((string) file_get_contents($real), true);
        if (!is_array($metadata) || !is_string($metadata['name'] ?? null)) {
            return false;
        }

        $fields = $this->blockAssetFields($metadata, dirname($real), $editorScriptDeps);
        $target = wp_normalize_path($real);

        $filter = static function (array $meta) use ($target, $fields): array {
            if (($meta['file'] ?? null) !== $target) {
                return $meta;
            }
            foreach ($fields as $field => $handles) {
                if ($handles === []) {
                    unset($meta[$field]);
                } else {
                    $meta[$field] = $handles;
                }
            }

            return $meta;
        };

        add_filter('block_type_metadata', $filter);
        try {
            return register_block_type($blockDir, $args);
        } finally {
            remove_filter('block_type_metadata', $filter);
        }
    }

    /**
     * wp_script_attributes: add type="module" to scripts this class registered.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function moduleAttributes(array $attributes): array
    {
        $id = $attributes['id'] ?? null;
        if (is_string($id) && str_ends_with($id, '-js') && isset(self::$moduleHandles[substr($id, 0, -3)])) {
            $attributes['type'] = 'module';
        }

        return $attributes;
    }

    /**
     * admin_notices: name what isn't built.
     */
    public function renderNotice(): void
    {
        if ($this->missing === [] || !current_user_can(self::NOTICE_CAPABILITY)) {
            return;
        }

        $sources = array_keys($this->missing);
        $shown   = array_slice($sources, 0, 5);
        $more    = count($sources) - count($shown);

        printf(
            '<div class="notice notice-warning"><p>%s %s</p><p><code>%s</code>%s</p></div>',
            esc_html(sprintf(
                /* translators: %s: folder name of the theme or package. */
                __('Some assets in “%s” aren’t built, so they aren’t loaded.', 'taw-core'),
                basename($this->dir)
            )),
            sprintf(
                /* translators: 1: the build command, 2: the dev-server command. */
                esc_html__('Run %1$s in that folder (%2$s while developing).', 'taw-core'),
                '<code>npm run build</code>',
                '<code>npm run dev</code>'
            ),
            esc_html(implode(', ', $shown)),
            $more > 0 ? esc_html(sprintf(' +%d', $more)) : ''
        );
    }

    /**
     * Forget the shared theme instance and module handles (tests only).
     */
    public static function resetForTests(): void
    {
        self::$theme                 = null;
        self::$moduleHandles         = [];
        self::$attributesFilterAdded = false;
    }

    /**
     * Register each `file:` asset of a block.json through Vite.
     *
     * @param array<string, mixed> $metadata
     * @param list<string>         $editorScriptDeps
     * @return array<string, list<string>> Field → handles, for the fields that had `file:` assets.
     */
    private function blockAssetFields(array $metadata, string $blockDir, array $editorScriptDeps): array
    {
        $name   = (string) $metadata['name'];
        $fields = [];
        $extra  = ['editorStyle' => [], 'style' => [], 'viewStyle' => []];

        foreach (self::BLOCK_FIELDS as $field => $isScript) {
            if (!isset($metadata[$field])) {
                continue;
            }

            $values  = is_array($metadata[$field]) ? $metadata[$field] : [$metadata[$field]];
            $hasFile = false;
            $handles = [];

            foreach ($values as $index => $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (!str_starts_with($value, 'file:')) {
                    $handles[] = $value;
                    continue;
                }

                $hasFile = true;
                $source  = $this->sourceFor($blockDir, substr($value, 5));
                if ($source === null) {
                    continue;
                }

                $handle = self::blockHandle($name, $field, (int) $index);
                if ($isScript) {
                    $deps = $field === 'editorScript' ? $editorScriptDeps : [];
                    if ($this->script($handle, $source, $deps, false)) {
                        $handles[] = $handle;
                        $cssField  = $field === 'editorScript' ? 'editorStyle' : ($field === 'viewScript' ? 'viewStyle' : 'style');
                        $extra[$cssField] = [...$extra[$cssField], ...$this->cssHandlesFor($handle)];
                    }
                } elseif ($this->style($handle, $source, [], false)) {
                    $handles[] = $handle;
                }
            }

            if ($hasFile) {
                $fields[$field] = $handles;
            }
        }

        foreach ($extra as $field => $handles) {
            if ($handles === []) {
                continue;
            }
            $existing = $fields[$field] ?? null;
            if ($existing === null) {
                $existing = isset($metadata[$field]) ? array_values(array_filter((array) $metadata[$field], 'is_string')) : [];
            }
            $fields[$field] = [...$existing, ...$handles];
        }

        return $fields;
    }

    /**
     * A block asset path as a source relative to the project root, or null
     * when it's outside the root or not a script/style.
     */
    private function sourceFor(string $blockDir, string $relative): ?string
    {
        $relative = str_starts_with($relative, './') ? substr($relative, 2) : $relative;
        $path     = realpath($blockDir . '/' . $relative);
        $root = realpath($this->dir);
        if ($path === false || $root === false || !str_starts_with($path, $root . '/')) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, [...self::SCRIPT_EXTENSIONS, ...self::STYLE_EXTENSIONS], true)) {
            return null;
        }

        return substr($path, strlen($root) + 1);
    }

    private static function blockHandle(string $blockName, string $field, int $index): string
    {
        $handle = str_replace('/', '-', $blockName) . '-' . strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $field));

        return $index > 0 ? "{$handle}-{$index}" : $handle;
    }

    private function registerDevClient(string $origin): string
    {
        $handle = 'taw-vite-client-' . substr(md5($origin), 0, 8);
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, rtrim($origin, '/') . '/@vite/client', [], null, true);
            $this->markModule($handle);
        }

        return $handle;
    }

    private function markModule(string $handle): void
    {
        self::$moduleHandles[$handle] = true;

        if (!self::$attributesFilterAdded) {
            self::$attributesFilterAdded = true;
            add_filter('wp_script_attributes', [self::class, 'moduleAttributes']);
        }
    }

    private function reportMissing(string $source): void
    {
        $this->missing[ltrim($source, '/')] = true;

        if (!$this->noticeHooked) {
            $this->noticeHooked = true;
            add_action('admin_notices', [$this, 'renderNotice']);
        }
    }

    private function outPath(string $relative): string
    {
        return $this->dir . '/' . trim($this->outDir, '/') . '/' . $relative;
    }

    private function distUrl(string $file): string
    {
        return $this->url . '/' . trim($this->outDir, '/') . '/' . ltrim($file, '/');
    }
}
