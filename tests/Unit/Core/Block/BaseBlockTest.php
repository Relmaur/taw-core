<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Block;

use Brain\Monkey\Functions;
use TAW\Core\Block\BaseBlock;
use TAW\Tests\TestCase;

/**
 * A block's script.js is allowed to `import '...css'` (e.g. a third-party
 * library's stylesheet). Vite's dev server injects that CSS itself, but a
 * production build extracts it into its own file and records it on the
 * *script's* manifest entry (`css: [...]`), not the block's dedicated
 * style.scss/style.css entry. enqueueProdAssets() must read that array off
 * the script's manifest entry and enqueue it — otherwise the CSS silently
 * never reaches the page in any real production build.
 */
final class BaseBlockTest extends TestCase
{
    private string $themeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeDir = sys_get_temp_dir() . '/taw-block-test-theme-' . getmypid();
        @mkdir($this->themeDir . '/public/build', 0777, true);

        Functions\when('get_template_directory')->justReturn($this->themeDir);
        Functions\when('get_template_directory_uri')->justReturn('https://example.test');
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_script_add_data')->justReturn(true);
    }

    protected function tearDown(): void
    {
        @unlink($this->themeDir . '/public/build/manifest.json');
        @rmdir($this->themeDir . '/public/build');
        @rmdir($this->themeDir . '/public');
        @rmdir($this->themeDir);

        parent::tearDown();
    }

    public function test_prod_assets_enqueue_css_that_the_scripts_own_manifest_entry_carries(): void
    {
        file_put_contents($this->themeDir . '/public/build/manifest.json', json_encode([
            'Blocks/TestBlock/script.js' => [
                'file'    => 'assets/script-HASH.js',
                'isEntry' => true,
                'css'     => ['assets/script-HASH2.css'],
            ],
        ]));

        // head_done = true so the CSS link prints synchronously instead of
        // being deferred behind an add_action('wp_head', ...) closure —
        // keeps the test a direct assertion on output rather than needing
        // to invoke a captured hook callback.
        Functions\when('did_action')->justReturn(1);

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with('taw-block-test-block', 'https://example.test/public/build/assets/script-HASH.js', [], null, false);

        $block = new class extends BaseBlock {};

        ob_start();
        $this->callMethod($block, 'enqueueProdAssets', 'Blocks/TestBlock', 'test-block');
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'https://example.test/public/build/assets/script-HASH2.css',
            $output,
            "The script's own CSS side-output (recorded on its manifest entry, not the block's style entry) was never enqueued."
        );
    }
}
