<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\DataPanel;

use PHPUnit\Framework\TestCase;
use TAW\Core\DataPanel\Ui;

final class UiTest extends TestCase
{
    public function test_metabox_is_the_default(): void
    {
        $this->assertSame(['ui' => 'metabox', 'source' => 'default', 'warning' => null], Ui::resolve(null, null, null));
    }

    public function test_resolution_order_fieldset_then_constant_then_settings(): void
    {
        $this->assertSame('settings', Ui::resolve(null, null, 'panel')['source']);
        $this->assertSame(['ui' => 'metabox', 'source' => 'constant', 'warning' => null], Ui::resolve(null, 'metabox', 'panel'));
        $this->assertSame(['ui' => 'panel', 'source' => 'fieldset', 'warning' => null], Ui::resolve('panel', 'metabox', 'metabox'));
        $this->assertSame(['ui' => 'metabox', 'source' => 'fieldset', 'warning' => null], Ui::resolve('metabox', 'panel', 'panel'));
    }

    public function test_an_invalid_constant_is_ignored_with_a_warning(): void
    {
        $resolved = Ui::resolve(null, 'sidebar', 'panel');

        $this->assertSame('panel', $resolved['ui']);
        $this->assertSame('settings', $resolved['source']);
        $this->assertSame('TAW_DATA_UI must be one of panel, metabox; ignoring "sidebar".', $resolved['warning']);
        $this->assertStringContainsString('ignoring boolean', (string) Ui::resolve(null, true, null)['warning']);
    }

    public function test_an_invalid_fieldset_value_falls_through(): void
    {
        $this->assertSame('panel', Ui::resolve('Panel', null, 'panel')['ui']);
    }
}
