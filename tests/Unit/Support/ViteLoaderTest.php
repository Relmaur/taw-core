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
