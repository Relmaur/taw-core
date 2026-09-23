<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use Brain\Monkey\Functions;
use TAW\Core\Editing\DesignLayer;
use TAW\Core\Editing\Presets;
use TAW\Core\Editing\Resolver;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

final class DesignLayerTest extends TestCase
{
    public function test_open_changes_nothing_and_registers_nothing(): void
    {
        $layer = new DesignLayer(Resolver::resolve(null));
        $layer->register();

        $this->assertSame([], $layer->settings());
        $this->assertFalse(has_filter('wp_theme_json_data_theme'));
    }

    public function test_guided_locks_custom_colors_sizes_and_drop_cap(): void
    {
        $layer = new DesignLayer(Resolver::resolve(Schema::editing()->preset('guided')));

        $this->assertSame([
            'color'      => ['custom' => false, 'customGradient' => false],
            'typography' => ['customFontSize' => false, 'dropCap' => false],
        ], $layer->settings());
    }

    public function test_structured_locks_every_mapped_setting(): void
    {
        $layer = new DesignLayer(Resolver::resolve(Schema::editing()->preset('structured')));

        $this->assertSame([
            'color'      => ['custom' => false, 'customGradient' => false, 'customDuotone' => false],
            'typography' => ['customFontSize' => false, 'dropCap' => false, 'lineHeight' => false],
            'spacing'    => ['customSpacingSize' => false],
            'border'     => ['color' => false, 'radius' => false, 'style' => false, 'width' => false],
            'shadow'     => ['defaultPresets' => false],
        ], $layer->settings());
    }

    public function test_every_design_setting_has_a_theme_json_mapping(): void
    {
        $this->assertSame(Presets::SETTINGS['design'], array_keys(DesignLayer::THEME_JSON));
    }

    public function test_filter_merges_through_update_with_and_clears_the_cache(): void
    {
        Functions\expect('wp_clean_theme_json_cache')->once();
        $layer = new DesignLayer(Resolver::resolve(Schema::editing()->preset('guided')));
        $layer->register();

        $this->assertSame(10, has_filter('wp_theme_json_data_theme', [$layer, 'filterThemeJson']));

        $themeJson = new class () {
            /** @var array<string, mixed> */
            public array $merged = [];

            /** @param array<string, mixed> $data */
            public function update_with(array $data): self
            {
                $this->merged = $data;

                return $this;
            }
        };

        $layer->filterThemeJson($themeJson);

        $this->assertSame(3, $themeJson->merged['version']);
        $this->assertFalse($themeJson->merged['settings']['color']['custom']);
    }

    public function test_non_theme_json_values_pass_through(): void
    {
        $layer = new DesignLayer(Resolver::resolve(Schema::editing()->preset('guided')));

        $this->assertSame('x', $layer->filterThemeJson('x'));
    }
}
