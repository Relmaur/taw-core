<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Lens;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use TAW\Core\Assets\Vite;
use TAW\Core\Lens\ClassicAdapter;
use TAW\Core\Lens\Lens;
use TAW\Core\Lens\SourceMap;
use TAW\Tests\TestCase;

final class LensTest extends TestCase
{
    private string $root = '';

    /** @var array<string, array{0: string, 1: list<string>}> */
    private array $scripts = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $inline = [];

    /** @var array{toggle: mixed, admin: bool, ajax: bool, preview: bool, loggedIn: bool, can: bool, dev: bool} */
    private array $site = ['toggle' => '1', 'admin' => false, 'ajax' => false, 'preview' => false, 'loggedIn' => true, 'can' => true, 'dev' => true];

    /** @var list<string> */
    private array $capsAsked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Lens::resetForTests();

        $this->root = sys_get_temp_dir() . '/taw-lens-' . uniqid();
        mkdir($this->root . '/assets/lens/.vite', 0777, true);
        file_put_contents($this->root . '/assets/lens/.vite/manifest.json', (string) json_encode([
            Lens::SCRIPT_SOURCE => ['file' => 'lens-1.js', 'css' => []],
        ]));
        Lens::useVite(new Vite($this->root, 'https://site.test/vendor/taw/core', 'assets/lens'));
        Lens::useDevServerCheck(fn (): bool => $this->site['dev']);

        Functions\when('is_admin')->alias(fn (): bool => $this->site['admin']);
        Functions\when('wp_doing_ajax')->alias(fn (): bool => $this->site['ajax']);
        Functions\when('is_customize_preview')->alias(fn (): bool => $this->site['preview']);
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $key === '_taw_lens_enabled' ? $this->site['toggle'] : $default);
        Functions\when('is_user_logged_in')->alias(fn (): bool => $this->site['loggedIn']);
        Functions\when('current_user_can')->alias(function (string $cap): bool {
            $this->capsAsked[] = $cap;

            return $this->site['can'];
        });
        Functions\when('home_url')->justReturn('http://taw.local');
        Functions\when('wp_parse_url')->alias(static fn (string $url, int $part = -1) => parse_url($url, $part));
        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data, JSON_UNESCAPED_SLASHES));
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_register_script')->alias(function (string $handle, string $src, array $deps): bool {
            $this->scripts[$handle] = [$src, $deps];

            return true;
        });
        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\when('wp_add_inline_script')->alias(function (string $handle, string $js, string $position): bool {
            $this->inline[] = [$handle, $js, $position];

            return true;
        });
        Functions\when('get_translations_for_domain')->justReturn((object) ['entries' => []]);
        Functions\when('determine_locale')->justReturn('en_US');
    }

    protected function tearDown(): void
    {
        Lens::resetForTests();
        SourceMap::resetForTests();
        ClassicAdapter::resetForTests();
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_register_adds_one_frontend_hook_once(): void
    {
        Lens::register();
        Lens::register();

        $this->assertSame(10, has_action('wp_enqueue_scripts', [Lens::class, 'enqueue']));
        $this->assertSame(PHP_INT_MAX, has_action('template_redirect', [Lens::class, 'startSourceMap']));
    }

    public function test_the_source_map_starts_only_under_the_gate(): void
    {
        foreach (['is_feed', 'is_embed', 'is_robots', 'is_trackback'] as $fn) {
            Functions\when($fn)->justReturn(false);
        }
        $this->site['dev'] = false;
        Lens::startSourceMap();
        $this->assertFalse(SourceMap::on());

        $this->site['dev'] = true;
        Lens::startSourceMap();
        $this->assertTrue(SourceMap::on());
        $this->assertSame(10, has_action('wp_before_load_template', [ClassicAdapter::class, 'openPart']));
    }

    public function test_open_in_editor_defaults_to_vs_code_and_can_be_changed(): void
    {
        $this->assertSame('vscode://file/{path}:{line}', Lens::config()['editor']);

        Filters\expectApplied('taw_lens_editor_url')->andReturn('cursor://file/{path}:{line}');
        $this->assertSame('cursor://file/{path}:{line}', Lens::config()['editor']);
    }

    public function test_it_loads_the_committed_build_with_its_config_when_every_gate_is_open(): void
    {
        Lens::enqueue();

        $this->assertSame(['https://site.test/vendor/taw/core/assets/lens/lens-1.js', Lens::SCRIPT_DEPS], $this->scripts[Lens::SCRIPT_HANDLE]);
        $this->assertCount(1, $this->inline);
        [$handle, $js, $position] = $this->inline[0];
        $this->assertSame([Lens::SCRIPT_HANDLE, 'before'], [$handle, $position]);
        $this->assertStringStartsWith('window.tawLens = {"host":"taw.local","version":', $js);
        $this->assertSame([Lens::CAPABILITY], $this->capsAsked);
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function closedGates(): iterable
    {
        yield 'toggle off' => ['toggle', ''];
        yield 'toggle never saved' => ['toggle', false];
        yield 'logged out' => ['loggedIn', false];
        yield "can't edit the theme" => ['can', false];
        yield 'no dev server' => ['dev', false];
        yield 'wp-admin' => ['admin', true];
        yield 'admin-ajax' => ['ajax', true];
        yield 'customizer preview' => ['preview', true];
    }

    #[DataProvider('closedGates')]
    public function test_it_prints_nothing_when_any_gate_is_closed(string $gate, mixed $value): void
    {
        $this->site[$gate] = $value;

        $this->assertFalse(Lens::active());
        Lens::enqueue();
        $this->assertSame([], $this->scripts);
        $this->assertSame([], $this->inline);
    }

    public function test_the_dev_server_is_probed_last(): void
    {
        $probed = false;
        Lens::useDevServerCheck(static function () use (&$probed): bool {
            $probed = true;

            return true;
        });
        $this->site['toggle'] = '';

        Lens::active();

        $this->assertFalse($probed, 'a site with the toggle off never pays for the HTTP probe');
    }

    public function test_the_host_keeps_a_port(): void
    {
        Functions\when('home_url')->justReturn('http://localhost:10003/');

        $this->assertSame('localhost:10003', Lens::config()['host']);
    }

    public function test_the_committed_build_has_the_entry_php_loads(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/assets/lens/.vite/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey(Lens::SCRIPT_SOURCE, $manifest, 'run npm run build in resources/lens');
        $this->assertFileExists(dirname(__DIR__, 4) . '/assets/lens/' . $manifest[Lens::SCRIPT_SOURCE]['file']);
    }
}
