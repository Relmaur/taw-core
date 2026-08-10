<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Support;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TAW\Support\ViteLoader;
use TAW\Tests\TestCase;

/**
 * isDevServerRunning() memoizes into function-static variables (both its
 * own and hotFileUrl()'s), so each scenario needs a fresh PHP process —
 * hence #[RunInSeparateProcess] on every test here rather than the usual
 * shared-process suite. file_exists()/file_get_contents() are real PHP
 * internals Patchwork can't redefine without a project-wide allowlist, so
 * these tests use a real temp directory + real files rather than stubbing
 * them — get_template_directory() is stubbed to point Framework::themePath()
 * at that directory.
 *
 * Covers the exact regression a client project hit: no hot file for this
 * project (its own dev server was never started — only `npm run build`
 * ran), but an unrelated, non-TAW Vite project's dev server happened to be
 * occupying the hardcoded default port. The old fallback-to-default-port
 * behavior treated that as "our dev server is running" because the HTTP
 * check (GET /@vite/client -> 200) is generic to *any* Vite dev server,
 * not specific to this project's — a bare PHP built-in server answering
 * 200 on that port is enough to prove the same point without needing a
 * real Vite process.
 */
final class ViteLoaderTest extends TestCase
{
    private string $themeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeDir = sys_get_temp_dir() . '/taw-vite-test-theme-' . getmypid();
        @mkdir($this->themeDir . '/public/build', 0777, true);

        Functions\when('get_template_directory')->justReturn($this->themeDir);

        // Both static handle-tracking arrays persist across tests in the
        // same process (they're populated by real enqueue calls, not reset
        // per-request in production) — reset here so one test's tracked
        // handles can't leak into another's assertions.
        ViteLoader::$moduleHandles = [];
        ViteLoader::$styleHandles = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->themeDir . '/public/build/hot');
        @rmdir($this->themeDir . '/public/build');
        @rmdir($this->themeDir . '/public');
        @rmdir($this->themeDir);

        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function test_no_hot_file_means_not_running_even_if_the_default_port_answers(): void
    {
        // No hot file written — this project's dev server was never started.
        $server = $this->startFakeViteServer(5173);

        try {
            // The regression: an unrelated project's Vite dev server (or
            // this fake stand-in) answering on the hardcoded default port
            // must NOT be mistaken for this project's own dev server.
            $this->assertFalse(ViteLoader::isDevServerRunning());
        } finally {
            $this->stopFakeViteServer($server);
        }
    }

    #[RunInSeparateProcess]
    public function test_hot_file_present_and_reachable_means_running(): void
    {
        file_put_contents($this->themeDir . '/public/build/hot', 'http://localhost:5174');

        $server = $this->startFakeViteServer(5174);

        try {
            $this->assertTrue(ViteLoader::isDevServerRunning());
        } finally {
            $this->stopFakeViteServer($server);
        }
    }

    #[RunInSeparateProcess]
    public function test_hot_file_present_but_nothing_listening_means_not_running(): void
    {
        // Stale hot file (e.g. the dev process was SIGKILLed instead of
        // shutting down cleanly) — nothing actually bound to this port.
        file_put_contents($this->themeDir . '/public/build/hot', 'http://localhost:5199');

        $this->assertFalse(ViteLoader::isDevServerRunning());
    }

    /**
     * Regression test for a real incident: a site with a persistent object
     * cache (Redis/Memcached) kept serving a previous build's hashed asset
     * filenames — both genuinely 404ing on disk — because getManifest()
     * cached the parsed manifest under a fixed key for 24 hours, and the
     * deploy that rotated the hashes never flushed the object cache. This
     * fakes wp_cache_get()/wp_cache_set() with a simple in-memory array (the
     * real functions don't exist outside WordPress) to prove the fix:
     * changing the manifest file's mtime — exactly what happens on every
     * real deploy — must produce a cache miss and a fresh read, without
     * anyone needing to flush anything.
     */
    public function test_manifest_cache_is_invalidated_when_the_file_changes_on_disk(): void
    {
        // Both stubs share $cache by reference. wp_cache_get MUST be a full
        // closure with `use (&$cache)`, not an arrow function — an arrow
        // function captures $cache *by value* at creation time (here, while
        // still an empty array), so it would silently never observe what
        // wp_cache_set later writes, making every call look like a cache
        // miss regardless of what's actually cached. That mistake shipped
        // here once already: it made this test pass identically whether or
        // not the production cache-invalidation fix was even present.
        $cache = [];
        Functions\when('wp_cache_get')->alias(
            function (string $key, string $group) use (&$cache): mixed {
                return $cache[$group][$key] ?? false;
            }
        );
        Functions\when('wp_cache_set')->alias(
            function (string $key, mixed $value, string $group) use (&$cache): bool {
                $cache[$group][$key] = $value;
                return true;
            }
        );

        $manifestPath = $this->themeDir . '/public/build/manifest.json';

        file_put_contents($manifestPath, json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-OLDHASH.js'],
        ]));
        touch($manifestPath, 1000000000);

        $first = ViteLoader::getManifest();
        $this->assertSame('assets/app-OLDHASH.js', $first['resources/js/app.js']['file']);

        // Content changes underneath the file but its mtime is forced back to
        // the same value — proves a real cache HIT happens for an unchanged
        // mtime (not just "always reads disk," which would also pass the
        // mtime-changed assertion below without ever exercising the cache at
        // all). This is the flip side of the fix: mtime, not content, is the
        // staleness signal, by design — cheap to check on every request.
        file_put_contents($manifestPath, json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-DIFFERENT-BUT-SAME-MTIME.js'],
        ]));
        touch($manifestPath, 1000000000);

        $stillCached = ViteLoader::getManifest();
        $this->assertSame(
            'assets/app-OLDHASH.js',
            $stillCached['resources/js/app.js']['file'],
            'getManifest() re-read the file instead of serving the cached entry for an unchanged mtime.'
        );

        // Simulate a new deploy: manifest content changes, mtime necessarily
        // changes with it (a real deploy always rewrites this file).
        file_put_contents($manifestPath, json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-NEWHASH.js'],
        ]));
        touch($manifestPath, 1000000001);

        $afterDeploy = ViteLoader::getManifest();
        $this->assertSame(
            'assets/app-NEWHASH.js',
            $afterDeploy['resources/js/app.js']['file'],
            'getManifest() returned a stale cached manifest after the file on disk changed.'
        );

        @unlink($manifestPath);
    }

    /**
     * Regression coverage for the optimizer-exclusion hardening added after
     * a real production incident: WPMUdev Hummingbird CDN-rehosted a client
     * site's entry bundle cross-origin with no CORS support, hard-failing
     * the ES module silently. These markers (data-no-optimize etc.) are
     * honored by plugins that hook WordPress's normal enqueue/tag filters
     * (WP Rocket, Autoptimize, Perfmatters, LiteSpeed Cache) — they don't
     * help against a tool rewriting the raw HTML buffer directly (that
     * needs manual exclusion-list configuration regardless), but every
     * plugin playing by WordPress's rules should see them.
     */
    public function test_add_module_type_appends_optimizer_exclusion_attrs_for_a_tracked_handle(): void
    {
        Functions\when('esc_url')->returnArg();
        ViteLoader::$moduleHandles = ['theme-app'];

        $tag = ViteLoader::addModuleType('<script src="app.js"></script>', 'theme-app', 'https://example.test/app.js');

        $this->assertStringContainsString('type="module"', $tag);
        $this->assertStringContainsString('data-no-optimize="1"', $tag);
        $this->assertStringContainsString('data-cfasync="false"', $tag);
        $this->assertStringContainsString('data-no-defer="1"', $tag);
        $this->assertStringContainsString('data-no-minify="1"', $tag);
    }

    public function test_add_module_type_leaves_untracked_handles_untouched(): void
    {
        ViteLoader::$moduleHandles = ['theme-app'];

        $original = '<script src="jquery.js"></script>';
        $tag = ViteLoader::addModuleType($original, 'jquery', 'https://example.test/jquery.js');

        $this->assertSame($original, $tag);
    }

    public function test_add_style_exclusion_attrs_appends_markers_for_a_tracked_handle(): void
    {
        ViteLoader::$styleHandles = ['theme-app-style'];

        $tag = ViteLoader::addStyleExclusionAttrs(
            "<link rel='stylesheet' id='theme-app-style-css' href='https://example.test/app.css' media='all' />\n",
            'theme-app-style'
        );

        $this->assertStringContainsString('data-no-optimize="1"', $tag);
        $this->assertStringContainsString('data-cfasync="false"', $tag);
        $this->assertStringContainsString('data-no-defer="1"', $tag);
        $this->assertStringContainsString('data-no-minify="1"', $tag);
        // The original tag content (handle, href, media) must survive —
        // this filter only inserts attributes, never rebuilds the tag.
        $this->assertStringContainsString("href='https://example.test/app.css'", $tag);
    }

    public function test_add_style_exclusion_attrs_leaves_untracked_handles_untouched(): void
    {
        ViteLoader::$styleHandles = ['theme-app-style'];

        $original = "<link rel='stylesheet' id='some-plugin-style-css' href='https://example.test/plugin.css' media='all' />\n";
        $tag = ViteLoader::addStyleExclusionAttrs($original, 'some-plugin-style');

        $this->assertSame($original, $tag);
    }

    #[RunInSeparateProcess]
    public function test_preload_assets_include_optimizer_exclusion_attrs(): void
    {
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('get_template_directory_uri')->justReturn('https://example.test');

        file_put_contents($this->themeDir . '/public/build/manifest.json', json_encode([
            'resources/js/app.js' => [
                'file' => 'assets/app-HASH.js',
                'isEntry' => true,
                'css' => ['assets/app-HASH.css'],
            ],
        ]));

        ViteLoader::$moduleHandles = ['theme-app'];

        ob_start();
        ViteLoader::preloadAssets();
        $output = ob_get_clean();

        $this->assertStringContainsString('rel="modulepreload"', $output);
        $this->assertStringContainsString('rel="preload"', $output);
        $this->assertSame(
            2,
            substr_count($output, 'data-no-optimize="1"'),
            'Both the modulepreload and preload tags must carry the exclusion attrs.'
        );

        @unlink($this->themeDir . '/public/build/manifest.json');
    }

    /**
     * Regression coverage for switching the main theme CSS bundle from a
     * synchronous, render-blocking <link> to the same async media="print"
     * swap already used for non-critical block CSS (BaseBlock::enqueueCssFile()).
     * Safe now that critical.scss carries real hand-authored layout rules for
     * the header/Hero/Button markup — before that this was deliberately
     * render-blocking to avoid a flash of totally unstyled utility-first HTML.
     */
    #[RunInSeparateProcess]
    public function test_theme_css_is_enqueued_async_with_a_noscript_fallback(): void
    {
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('get_template_directory_uri')->justReturn('https://example.test');
        Functions\when('wp_enqueue_script')->justReturn(true);

        $capturedWpHeadCallbacks = [];
        Functions\when('add_action')->alias(
            function (string $hook, callable $callback, int $priority = 10) use (&$capturedWpHeadCallbacks): bool {
                if ($hook === 'wp_head') {
                    $capturedWpHeadCallbacks[$priority][] = $callback;
                }
                return true;
            }
        );

        file_put_contents($this->themeDir . '/public/build/manifest.json', json_encode([
            'resources/js/app.js' => [
                'file' => 'assets/app-HASH.js',
                'isEntry' => true,
                'css' => ['assets/app-HASH.css'],
            ],
        ]));

        $ref = new \ReflectionMethod(ViteLoader::class, 'enqueueThemeAssets');
        $ref->setAccessible(true);
        $ref->invoke(null, 'resources/js/app.js');

        $this->assertArrayHasKey(50, $capturedWpHeadCallbacks, 'CSS output must be scheduled on wp_head priority 50, matching BaseBlock non-critical CSS.');

        ob_start();
        foreach ($capturedWpHeadCallbacks[50] as $callback) {
            $callback();
        }
        $output = ob_get_clean();

        $this->assertStringContainsString('assets/app-HASH.css', $output);
        $this->assertStringContainsString('media="print"', $output);
        $this->assertStringContainsString('onload="this.media=\'all\'"', $output);
        $this->assertStringContainsString('<noscript>', $output);

        @unlink($this->themeDir . '/public/build/manifest.json');
    }

    /**
     * Starts a real PHP built-in server answering HTTP 200 to any request
     * (including GET /@vite/client) — a faithful stand-in for "a Vite dev
     * server is listening here," without depending on Vite/Node at all.
     *
     * @return resource proc_open() process handle
     */
    private function startFakeViteServer(int $port)
    {
        $docroot = sys_get_temp_dir() . '/taw-vite-test-server-' . $port;
        @mkdir($docroot, 0777, true);
        $router = $docroot . '/router.php';
        file_put_contents($router, '<?php http_response_code(200); header("Content-Type: text/javascript"); echo "// vite client stand-in";');

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $this->assertIsResource($process, 'Failed to start fake Vite server for test');

        // Poll until the port actually accepts connections rather than a
        // fixed sleep — keeps the test fast on a quiet machine and robust
        // on a loaded one.
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($probe) {
                fclose($probe);
                break;
            }
            usleep(20000);
        }

        return $process;
    }

    /** @param resource $process */
    private function stopFakeViteServer($process): void
    {
        proc_terminate($process);
        proc_close($process);
    }
}
