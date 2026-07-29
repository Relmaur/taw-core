<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Icons;

use TAW\Core\Icons\Lucide;
use TAW\Tests\TestCase;

/**
 * Lucide::render() reads directly from the vendored resources/icons/lucide/
 * files (see IconsSyncCommand) — no WordPress functions involved, so these
 * tests exercise the real vendored 'house' icon rather than mocking the
 * filesystem.
 */
final class LucideRenderTest extends TestCase
{
    public function test_renders_a_known_icon_as_inline_svg(): void
    {
        $svg = Lucide::render('house');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('stroke="currentColor"', $svg);
    }

    public function test_merges_a_class_onto_the_svg_root(): void
    {
        $svg = Lucide::render('house', ['class' => 'w-5 h-5']);

        $this->assertMatchesRegularExpression('/<svg[^>]*class="w-5 h-5"/', $svg);
    }

    public function test_unknown_icon_name_returns_empty_string(): void
    {
        $this->assertSame('', Lucide::render('this-icon-does-not-exist'));
    }

    public function test_path_traversal_attempt_is_rejected(): void
    {
        $this->assertSame('', Lucide::render('../../composer'));
        $this->assertSame('', Lucide::render('house/../../composer'));
    }
}
