<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Assets;

use TAW\Hub\Assets\ExtractionLimits;
use TAW\Hub\Assets\PayloadException;
use TAW\Hub\Assets\PayloadExtractor;
use TAW\Tests\TestCase;

final class PayloadExtractorTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/taw-extract-' . getmypid() . '-' . uniqid();
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $files  entry name => contents
     * @param list<array{name: string, mode: int}> $rawEntries entries added with explicit unix mode
     */
    private function makeZip(array $files, array $rawEntries = []): string
    {
        $path = $this->tmp . '/payload-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        foreach ($rawEntries as $entry) {
            $zip->addFromString($entry['name'], 'x');
            $zip->setExternalAttributesName($entry['name'], \ZipArchive::OPSYS_UNIX, $entry['mode'] << 16);
        }
        $zip->close();

        return $path;
    }

    private function extractor(?ExtractionLimits $limits = null): PayloadExtractor
    {
        return new PayloadExtractor($limits ?? new ExtractionLimits());
    }

    private function assertRejected(string $reason, string $zip): void
    {
        try {
            $this->extractor()->extract($zip, $this->tmp . '/out-' . uniqid());
            $this->fail("Expected rejection: {$reason}");
        } catch (PayloadException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function test_it_extracts_allowed_files_and_returns_a_sorted_list(): void
    {
        $zip = $this->makeZip([
            'assets/app.js'        => 'console.log(1)',
            'assets/app.css'       => 'body{}',
            'manifest.json'        => '{}',
            'fonts/inter.woff2'    => 'FONT',
        ]);
        $dest = $this->tmp . '/out';

        $files = $this->extractor()->extract($zip, $dest);

        $this->assertSame(
            ['assets/app.css', 'assets/app.js', 'fonts/inter.woff2', 'manifest.json'],
            $files,
        );
        $this->assertFileExists($dest . '/assets/app.js');
        $this->assertSame('body{}', file_get_contents($dest . '/assets/app.css'));
    }

    public function test_parent_directory_traversal_is_rejected(): void
    {
        $this->assertRejected(PayloadException::PATH_TRAVERSAL, $this->makeZip(['../evil.js' => 'x']));
        $this->assertRejected(PayloadException::PATH_TRAVERSAL, $this->makeZip(['a/../../evil.js' => 'x']));
    }

    public function test_absolute_paths_are_rejected(): void
    {
        $this->assertRejected(PayloadException::PATH_TRAVERSAL, $this->makeZip(['/etc/cron.d/x.js' => 'x']));
    }

    public function test_backslash_paths_are_rejected(): void
    {
        $this->assertRejected(PayloadException::PATH_TRAVERSAL, $this->makeZip(['a\\b.js' => 'x']));
    }

    public function test_disallowed_extensions_are_rejected(): void
    {
        $this->assertRejected(PayloadException::DISALLOWED_FILE, $this->makeZip(['shell.php' => '<?php']));
        $this->assertRejected(PayloadException::DISALLOWED_FILE, $this->makeZip(['x.phtml' => 'x']));
        $this->assertRejected(PayloadException::DISALLOWED_FILE, $this->makeZip(['noext' => 'x']));
    }

    public function test_symlink_entries_are_rejected(): void
    {
        $zip = $this->makeZip([], [['name' => 'link.js', 'mode' => 0xA1FF]]);
        $this->assertRejected(PayloadException::SYMLINK_ENTRY, $zip);
    }

    public function test_an_oversized_archive_is_rejected_before_opening(): void
    {
        // Incompressible content so the .zip itself is genuinely large.
        $zip = $this->makeZip(['a.js' => bin2hex(random_bytes(4096))]);
        $limits = new ExtractionLimits(maxArchiveBytes: 1024);

        try {
            (new PayloadExtractor($limits))->extract($zip, $this->tmp . '/out');
            $this->fail('Expected rejection.');
        } catch (PayloadException $e) {
            $this->assertSame(PayloadException::ARCHIVE_TOO_LARGE, $e->reason());
        }
    }

    public function test_a_compression_bomb_entry_is_rejected(): void
    {
        $this->assertRejected(
            PayloadException::COMPRESSION_BOMB,
            $this->makeZip(['bomb.js' => str_repeat('A', 200_000)]),
        );
    }

    public function test_too_many_entries_is_rejected(): void
    {
        $files = [];
        for ($i = 0; $i < 10; $i++) {
            $files["f{$i}.js"] = 'x';
        }
        try {
            (new PayloadExtractor(new ExtractionLimits(maxEntries: 5)))
                ->extract($this->makeZip($files), $this->tmp . '/out');
            $this->fail('Expected rejection.');
        } catch (PayloadException $e) {
            $this->assertSame(PayloadException::TOO_MANY_ENTRIES, $e->reason());
        }
    }

    public function test_an_oversized_entry_is_rejected(): void
    {
        try {
            (new PayloadExtractor(new ExtractionLimits(maxFileBytes: 8)))
                ->extract($this->makeZip(['big.js' => str_repeat('x', 4096)]), $this->tmp . '/out');
            $this->fail('Expected rejection.');
        } catch (PayloadException $e) {
            $this->assertSame(PayloadException::ENTRY_TOO_LARGE, $e->reason());
        }
    }

    public function test_a_missing_archive_is_rejected(): void
    {
        $this->assertRejected(PayloadException::ARCHIVE_UNREADABLE, $this->tmp . '/nope.zip');
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($path);
    }
}
