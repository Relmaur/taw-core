<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use Brain\Monkey\Functions;
use Symfony\Component\Console\Tester\CommandTester;
use TAW\CLI\ContentImportCommand;
use TAW\Tests\TestCase;

/**
 * Regression cover for Bug C — `content:import` in dry-run mode emitted
 * nothing at all (no plan table, no "no changes" line, no "dry run" note)
 * while exiting 0. The command must always write *something* to stdout.
 */
final class ContentImportCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-content-import-' . getmypid() . '-' . uniqid();
        @mkdir($this->tmp . '/wp/wp-content/themes/taw-theme', 0777, true);
        file_put_contents($this->tmp . '/wp/wp-load.php', '<?php');

        // Everything Importer::plan() reaches once WordPress is "loaded".
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_option')->justReturn(null);
        Functions\when('get_term_by')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\when('get_page_template_slug')->justReturn('');
        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
    }

    protected function tearDown(): void
    {
        $it = @new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it ?: [] as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function writeSnapshot(array $data): string
    {
        $path = $this->tmp . '/snap.json';
        file_put_contents($path, (string) json_encode($data));
        return $path;
    }

    public function test_dry_run_against_a_new_post_prints_a_plan_table_and_the_dry_run_note(): void
    {
        $snap = $this->writeSnapshot([
            'meta'    => ['schema' => '1.0'],
            'options' => [],
            'terms'   => [],
            'posts'   => [
                ['type' => 'page', 'slug' => 'about', 'title' => 'About', 'content' => 'Hi', 'fields' => []],
            ],
        ]);

        $themeDir = $this->tmp . '/wp/wp-content/themes/taw-theme';
        $tester = new CommandTester(new ContentImportCommand($themeDir));
        $exit = $tester->execute(['file' => $snap]);

        $display = $tester->getDisplay();

        $this->assertSame(0, $exit);
        $this->assertNotSame('', trim($display), 'content:import must never emit nothing');
        $this->assertStringContainsString('page:about', $display);
        $this->assertStringContainsString('create', $display);
        $this->assertStringContainsString('Dry run', $display);
    }

    public function test_dry_run_with_no_differences_says_so(): void
    {
        $snap = $this->writeSnapshot([
            'meta' => ['schema' => '1.0'], 'options' => [], 'terms' => [], 'posts' => [],
        ]);

        $themeDir = $this->tmp . '/wp/wp-content/themes/taw-theme';
        $tester = new CommandTester(new ContentImportCommand($themeDir));
        $exit = $tester->execute(['file' => $snap]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No changes', $tester->getDisplay());
        $this->assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function test_invalid_json_is_reported(): void
    {
        $path = $this->tmp . '/bad.json';
        file_put_contents($path, 'not json');

        $themeDir = $this->tmp . '/wp/wp-content/themes/taw-theme';
        $tester = new CommandTester(new ContentImportCommand($themeDir));
        $exit = $tester->execute(['file' => $path]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not valid JSON', $tester->getDisplay());
    }
}
