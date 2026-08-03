<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Helpers\Framework;
use TAW\Support\ViteLoader;


if (!defined('ABSPATH')) {
    exit;
}

abstract class BaseBlock
{
    protected string $id;
    protected string $dir;
    protected string $uri;

    /**
     * Set to true for above-fold blocks whose CSS must be inlined into <head>
     * to prevent layout shift. All other blocks load their CSS asynchronously
     * (non-render-blocking) to reduce network dependency depth.
     */
    protected bool $critical = false;

    private static array $enqueuedComponents = [];

    /**
     * Called once at theme boot for every Block subclass, before any template renders.
     *
     * Override in concrete blocks to register anything that must exist on every request
     * (Metaboxes, admin hooks, etc.). The default implementation is a no-op.
     * MetaBlock subclasses use registerMetaboxes() via their constructor instead.
     */
    public static function boot(): void {}

    public function __construct()
    {
        $reflector = new \ReflectionClass(static::class);
        $this->dir = dirname($reflector->getFileName());

        $this->uri = get_template_directory_uri() . '/'
            . str_replace(get_template_directory() . '/', '', $this->dir);
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Enqueue this block's CSS and JS.
     * Supports style.scss, style.css, and script.js.
     * Safe to call multiple times — only enqueues once per block.
     */
    /**
     * The ID used for asset deduplication and WP handles.
     * Overridden by MetaBlock so all variations share one enqueue slot.
     */
    protected function getAssetId(): string
    {
        return $this->id;
    }

    public function enqueueAssets(): void
    {
        $assetId = $this->getAssetId();

        if (isset(self::$enqueuedComponents[$assetId])) {
            return;
        }
        self::$enqueuedComponents[$assetId] = true;

        $relative_dir = str_replace(get_template_directory() . '/', '', $this->dir);

        if (ViteLoader::isDevServerRunning()) {
            $this->enqueueDevAssets($relative_dir, $assetId);
        } else {
            $this->enqueueProdAssets($relative_dir, $assetId);
        }
    }

    /**
     * DEV: serve assets from Vite dev server for HMR.
     */
    private function enqueueDevAssets(string $relative_dir, string $assetId): void
    {
        $style_ext = $this->resolveStyleExtension();
        $head_done = did_action('wp_head') > 0;

        if ($style_ext) {
            $url = ViteLoader::devServerOrigin() . '/' . $relative_dir . '/' . $style_ext;

            if ($head_done) {
                // Fallback: wp_head already fired, print inline
                printf('<link rel="stylesheet" href="%s">' . "\n", esc_url($url));
            } else {
                wp_enqueue_style('taw-block-' . $assetId, $url, [], null);
            }
        }

        if (file_exists($this->dir . '/script.js')) {
            $handle = 'taw-block-' . $assetId;
            wp_enqueue_script($handle, ViteLoader::devServerOrigin() . '/' . $relative_dir . '/script.js', ['vite-client'], null, false);
            ViteLoader::$moduleHandles[] = $handle;
            wp_script_add_data($handle, 'group', 0);
        }
    }

    /**
     * PROD: resolve hashed filenames from the Vite manifest.
     *
     * CSS loading strategy:
     *  - $critical = true  → <style> inlined into <head> via wp_head hook (priority 11).
     *                        Never echoed directly so it cannot land before <!DOCTYPE html>
     *                        regardless of when enqueueAssets() is called.
     *                        Falls back to a sync <link> if wp_head has already completed.
     *  - $critical = false → async <link media="print" onload> via wp_head hook (priority 50)
     *                        when called before/during wp_head, or inline when called after.
     *
     * Also enqueues any CSS a script.js entry pulls in via `import '...css'`
     * (e.g. a third-party library's stylesheet). Vite's dev server injects
     * that CSS itself, but a production build extracts it into its own file
     * and records it on the *script's* manifest entry ($manifest[$js_key]['css']),
     * not the block's dedicated style.scss/style.css entry — so it has to be
     * read and enqueued separately from $style_key.
     */
    private function enqueueProdAssets(string $relative_dir, string $assetId): void
    {
        $manifest  = ViteLoader::getManifest();
        $scss_key  = $relative_dir . '/style.scss';
        $css_key   = $relative_dir . '/style.css';
        $js_key    = $relative_dir . '/script.js';
        $style_key = isset($manifest[$scss_key]) ? $scss_key : (isset($manifest[$css_key]) ? $css_key : null);

        $head_done = did_action('wp_head') > 0;

        if ($style_key) {
            $this->enqueueCssFile($manifest[$style_key]['file'], $assetId, $head_done);
        }

        if (isset($manifest[$js_key])) {
            $handle = 'taw-block-' . $assetId;
            wp_enqueue_script($handle, ViteLoader::distUrl($manifest[$js_key]['file']), [], null, false);
            ViteLoader::$moduleHandles[] = $handle;
            wp_script_add_data($handle, 'group', 0);

            foreach ($manifest[$js_key]['css'] ?? [] as $index => $css_file) {
                $css_id = $index === 0 ? $assetId . '-script' : $assetId . '-script-' . $index;
                $this->enqueueCssFile($css_file, $css_id, $head_done);
            }
        }
    }

    /**
     * Emit a single manifest-resolved CSS file, respecting the same
     * critical/non-critical inline-vs-async logic documented on
     * enqueueProdAssets(). Shared by the block's own style.scss/style.css
     * and any CSS a script.js entry carries as a Rollup side-output.
     */
    private function enqueueCssFile(string $file, string $styleId, bool $head_done): void
    {
        $url      = ViteLoader::distUrl($file);
        $css_path = Framework::themePath(ViteLoader::distDir() . '/' . $file);

        if ($this->critical) {
            if ($head_done) {
                // wp_head already fired — best-effort sync <link> in body
                printf('<link rel="stylesheet" href="%s">' . "\n", esc_url($url));
            } else {
                // Always schedule via wp_head hook so the <style> is guaranteed
                // to land inside <head> no matter when enqueueAssets() was called.
                // Priority 11 runs right after wp_enqueue_scripts (10), before the
                // async <link> tags (50), and well before </head>.
                add_action('wp_head', static function () use ($css_path, $styleId): void {
                    ViteLoader::inlineCssFile($css_path, 'block-' . $styleId);
                }, 11);
            }
        } elseif ($head_done) {
            // Non-critical, after head (below-fold render) — async, no noscript needed
            printf(
                '<link rel="stylesheet" href="%s" media="print" onload="this.media=\'all\'">' . "\n",
                esc_url($url)
            );
        } else {
            // Non-critical, inside head — schedule async output via late wp_head hook
            add_action('wp_head', static function () use ($url): void {
                printf(
                    '<link rel="stylesheet" href="%s" media="print" onload="this.media=\'all\'">' . "\n",
                    esc_url($url)
                );
                printf(
                    '<noscript><link rel="stylesheet" href="%s"></noscript>' . "\n",
                    esc_url($url)
                );
            }, 50);
        }
    }

    /**
     * Determine which style file this block uses.
     * SCSS takes priority over CSS. Returns null if neither exists.
     */
    private function resolveStyleExtension(): ?string
    {
        if (file_exists($this->dir . '/style.scss')) {
            return 'style.scss';
        }
        if (file_exists($this->dir . '/style.css')) {
            return 'style.css';
        }
        return null;
    }

    /**
     * Include the block's index.php template with the given data.
     */
    protected function renderTemplate(array $data): void
    {
        $template = $this->dir . '/index.php';
        if (file_exists($template)) {
            extract($data, EXTR_SKIP);
            include $template;
        }
    }
}
