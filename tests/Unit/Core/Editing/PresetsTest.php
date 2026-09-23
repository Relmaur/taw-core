<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use PHPUnit\Framework\TestCase;
use TAW\Core\Editing\Presets;

/**
 * Sites rely on what each preset level means, so the whole table is pinned
 * here. Changing a preset should be a deliberate edit to this test (and a
 * changelog entry), never a side effect.
 */
final class PresetsTest extends TestCase
{
    /**
     * Locked settings per layer and level; everything else is allowed.
     */
    private const EXPECTED_LOCKED = [
        'site' => [
            'open'       => [],
            'guided'     => ['globalStyles'],
            'structured' => ['templates', 'templateParts', 'globalStyles', 'templateMode'],
            'locked'     => ['templates', 'templateParts', 'globalStyles', 'navigation', 'templateMode', 'siteEditor'],
        ],
        'design' => [
            'open'       => [],
            'guided'     => ['customColors', 'customGradients', 'customFontSizes', 'dropCap'],
            'structured' => ['customColors', 'customGradients', 'customFontSizes', 'dropCap', 'customSpacing', 'customLineHeight', 'border', 'shadow', 'duotone'],
            'locked'     => ['customColors', 'customGradients', 'customFontSizes', 'dropCap', 'customSpacing', 'customLineHeight', 'border', 'shadow', 'duotone'],
        ],
        'features' => [
            'open'       => [],
            'guided'     => ['codeEditor', 'customHtml', 'blockDirectory', 'openverse', 'remotePatterns'],
            'structured' => ['codeEditor', 'customHtml', 'blockDirectory', 'openverse', 'remotePatterns', 'corePatterns', 'blockLocking'],
            'locked'     => ['codeEditor', 'customHtml', 'blockDirectory', 'openverse', 'remotePatterns', 'corePatterns', 'blockLocking'],
        ],
    ];

    public function test_boolean_layers_match_the_pinned_table(): void
    {
        foreach (self::EXPECTED_LOCKED as $layer => $levels) {
            foreach ($levels as $level => $locked) {
                $settings = Presets::layer($layer, $level);

                $this->assertSame(Presets::SETTINGS[$layer], array_keys($settings), "{$layer}/{$level} keys");
                $this->assertSame($locked, array_keys(array_filter($settings, static fn (bool $allowed): bool => !$allowed)), "{$layer}/{$level}");
            }
        }
    }

    public function test_content_rules_per_level(): void
    {
        $this->assertSame(
            ['allow' => null, 'template' => null, 'lock' => false, 'newPostsOnly' => true],
            Presets::layer('content', 'open')
        );
        $this->assertSame(
            ['allow' => Presets::CURATED_BLOCKS, 'template' => null, 'lock' => false, 'newPostsOnly' => true],
            Presets::layer('content', 'guided')
        );
        $this->assertSame('contentOnly', Presets::layer('content', 'structured')['lock']);
        $this->assertSame('all', Presets::layer('content', 'locked')['lock']);
    }

    public function test_every_level_locks_at_least_what_the_level_before_it_locks(): void
    {
        foreach (array_keys(Presets::SETTINGS) as $layer) {
            $previous = [];
            foreach (Presets::LEVELS as $level) {
                $locked = array_keys(array_filter(Presets::layer($layer, $level), static fn (bool $allowed): bool => !$allowed));
                $this->assertSame([], array_diff($previous, $locked), "{$layer}/{$level} unlocks something");
                $previous = $locked;
            }
        }
    }

    public function test_curated_blocks_leave_out_raw_html_and_code(): void
    {
        foreach (['core/html', 'core/freeform', 'core/shortcode', 'core/code'] as $block) {
            $this->assertNotContains($block, Presets::CURATED_BLOCKS);
        }
    }

    public function test_unknown_layer_or_level_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Presets::layer('design', 'strict');
    }

    public function test_unknown_layer_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Presets::layer('widgets', 'open');
    }
}
