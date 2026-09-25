<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Assets;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TAW\Core\Assets\DevServer;
use TAW\Core\Assets\Vite;
use TAW\Tests\TestCase;

/**
 * Assets\Vite against a real temp project (manifest, block.json, sources on
 * disk). Dev mode uses a PHP built-in server standing in for Vite, as
 * ViteLoaderTest does.
 */
final class ViteTest extends TestCase
{
    private string $root;

    /** False when a test sets its own wp_cache_set expectation. */
    private bool $stubCacheSet = true;

    /** @var array<string, array{0: string, 1: array<int, string>}> */
    private array $scripts = [];

    /** @var array<string, string> */
    private array $styles = [];

    /** @var list<string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        Vite::resetForTests();
        DevServer::reset();

        $this->root = sys_get_temp_dir() . '/taw-vite-adapter-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->root . '/dist/.vite', 0777, true);
        mkdir($this->root . '/src/blocks/hero', 0777, true);

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_normalize_path')->returnArg();
        Functions\when('wp_script_is')->alias(fn (string $handle): bool => isset($this->scripts[$handle]));
        Functions\when('wp_register_script')->alias(function (string $handle, string $src, array $deps = []): bool {
            $this->scripts[$handle] = [$src, $deps];

            return true;
        });
        Functions\when('wp_register_style')->alias(function (string $handle, string $src): bool {
            $this->styles[$handle] = $src;

            return true;
        });
        Functions\when('wp_enqueue_script')->alias(function (string $handle): void {
            $this->enqueued[] = "script:{$handle}";
        });
        Functions\when('wp_enqueue_style')->alias(function (string $handle): void {
            $this->enqueued[] = "style:{$handle}";
        });
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        Vite::resetForTests();
        DevServer::reset();
        parent::tearDown();
    }

    private function vite(): Vite
    {
        return new Vite($this->root, 'https://site.test/wp-content/themes/acme');
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function manifest(array $manifest): void
    {
        if ($this->stubCacheSet) {
            Functions\when('wp_cache_set')->justReturn(true);
        }
        file_put_contents($this->root . '/dist/.vite/manifest.json', (string) json_encode($manifest));
    }

    public function test_script_registers_the_built_file_and_its_css(): void
    {
        $this->manifest(['src/js/main.ts' => ['file' => 'main-abc.js', 'css' => ['main-def.css']]]);

        $this->assertTrue($this->vite()->script('acme-main', 'src/js/main.ts', ['wp-dom-ready']));

        $this->assertSame(['https://site.test/wp-content/themes/acme/dist/main-abc.js', ['wp-dom-ready']], $this->scripts['acme-main']);
        $this->assertSame('https://site.test/wp-content/themes/acme/dist/main-def.css', $this->styles['acme-main-style']);
        $this->assertSame(['style:acme-main-style', 'script:acme-main'], $this->enqueued);
    }

    public function test_register_only_does_not_enqueue(): void
    {
        $this->manifest(['src/js/main.ts' => ['file' => 'main-abc.js', 'css' => ['a.css', 'b.css']]]);

        $vite = $this->vite();
        $vite->script('acme-main', 'src/js/main.ts', [], false);

        $this->assertSame([], $this->enqueued);
        $this->assertSame(['acme-main-style', 'acme-main-style-1'], $vite->cssHandlesFor('acme-main'));
    }

    public function test_scripts_become_modules_through_script_attributes(): void
    {
        $this->manifest(['src/js/main.ts' => ['file' => 'main-abc.js']]);

        $this->vite()->script('acme-main', 'src/js/main.ts');

        $this->assertSame(10, has_filter('wp_script_attributes', [Vite::class, 'moduleAttributes']));
        $this->assertSame(['id' => 'acme-main-js', 'type' => 'module'], Vite::moduleAttributes(['id' => 'acme-main-js']));
        $this->assertSame(['id' => 'jquery-js'], Vite::moduleAttributes(['id' => 'jquery-js']), 'other scripts are untouched');
        $this->assertSame(['src' => 'x.js'], Vite::moduleAttributes(['src' => 'x.js']));
    }

    public function test_style_entry(): void
    {
        $this->manifest(['src/scss/editor.scss' => ['file' => 'editor-123.css']]);

        $this->assertTrue($this->vite()->style('acme-editor', 'src/scss/editor.scss', [], false));

        $this->assertSame('https://site.test/wp-content/themes/acme/dist/editor-123.css', $this->styles['acme-editor']);
        $this->assertSame([], $this->enqueued);
    }

    public function test_an_unbuilt_entry_registers_nothing_and_raises_one_notice(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();

        $vite = $this->vite();
        $this->assertFalse($vite->script('acme-main', 'src/js/main.ts'));
        $this->assertFalse($vite->style('acme-style', 'src/scss/main.scss'));
        $this->assertNull($vite->url('/src/js/other.ts'));

        $this->assertSame([], $this->scripts);
        $this->assertSame(10, has_action('admin_notices', [$vite, 'renderNotice']));

        ob_start();
        $vite->renderNotice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<code>npm run build</code>', $html);
        $this->assertStringContainsString('src/js/main.ts, src/scss/main.scss, src/js/other.ts', $html);
    }

    public function test_the_notice_is_only_for_theme_editors(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $vite = $this->vite();
        $vite->url('src/js/main.ts');

        ob_start();
        $vite->renderNotice();
        $this->assertSame('', ob_get_clean());
    }

    public function test_a_half_written_manifest_counts_as_no_build_and_is_not_cached(): void
    {
        file_put_contents($this->root . '/dist/.vite/manifest.json', '{"src/js/main.ts": {"fi');
        Functions\expect('wp_cache_set')->never();

        $this->assertSame([], $this->vite()->manifest());
    }

    public function test_the_manifest_cache_key_includes_the_mtime(): void
    {
        $this->stubCacheSet = false;
        $this->manifest(['a.ts' => ['file' => 'a.js']]);
        $path = $this->root . '/dist/.vite/manifest.json';

        $keys = [];
        Functions\when('wp_cache_set')->alias(function (string $key, mixed $value, string $group, int $ttl) use (&$keys): bool {
            $keys[] = [$key, $group, $ttl];

            return true;
        });

        // (Brain Monkey's Patchwork stream wrapper can shift touch() times by
        // a second, so the expected key is built from the real mtime.)
        touch($path, 1700000000);
        clearstatcache();
        $first = 'taw_vite_' . md5($path) . '_' . filemtime($path);
        $this->vite()->manifest();

        touch($path, 1800000000);
        clearstatcache();
        $second = 'taw_vite_' . md5($path) . '_' . filemtime($path);
        $this->vite()->manifest();

        $this->assertNotSame($first, $second);
        $this->assertSame([[$first, 'taw_core', DAY_IN_SECONDS], [$second, 'taw_core', DAY_IN_SECONDS]], $keys);
    }

    public function test_dev_server_from_the_hot_file(): void
    {
        $port = 5187;
        file_put_contents($this->root . '/dist/hot', "http://127.0.0.1:{$port}");
        $server = $this->startFakeViteServer($port);

        try {
            $vite = $this->vite();
            $this->assertTrue($vite->isDev());
            $this->assertTrue($vite->script('acme-main', 'src/js/main.ts'));
            $this->assertSame("http://127.0.0.1:{$port}/src/js/main.ts", $this->scripts['acme-main'][0]);

            $client = $this->scripts['acme-main'][1][0];
            $this->assertStringStartsWith('taw-vite-client-', $client);
            $this->assertSame("http://127.0.0.1:{$port}/@vite/client", $this->scripts[$client][0]);
            $this->assertSame('module', Vite::moduleAttributes(['id' => "{$client}-js"])['type'] ?? null);
            $this->assertSame("http://127.0.0.1:{$port}/src/scss/a.scss", $vite->url('src/scss/a.scss'));
        } finally {
            $this->stopFakeViteServer($server);
        }
    }

    public function test_a_stale_hot_file_falls_back_to_the_build(): void
    {
        file_put_contents($this->root . '/dist/hot', 'http://127.0.0.1:5198'); // nothing listens there
        $this->manifest(['src/js/main.ts' => ['file' => 'main-abc.js']]);

        $vite = $this->vite();
        $this->assertFalse($vite->isDev());
        $this->assertSame('https://site.test/wp-content/themes/acme/dist/main-abc.js', $vite->url('src/js/main.ts'));
    }

    public function test_block_loads_file_assets_through_vite(): void
    {
        $dir = $this->root . '/src/blocks/hero';
        file_put_contents($dir . '/block.json', (string) json_encode([
            'apiVersion'   => 3,
            'name'         => 'acme/hero',
            'editorScript' => 'file:./index.tsx',
            'viewScript'   => ['file:./view.ts', 'acme-shared'],
            'style'        => 'file:./style.scss',
            'editorStyle'  => 'acme-editor-base',
        ]));
        foreach (['index.tsx', 'view.ts', 'style.scss'] as $file) {
            touch($dir . '/' . $file);
        }
        $this->manifest([
            'src/blocks/hero/index.tsx'  => ['file' => 'hero-1.js', 'css' => ['hero-editor-1.css']],
            'src/blocks/hero/style.scss' => ['file' => 'hero-style-1.css'],
            // view.ts isn't built.
        ]);
        Functions\when('current_user_can')->justReturn(false);

        $rewrite = null;
        Filters\expectAdded('block_type_metadata')->once()->whenHappen(function (callable $callback) use (&$rewrite): void {
            $rewrite = $callback;
        });
        Functions\expect('register_block_type')->once()->with($dir, ['title' => 'Hero'])->andReturn(false);

        $this->vite()->block($dir, ['title' => 'Hero']);

        $this->assertIsCallable($rewrite);
        $metadata = $rewrite([
            'file'         => realpath($dir . '/block.json'),
            'name'         => 'acme/hero',
            'editorScript' => 'file:./index.tsx',
            'viewScript'   => ['file:./view.ts', 'acme-shared'],
            'style'        => 'file:./style.scss',
            'editorStyle'  => 'acme-editor-base',
        ]);

        $this->assertSame(['acme-hero-editor-script'], $metadata['editorScript']);
        $this->assertSame(['acme-shared'], $metadata['viewScript'], 'the unbuilt file is dropped, the plain handle kept');
        $this->assertSame(['acme-hero-style'], $metadata['style']);
        $this->assertSame(['acme-editor-base', 'acme-hero-editor-script-style'], $metadata['editorStyle']);

        $this->assertSame(Vite::EDITOR_SCRIPT_DEPS, $this->scripts['acme-hero-editor-script'][1]);
        $this->assertSame('https://site.test/wp-content/themes/acme/dist/hero-style-1.css', $this->styles['acme-hero-style']);
        $this->assertSame([], $this->enqueued);

        $other = ['file' => '/elsewhere/block.json', 'editorScript' => 'file:./index.js'];
        $this->assertSame($other, $rewrite($other), 'other blocks are untouched');
    }

    public function test_block_assets_outside_the_project_are_ignored(): void
    {
        $dir = $this->root . '/src/blocks/hero';
        mkdir($this->root . '/../taw-vite-outside-' . getmypid(), 0777, true);
        $outside = realpath($this->root . '/../taw-vite-outside-' . getmypid());
        touch($outside . '/x.ts');

        try {
            $vite = $this->vite();
            $this->assertNull($this->callMethod($vite, 'sourceFor', $dir, '../../../../taw-vite-outside-' . getmypid() . '/x.ts'));
            touch($dir . '/readme.md');
            $this->assertNull($this->callMethod($vite, 'sourceFor', $dir, './readme.md'));
            touch($dir . '/index.tsx');
            $this->assertSame('src/blocks/hero/index.tsx', $this->callMethod($vite, 'sourceFor', $dir, './index.tsx'));
        } finally {
            $this->removeTree($outside);
        }
    }

    public function test_a_missing_block_json_registers_nothing(): void
    {
        Functions\expect('register_block_type')->never();

        $this->assertFalse($this->vite()->block($this->root . '/src/blocks/nope'));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @return resource
     */
    private function startFakeViteServer(int $port)
    {
        $router = $this->root . '/router.php';
        file_put_contents($router, '<?php http_response_code(200); echo "// vite client stand-in";');

        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

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

    /**
     * @param resource $process
     */
    private function stopFakeViteServer($process): void
    {
        proc_terminate($process);
        proc_close($process);
    }
}
