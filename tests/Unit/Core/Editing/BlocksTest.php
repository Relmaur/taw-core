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

    public function test_a_block_is_bound_only_to_a_named_taw_field(): void
    {
        $bound = static fn (array $bindings): array => ['blockName' => 'core/paragraph', 'attrs' => ['metadata' => ['bindings' => $bindings]]];

        $this->assertTrue(Blocks::isBound($bound(['content' => ['source' => 'taw/field', 'args' => ['field' => 'headline']]])));
        $this->assertTrue(Blocks::isBound($bound(['url' => ['source' => 'core/post-meta', 'args' => ['key' => 'x']], 'text' => ['source' => 'taw/field', 'args' => ['field' => 'cta']]])));
        $this->assertFalse(Blocks::isBound($bound(['content' => ['source' => 'taw/field', 'args' => ['field' => '  ']]])), 'no field picked yet');
        $this->assertFalse(Blocks::isBound($bound(['content' => ['source' => 'core/post-meta', 'args' => ['key' => 'headline']]])));
        $this->assertFalse(Blocks::isBound(['blockName' => 'core/paragraph', 'attrs' => []]));
    }

    public function test_unbound_blocks_are_counted_per_name_recursively(): void
    {
        $bound = ['blockName' => 'core/paragraph', 'attrs' => ['metadata' => ['bindings' => ['content' => ['source' => 'taw/field', 'args' => ['field' => 'a']]]]], 'innerBlocks' => []];
        $plain = ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []];

        $this->assertSame(
            ['core/paragraph' => 2, 'core/group' => 1],
            Blocks::unboundCounts([$bound, $plain, ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [$bound, $plain]], ['blockName' => null, 'innerHTML' => "\n"]])
        );
    }

    public function test_allow_bound_matches_globs_but_never_custom_html_when_it_is_off(): void
    {
        $this->assertTrue(Blocks::isAllowedBound('core/paragraph', ['core/paragraph'], false));
        $this->assertTrue(Blocks::isAllowedBound('core/image', ['core/*'], true));
        $this->assertFalse(Blocks::isAllowedBound('core/html', ['core/*'], false));
        $this->assertFalse(Blocks::isAllowedBound('core/paragraph', [], true));
    }
}
