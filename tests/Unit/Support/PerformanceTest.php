<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Support;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TAW\Support\Performance;
use TAW\Support\ViteLoader;
use TAW\Tests\TestCase;

/**
 * injectBuildAssetCacheHtaccess() writes into a path resolved through
 * ViteLoader::distDir(), which memoizes into a function-static — same
 * reason ViteLoaderTest needs a fresh process per scenario, so this suite
 * does too. Real temp directories + real file writes throughout, same
 * rationale as ViteLoaderTest: file_exists()/is_writable()/file_put_contents()
 * are real PHP internals, not worth stubbing when a real temp dir is cheap
 * and proves the actual file ends up in the actual place.
 */
final class PerformanceTest extends TestCase
{
    private string $themeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeDir = sys_get_temp_dir() . '/taw-perf-test-theme-' . getmypid() . '-' . uniqid();
        Functions\when('get_template_directory')->justReturn($this->themeDir);

        // insert_with_markers() is a real WP core function (wp-admin/includes/misc.php)
        // not available outside a real WP install — stub it with a faithful-enough
        // version that actually writes the file, so assertions can read real content
        // back rather than trusting a call was merely made.
        Functions\when('insert_with_markers')->alias(
            function (string $file, string $marker, array $insertion): bool {
                $start = "# BEGIN {$marker}\n";
                $end = "# END {$marker}\n";
                file_put_contents($file, $start . implode("\n", $insertion) . "\n" . $end);
                return true;
            }
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->themeDir);
        parent::tearDown();
    }

    #[RunInSeparateProcess]
    public function test_writes_immutable_cache_rules_into_the_assets_directory_when_it_exists(): void
    {
        Performance::configure(['build_asset_cache_htaccess' => true]);
        @mkdir($this->themeDir . '/public/build/assets', 0777, true);
        file_put_contents($this->themeDir . '/public/build/manifest.json', '{}');

        Performance::injectBuildAssetCacheHtaccess();

        $htaccess = $this->themeDir . '/public/build/assets/.htaccess';
        $this->assertFileExists($htaccess);

        $content = file_get_contents($htaccess);
        $this->assertStringContainsString('max-age=31536000, public, immutable', $content);
        $this->assertStringContainsString('ExpiresDefault "access plus 1 year"', $content);
        // Scoped by directory, not by extension — no FilesMatch needed or wanted
        // here (unlike the root-level font rule), since everything in this
        // directory is Vite's hashed output by construction.
        $this->assertStringNotContainsString('FilesMatch', $content);
    }

    #[RunInSeparateProcess]
    public function test_does_nothing_when_the_assets_directory_does_not_exist_yet(): void
    {
        Performance::configure(['build_asset_cache_htaccess' => true]);
        // Deliberately no mkdir — simulates a theme activated before `npm run build`
        // ever ran, which must no-op quietly rather than error.

        Performance::injectBuildAssetCacheHtaccess();

        $this->assertFileDoesNotExist($this->themeDir . '/public/build/assets/.htaccess');
    }

    #[RunInSeparateProcess]
    public function test_does_nothing_when_disabled_via_config(): void
    {
        Performance::configure(['build_asset_cache_htaccess' => false]);
        @mkdir($this->themeDir . '/public/build/assets', 0777, true);
        file_put_contents($this->themeDir . '/public/build/manifest.json', '{}');

        Performance::injectBuildAssetCacheHtaccess();

        $this->assertFileDoesNotExist($this->themeDir . '/public/build/assets/.htaccess');
    }

    /**
     * Regression test for the writability-check bug this change also fixed:
     * the original `!is_writable($file) && !file_exists($file)` logic bailed
     * on every not-yet-existing file, since is_writable() on a nonexistent
     * path returns false — meaning the feature silently never ran on the
     * single most common case, a fresh site with no pre-existing .htaccess
     * of its own in that directory yet.
     */
    #[RunInSeparateProcess]
    public function test_creates_the_htaccess_when_none_exists_yet_but_the_directory_is_writable(): void
    {
        Performance::configure(['build_asset_cache_htaccess' => true]);
        @mkdir($this->themeDir . '/public/build/assets', 0777, true);
        file_put_contents($this->themeDir . '/public/build/manifest.json', '{}');

        $htaccess = $this->themeDir . '/public/build/assets/.htaccess';
        $this->assertFileDoesNotExist($htaccess, 'Precondition: no .htaccess should exist before the call.');

        Performance::injectBuildAssetCacheHtaccess();

        $this->assertFileExists($htaccess);
    }

    /**
     * Local by Flywheel's default site environment — the primary local dev
     * tool this codebase is built against — turned out to run nginx, not
     * Apache: discovered live, not hypothesized. .htaccess is silently inert
     * there, so the framework's only real recourse is telling a logged-in
     * admin exactly what to paste into their server block.
     */
    #[RunInSeparateProcess]
    public function test_nginx_notice_renders_with_the_config_snippet_when_nginx_is_detected(): void
    {
        $this->stubNoticeDependencies();
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.1';

        ob_start();
        Performance::maybeShowNginxCacheNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $output);
        $this->assertStringContainsString('location ^~', $output);
        $this->assertStringContainsString('expires 1y', $output);
        $this->assertStringContainsString('public, immutable', $output);
    }

    #[RunInSeparateProcess]
    public function test_nginx_notice_stays_silent_on_apache(): void
    {
        $this->stubNoticeDependencies();
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Unix)';

        ob_start();
        Performance::maybeShowNginxCacheNotice();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    #[RunInSeparateProcess]
    public function test_nginx_notice_stays_silent_once_dismissed(): void
    {
        $this->stubNoticeDependencies(dismissed: true);
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.1';

        ob_start();
        Performance::maybeShowNginxCacheNotice();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    #[RunInSeparateProcess]
    public function test_nginx_notice_stays_silent_for_a_user_without_manage_options(): void
    {
        $this->stubNoticeDependencies(canManageOptions: false);
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.1';

        ob_start();
        Performance::maybeShowNginxCacheNotice();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    #[RunInSeparateProcess]
    public function test_nginx_notice_stays_silent_when_the_feature_itself_is_disabled(): void
    {
        $this->stubNoticeDependencies();
        Performance::configure(['build_asset_cache_htaccess' => false]);
        $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26.1';

        ob_start();
        Performance::maybeShowNginxCacheNotice();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    #[RunInSeparateProcess]
    public function test_dismiss_handler_persists_dismissal_only_for_an_authorized_verified_request(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('check_admin_referer')->justReturn(true);

        $stored = false;
        Functions\when('update_option')->alias(function (string $key, mixed $value) use (&$stored): bool {
            $stored = $value;
            return true;
        });

        $_GET['taw_dismiss_nginx_cache_notice'] = '1';
        Performance::maybeDismissNginxCacheNotice();

        $this->assertTrue($stored);
    }

    #[RunInSeparateProcess]
    public function test_dismiss_handler_does_nothing_without_the_query_arg(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $called = false;
        Functions\when('update_option')->alias(function () use (&$called): bool {
            $called = true;
            return true;
        });

        unset($_GET['taw_dismiss_nginx_cache_notice']);
        Performance::maybeDismissNginxCacheNotice();

        $this->assertFalse($called);
    }

    #[RunInSeparateProcess]
    public function test_prefers_avif_when_the_host_supports_encoding_it(): void
    {
        Performance::configure(['modern_image_formats' => true]);
        Functions\when('wp_image_editor_supports')->alias(
            fn (array $args): bool => $args['mime_type'] === 'image/avif'
        );

        $result = Performance::preferModernImageFormat([], '/uploads/photo.jpg', 'image/jpeg');

        $this->assertSame('image/avif', $result['image/jpeg']);
    }

    #[RunInSeparateProcess]
    public function test_falls_back_to_webp_when_avif_encoding_is_not_available(): void
    {
        Performance::configure(['modern_image_formats' => true]);
        Functions\when('wp_image_editor_supports')->alias(
            fn (array $args): bool => $args['mime_type'] === 'image/webp'
        );

        $result = Performance::preferModernImageFormat([], '/uploads/photo.png', 'image/png');

        $this->assertSame('image/webp', $result['image/png']);
    }

    /**
     * Real-world case: a host with only GD and no AVIF/WebP encoder support
     * at all. Must leave the original format untouched rather than pointing
     * at a format the server can't actually produce.
     */
    #[RunInSeparateProcess]
    public function test_leaves_the_format_untouched_when_the_host_supports_neither(): void
    {
        Performance::configure(['modern_image_formats' => true]);
        Functions\when('wp_image_editor_supports')->justReturn(false);

        $result = Performance::preferModernImageFormat([], '/uploads/photo.jpg', 'image/jpeg');

        $this->assertArrayNotHasKey('image/jpeg', $result);
    }

    /**
     * Animated GIFs must never be converted — re-encoding through a generic
     * image editor save() would silently collapse the animation to a single
     * frame. The mime-type allowlist excludes anything but jpeg/png by
     * construction, so this proves gif specifically passes through inert.
     */
    #[RunInSeparateProcess]
    public function test_never_touches_gif(): void
    {
        Performance::configure(['modern_image_formats' => true]);
        Functions\when('wp_image_editor_supports')->justReturn(true);

        $result = Performance::preferModernImageFormat([], '/uploads/anim.gif', 'image/gif');

        $this->assertArrayNotHasKey('image/gif', $result);
    }

    /**
     * Regression test for a real fatal TypeError caught on an actual media
     * import against a live site (not a hypothetical): WP_Image_Editor's own
     * get_output_format() declares $filename nullable and some real subsize
     * call sites genuinely pass null. A `string $filename` signature under
     * strict_types=1 fatals the entire upload the instant that code path
     * runs — no unit test with a mocked string argument would ever have
     * caught this; only calling with a real null does.
     */
    #[RunInSeparateProcess]
    public function test_does_not_fatal_when_wordpress_calls_it_with_a_null_filename(): void
    {
        Performance::configure(['modern_image_formats' => true]);
        Functions\when('wp_image_editor_supports')->justReturn(true);

        $result = Performance::preferModernImageFormat([], null, 'image/jpeg');

        $this->assertSame('image/avif', $result['image/jpeg']);
    }

    #[RunInSeparateProcess]
    public function test_preserves_core_default_mappings_already_in_the_array(): void
    {
        Performance::configure(['modern_image_formats' => true]);
        Functions\when('wp_image_editor_supports')->justReturn(true);

        // Core's own default maps HEIC -> JPEG before any filter runs — our
        // filter must add to that array, never replace it wholesale.
        $result = Performance::preferModernImageFormat(
            ['image/heic' => 'image/jpeg'],
            '/uploads/photo.jpg',
            'image/jpeg'
        );

        $this->assertSame('image/jpeg', $result['image/heic']);
        $this->assertSame('image/avif', $result['image/jpeg']);
    }

    #[RunInSeparateProcess]
    public function test_modern_image_formats_does_nothing_when_disabled_via_config(): void
    {
        Performance::configure(['modern_image_formats' => false]);
        Functions\when('wp_image_editor_supports')->justReturn(true);

        $result = Performance::preferModernImageFormat([], '/uploads/photo.jpg', 'image/jpeg');

        $this->assertSame([], $result);
    }

    private function stubNoticeDependencies(bool $dismissed = false, bool $canManageOptions = true): void
    {
        Performance::configure(['build_asset_cache_htaccess' => true]);
        Functions\when('current_user_can')->justReturn($canManageOptions);
        Functions\when('get_option')->justReturn($dismissed);
        Functions\when('add_query_arg')->justReturn('http://example.test/wp-admin/');
        Functions\when('wp_nonce_url')->justReturn('http://example.test/wp-admin/?_wpnonce=x');
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('__')->returnArg();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
