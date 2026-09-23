<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use PHPUnit\Framework\TestCase;
use TAW\Core\Editing\Blocks;

final class BlocksTest extends TestCase
{
    public function test_globs_match_everything_a_namespace_or_one_block(): void
    {
        $this->assertTrue(Blocks::matches('acme/anything', ['*']));
        $this->assertTrue(Blocks::matches('core/heading', ['core/*']));
        $this->assertTrue(Blocks::matches('core/heading', ['core/heading']));
        $this->assertFalse(Blocks::matches('core/heading', ['core/paragraph']));
        $this->assertFalse(Blocks::matches('corex/heading', ['core/*']), 'A namespace glob must not match a longer namespace.');
        $this->assertFalse(Blocks::matches('core/heading', []));
    }

    public function test_custom_html_is_refused_when_the_feature_is_off_even_under_a_wildcard(): void
    {
        $this->assertFalse(Blocks::isAllowed('core/html', ['*'], false));
        $this->assertFalse(Blocks::isAllowed('core/html', null, false));
        $this->assertTrue(Blocks::isAllowed('core/html', null, true));
        $this->assertTrue(Blocks::isAllowed('core/html', ['core/*'], true));
    }

    public function test_null_allow_means_every_block(): void
    {
        $this->assertTrue(Blocks::isAllowed('acme/widget', null, true));
    }

    public function test_names_are_collected_recursively_and_once(): void
    {
        $blocks = [
            ['blockName' => 'core/group', 'innerHTML' => '', 'innerBlocks' => [
                ['blockName' => 'core/heading', 'innerHTML' => '<h2>x</h2>', 'innerBlocks' => []],
                ['blockName' => 'core/columns', 'innerHTML' => '', 'innerBlocks' => [
                    ['blockName' => 'core/heading', 'innerHTML' => '<h3>y</h3>', 'innerBlocks' => []],
                ]],
            ]],
            ['blockName' => null, 'innerHTML' => "\n\n", 'innerBlocks' => []],
        ];

        $this->assertSame(['core/group', 'core/heading', 'core/columns'], Blocks::namesIn($blocks));
    }

    public function test_raw_html_outside_blocks_counts_as_freeform(): void
    {
        $blocks = [['blockName' => null, 'innerHTML' => '<script>alert(1)</script>', 'innerBlocks' => []]];

        $this->assertSame(['core/freeform'], Blocks::namesIn($blocks));
    }
}
