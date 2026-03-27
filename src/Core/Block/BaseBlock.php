<?php

declare(strict_types=1);

namespace TAW\Core\Block;

use TAW\Helpers\Framework;
use TAW\Support\ViteLoader;

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
            $url = ViteLoader::DEV_SERVER . '/' . $relative_dir . '/' . $style_ext;

            if ($head_done) {
                // Fallback: wp_head already fired, print inline
                printf('<link rel="stylesheet" href="%s">' . "\n", esc_url($url));
            } else {
                wp_enqueue_style('taw-block-' . $assetId, $url, [], null);
            }
        }

        if (file_exists($this->dir . '/script.js')) {
            wp_enqueue_script(
                'taw-block-' . $assetId,
                ViteLoader::DEV_SERVER . '/' . $relative_dir . '/script.js',
                ['vite-client'],
                null,
                true  // footer — scripts DO have a footer fallback
            );
        }
    }

    /**
     * PROD: resolve hashed filenames from the Vite manifest.
     *
     * CSS loading strategy:
     *  - $critical = true, head not yet done → inline as <style> in <head> (zero render-blocking, zero CLS)
     *  - $critical = true, head already done → sync <link> fallback (called too late to inline)
     *  - $critical = false, head not yet done → async <link> via late wp_head hook (non-render-blocking)
     *  - $critical = false, head already done → async <link> inline (below-fold, CLS trade-off accepted)
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
            $url      = ViteLoader::distUrl($manifest[$style_key]['file']);
            $css_path = Framework::themePath(ViteLoader::distDir() . '/' . $manifest[$style_key]['file']);

            if ($this->critical && !$head_done) {
                // Inline directly into <head> — eliminates render blocking and CLS
                ViteLoader::inlineCssFile($css_path, 'block-' . $assetId);
            } elseif ($this->critical) {
                // Critical but called after head — sync link is the best we can do
                printf('<link rel="stylesheet" href="%s">' . "\n", esc_url($url));
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

        if (isset($manifest[$js_key])) {
            wp_enqueue_script(
                'taw-block-' . $assetId,
                ViteLoader::distUrl($manifest[$js_key]['file']),
                [],
                null,
                true
            );
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
