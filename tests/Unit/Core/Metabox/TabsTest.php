<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Tabs;
use TAW\Tests\TestCase;

/**
 * Metabox and options-page tabs are a keyboard-operable tab list
 * (v1.59.2, found by the fleet upgrade: they were clickable divs only).
 */
final class TabsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_attr')->alias(static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES));
    }

    public function test_ids_are_safe_and_tie_tabs_to_panels(): void
    {
        $base = Tabs::idBase('hero box.1');

        $this->assertSame('taw-tabs-hero-box-1', $base);
        $this->assertSame('role="tablist"', Tabs::listAttributes());
        $this->assertStringContainsString('id="taw-tabs-hero-box-1-tab-2" aria-controls="taw-tabs-hero-box-1-panel-2"', Tabs::tabAttributes($base, 2, 3));
        $this->assertSame('role="tabpanel" id="taw-tabs-hero-box-1-panel-2" aria-labelledby="taw-tabs-hero-box-1-tab-2"', Tabs::panelAttributes($base, 2));
    }

    public function test_only_the_active_tab_is_focusable_and_keys_select_and_move(): void
    {
        $tab = Tabs::tabAttributes('taw-tabs-x', 0, 3);

        $this->assertStringContainsString('role="tab"', $tab);
        $this->assertStringContainsString(':aria-selected="activeTab === 0 ? \'true\' : \'false\'"', $tab);
        $this->assertStringContainsString(':tabindex="activeTab === 0 ? 0 : -1"', $tab);
        $this->assertStringContainsString('@keydown.enter.prevent="activeTab = 0"', $tab);
        $this->assertStringContainsString('@keydown.space.prevent="activeTab = 0"', $tab);
        $this->assertStringContainsString('@keydown.arrow-right.prevent="activeTab = (0 + 1) % 3;', $tab);
        $this->assertStringContainsString('@keydown.arrow-left.prevent="activeTab = (0 + 2) % 3;', $tab, 'left from the first wraps to the last');
        $this->assertStringContainsString('@keydown.end.prevent="activeTab = 2;', $tab);
        $this->assertStringContainsString('$el.parentElement.children[activeTab].focus()', $tab);
    }
}
