<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Telemetry;

use TAW\Hub\Telemetry\AssetInventory;
use TAW\Tests\TestCase;

final class AssetInventoryTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'resources/js/app.js' => [
                'file'    => 'assets/app.a1b2c3.js',
                'isEntry' => true,
                'css'     => ['assets/app.d4e5f6.css'],
            ],
            'resources/scss/critical.scss' => [
                'file'    => 'assets/critical.99999.css',
                'isEntry' => true,
            ],
            '_shared.chunk.js' => [
                'file' => 'assets/shared.00000.js',
            ],
        ];
    }

    public function test_it_lists_only_entry_chunks_sorted_by_source(): void
    {
        $report = AssetInventory::fromManifest($this->manifest());

        $this->assertSame(2, $report['entry_count']);
        $this->assertSame(
            ['resources/js/app.js', 'resources/scss/critical.scss'],
            array_column($report['entries'], 'src'),
        );
        $this->assertSame('assets/app.a1b2c3.js', $report['entries'][0]['file']);
    }

    public function test_the_manifest_hash_is_order_independent(): void
    {
        $forward  = $this->manifest();
        $reversed = array_reverse($forward, preserve_keys: true);

        $this->assertSame(
            AssetInventory::fromManifest($forward)['manifest_hash'],
            AssetInventory::fromManifest($reversed)['manifest_hash'],
        );
    }

    public function test_the_manifest_hash_changes_with_content(): void
    {
        $changed = $this->manifest();
        $changed['resources/js/app.js']['file'] = 'assets/app.DIFFERENT.js';

        $this->assertNotSame(
            AssetInventory::fromManifest($this->manifest())['manifest_hash'],
            AssetInventory::fromManifest($changed)['manifest_hash'],
        );
    }

    public function test_an_empty_manifest_has_no_hash_and_no_entries(): void
    {
        $report = AssetInventory::fromManifest([]);

        $this->assertSame(0, $report['entry_count']);
        $this->assertNull($report['manifest_hash']);
        $this->assertSame([], $report['entries']);
    }
}
