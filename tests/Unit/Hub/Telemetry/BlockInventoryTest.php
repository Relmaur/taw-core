<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Telemetry;

use TAW\Hub\Telemetry\BlockInventory;
use TAW\Tests\TestCase;

final class BlockInventoryTest extends TestCase
{
    /**
     * @param array<string, string> $idToVariation
     * @return array<string, object>
     */
    private function fakeBlocks(array $idToVariation): array
    {
        $blocks = [];
        foreach ($idToVariation as $id => $variation) {
            $blocks[$id] = new class ($variation) {
                public function __construct(private string $variation)
                {
                }
                public function getVariation(): string
                {
                    return $this->variation;
                }
            };
        }

        /** @var array<string, \TAW\Core\Block\MetaBlock> $blocks */
        return $blocks;
    }

    public function test_it_reports_count_and_a_sorted_block_list(): void
    {
        $report = BlockInventory::collect($this->fakeBlocks([
            'hero'    => '',
            'cta'     => 'wide',
            'gallery' => '',
        ]));

        $this->assertSame(3, $report['count']);
        $this->assertSame(['cta', 'gallery', 'hero'], array_column($report['blocks'], 'id'));
        $this->assertSame('wide', $report['blocks'][0]['variation']);
    }

    public function test_the_hash_is_stable_regardless_of_registration_order(): void
    {
        $a = BlockInventory::collect($this->fakeBlocks(['hero' => '', 'cta' => '', 'faq' => '']));
        $b = BlockInventory::collect($this->fakeBlocks(['faq' => '', 'hero' => '', 'cta' => '']));

        $this->assertSame($a['hash'], $b['hash']);
    }

    public function test_the_hash_changes_when_the_block_set_changes(): void
    {
        $a = BlockInventory::collect($this->fakeBlocks(['hero' => '', 'cta' => '']));
        $b = BlockInventory::collect($this->fakeBlocks(['hero' => '', 'cta' => '', 'faq' => '']));

        $this->assertNotSame($a['hash'], $b['hash']);
    }

    public function test_an_empty_registry_is_handled(): void
    {
        $report = BlockInventory::collect([]);

        $this->assertSame(0, $report['count']);
        $this->assertSame([], $report['blocks']);
        $this->assertSame(hash('sha256', ''), $report['hash']);
    }
}
