<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * A `tabs` config on a Metabox was accepted but never rendered — render_tabs()
 * existed with no caller, so every field showed as one flat list. These lock
 * in the wiring: tab bar + per-tab field panels, unclaimed fields kept visible,
 * and the no-tabs / all-tabs-empty cases still rendering flat.
 */
final class MetaboxTabsRenderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('esc_html')->alias(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        Functions\when('esc_attr')->alias(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        Functions\when('esc_url')->alias(static fn ($v) => (string) $v);
        Functions\when('__')->returnArg();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $tabs
     */
    private function renderMetabox(array $fields, array $tabs): string
    {
        $metabox = new Metabox([
            'id'     => 'taw_tabs_test',
            'title'  => 'Tabs test',
            'fields' => $fields,
            'tabs'   => $tabs,
        ]);

        ob_start();
        $metabox->render(new \WP_Post(['ID' => 5]));

        return (string) ob_get_clean();
    }

    /** @return array<int, array<string, mixed>> */
    private function fields(): array
    {
        return [
            ['id' => 'a_label', 'label' => 'A label', 'type' => 'text'],
            ['id' => 'b_label', 'label' => 'B label', 'type' => 'text'],
            ['id' => 'shared',  'label' => 'Shared',  'type' => 'text'],
        ];
    }

    public function test_tabs_render_a_tab_bar_and_one_panel_per_tab(): void
    {
        $html = $this->renderMetabox($this->fields(), [
            ['id' => 'a', 'label' => 'Alpha', 'fields' => ['a_label']],
            ['id' => 'b', 'label' => 'Beta',  'fields' => ['b_label']],
        ]);

        $this->assertStringContainsString('class="taw-tabbed"', $html);
        $this->assertSame(2, substr_count($html, 'class="tab-title"'));
        $this->assertStringContainsString('<p>Alpha</p>', $html);
        $this->assertStringContainsString('<p>Beta</p>', $html);
        $this->assertStringContainsString('x-show="activeTab === 0"', $html);
        $this->assertStringContainsString('x-show="activeTab === 1"', $html);
    }

    public function test_each_field_renders_inside_its_own_tab_panel(): void
    {
        $html = $this->renderMetabox($this->fields(), [
            ['id' => 'a', 'label' => 'Alpha', 'fields' => ['a_label']],
            ['id' => 'b', 'label' => 'Beta',  'fields' => ['b_label']],
        ]);

        $panel0 = $this->panel($html, 0);
        $panel1 = $this->panel($html, 1);

        $this->assertStringContainsString('name="_taw_a_label"', $panel0);
        $this->assertStringNotContainsString('name="_taw_b_label"', $panel0);
        $this->assertStringContainsString('name="_taw_b_label"', $panel1);
        $this->assertStringNotContainsString('name="_taw_a_label"', $panel1);
    }

    /**
     * save() walks every field and blanks the ones the form never posted, so a
     * field no tab lists must still be rendered — not silently dropped.
     */
    public function test_fields_no_tab_claims_stay_rendered_above_the_tabs(): void
    {
        $html = $this->renderMetabox($this->fields(), [
            ['id' => 'a', 'label' => 'Alpha', 'fields' => ['a_label']],
            ['id' => 'b', 'label' => 'Beta',  'fields' => ['b_label']],
        ]);

        $sharedAt = strpos($html, 'name="_taw_shared"');
        $tabsAt   = strpos($html, 'class="taw-tabbed"');

        $this->assertNotFalse($sharedAt);
        $this->assertNotFalse($tabsAt);
        $this->assertLessThan($tabsAt, $sharedAt);
        $this->assertSame(1, substr_count($html, 'name="_taw_shared"'));
    }

    public function test_a_tab_listing_only_unknown_fields_is_dropped_and_indexes_stay_contiguous(): void
    {
        $html = $this->renderMetabox($this->fields(), [
            ['id' => 'typo', 'label' => 'Typo',  'fields' => ['does_not_exist']],
            ['id' => 'b',    'label' => 'Beta',  'fields' => ['b_label']],
        ]);

        $this->assertSame(1, substr_count($html, 'class="tab-title"'));
        $this->assertStringNotContainsString('Typo', $html);
        // The surviving tab is index 0 so it is the one shown on load.
        $this->assertStringContainsString('x-show="activeTab === 0"', $html);
        $this->assertStringContainsString('name="_taw_b_label"', $this->panel($html, 0));
    }

    public function test_without_tabs_every_field_renders_flat(): void
    {
        $html = $this->renderMetabox($this->fields(), []);

        $this->assertStringNotContainsString('taw-tabbed', $html);
        foreach (['a_label', 'b_label', 'shared'] as $id) {
            $this->assertSame(1, substr_count($html, 'name="_taw_' . $id . '"'));
        }
    }

    public function test_when_every_tab_resolves_empty_it_falls_back_to_flat(): void
    {
        $html = $this->renderMetabox($this->fields(), [
            ['id' => 'typo', 'label' => 'Typo', 'fields' => ['does_not_exist']],
        ]);

        $this->assertStringNotContainsString('taw-tabbed', $html);
        $this->assertSame(1, substr_count($html, 'name="_taw_a_label"'));
    }

    /** Return the markup of the Nth tab panel (up to the next panel or the end). */
    private function panel(string $html, int $index): string
    {
        $start = strpos($html, 'tab-content-' . $index);
        $this->assertNotFalse($start, "panel {$index} not found");

        $next = strpos($html, 'tab-content-' . ($index + 1), $start);

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }
}
