<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use TAW\CLI\SyncCommand;

/**
 * Tier 1 `file` entries with `keep_edited` (resources/update-manifest.json):
 * sync replaces a site's copy only when it is a version the scaffold shipped;
 * a copy with the site's own changes is kept (issue #182: tests/TestCase.php
 * helpers were wiped on every sync).
 */
final class SyncKeepEditedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-keep-edited-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_only_a_copy_with_the_sites_own_changes_is_kept(): void
    {
        $old = "<?php // scaffold v1\n";
        $known = [hash('sha256', $old)];
        file_put_contents($this->dir . '/canonical.php', "<?php // scaffold v2\n");

        file_put_contents($this->dir . '/site.php', $old);
        $this->assertFalse(SyncCommand::isEditedCopy($this->dir . '/site.php', $this->dir . '/canonical.php', $known), 'an unedited old copy is replaced');

        file_put_contents($this->dir . '/site.php', "<?php // scaffold v2\n");
        $this->assertFalse(SyncCommand::isEditedCopy($this->dir . '/site.php', $this->dir . '/canonical.php', $known), 'the current copy is not "edited"');

        $this->assertFalse(SyncCommand::isEditedCopy($this->dir . '/missing.php', $this->dir . '/canonical.php', $known), 'a missing copy is created');

        file_put_contents($this->dir . '/site.php', $old . "trait SiteHelpers {}\n");
        $this->assertTrue(SyncCommand::isEditedCopy($this->dir . '/site.php', $this->dir . '/canonical.php', $known), "the site's own helpers are kept");
    }

    public function test_the_manifest_knows_the_scaffolds_current_test_case(): void
    {
        $scaffold = dirname(__DIR__, 4) . '/taw-theme/tests/TestCase.php';
        if (!is_file($scaffold)) {
            $this->markTestSkipped('needs the umbrella checkout of taw-theme next to taw-core');
        }
        $manifest = (new SyncCommand($this->dir))->loadManifest();
        $entry = array_values(array_filter($manifest['tier1'] ?? [], static fn (array $e): bool => $e['path'] === 'tests/TestCase.php'))[0] ?? [];

        $this->assertContains(
            hash_file('sha256', $scaffold),
            $entry['keep_edited'] ?? [],
            "taw-theme's tests/TestCase.php changed: add its sha256 to keep_edited in resources/update-manifest.json"
        );
    }
}
