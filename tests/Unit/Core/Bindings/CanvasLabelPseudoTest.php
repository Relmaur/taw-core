<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Bindings;

use PHPUnit\Framework\TestCase;

/**
 * The "Conditional" label in the editor canvas must not use ::after: the block
 * editor draws its hover/focus outline on a block's ::after, stretched over the
 * whole block, and the label's background then fills it (a blue box on hover).
 * Core's ::before is only its click overlay (`has-block-overlay`), left alone.
 */
final class CanvasLabelPseudoTest extends TestCase
{
    private function css(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Core/Bindings/Bindings.php');

        return $source;
    }

    public function test_conditional_labels_never_use_after(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\.taw-conditional[^{]*::?after/', $this->css());
    }

    public function test_conditional_labels_skip_blocks_with_core_overlay(): void
    {
        preg_match_all('/\.taw-conditional[^{]*::before/', $this->css(), $matches);

        $this->assertNotEmpty($matches[0]);
        foreach ($matches[0] as $selector) {
            $this->assertStringContainsString(':not(.has-block-overlay)', $selector);
        }
    }
}
