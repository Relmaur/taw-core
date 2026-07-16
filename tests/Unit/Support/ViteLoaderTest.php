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
