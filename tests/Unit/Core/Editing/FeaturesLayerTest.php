<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use Brain\Monkey\Functions;
use TAW\Core\Editing\Bypass;
use TAW\Core\Editing\FeaturesLayer;
use TAW\Core\Editing\Resolver;
use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

final class FeaturesLayerTest extends TestCase
{
    protected function tearDown(): void
    {
        // expect()->once()/never() are real assertions, verified by Mockery at
        // teardown (same as SchemaTestCase).
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        parent::tearDown();
    }

    private function layer(EditingPolicy $definition, bool $bypass = false): FeaturesLayer
    {
        Functions\when('current_user_can')->justReturn($bypass);

        return new FeaturesLayer(Resolver::resolve($definition), new Bypass('taw_unlock_editing', []));
    }

    public function test_open_changes_no_editor_setting(): void
    {
        $this->assertSame(['codeEditingEnabled' => true], $this->layer(Schema::editing())->editorSettings(['codeEditingEnabled' => true]));
    }

    public function test_guided_turns_off_code_editor_and_openverse_but_keeps_block_locking(): void
    {
        $settings = $this->layer(Schema::editing()->preset('guided'))->editorSettings([]);

        $this->assertSame(['codeEditingEnabled' => false, 'enableOpenverseMediaCategory' => false], $settings);
    }

    public function test_structured_also_hides_the_lock_ui(): void
    {
        $settings = $this->layer(Schema::editing()->preset('structured'))->editorSettings([]);

        $this->assertFalse($settings['canLockBlocks']);
        $this->assertFalse($settings['codeEditingEnabled']);
    }

    public function test_single_features_can_be_turned_back_on(): void
    {
        $settings = $this->layer(Schema::editing()->preset('structured')->layer('features', ['codeEditor' => true]))->editorSettings([]);

        $this->assertArrayNotHasKey('codeEditingEnabled', $settings);
    }

    public function test_bypass_users_keep_every_feature(): void
    {
        $layer = $this->layer(Schema::editing()->preset('locked'), true);

        $this->assertSame([], $layer->editorSettings([]));
        $this->assertTrue($layer->loadRemotePatterns(true));
    }

    public function test_remote_patterns(): void
    {
        $this->assertFalse($this->layer(Schema::editing()->preset('guided'))->loadRemotePatterns(true));
        $this->assertTrue($this->layer(Schema::editing())->loadRemotePatterns(true));
        $this->assertFalse($this->layer(Schema::editing())->loadRemotePatterns(false), 'Another filter\'s "no" stands.');
    }

    public function test_block_directory_is_removed_only_when_locked(): void
    {
        Functions\expect('remove_action')->once()->with('enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets');
        $this->layer(Schema::editing()->preset('guided'))->maybeRemoveBlockDirectory();
    }

    public function test_block_directory_stays_when_open(): void
    {
        Functions\expect('remove_action')->never();
        $this->layer(Schema::editing())->maybeRemoveBlockDirectory();
        $this->layer(Schema::editing()->preset('locked'), true)->maybeRemoveBlockDirectory();
    }

    public function test_core_patterns_support_is_removed_at_register_when_locked(): void
    {
        Functions\expect('remove_theme_support')->once()->with('core-block-patterns');
        $layer = $this->layer(Schema::editing()->preset('structured'));
        $layer->register();

        $this->assertSame(20, has_filter('block_editor_settings_all', [$layer, 'editorSettings']));
        $this->assertSame(20, has_filter('should_load_remote_block_patterns', [$layer, 'loadRemotePatterns']));
        $this->assertSame(1, has_action('enqueue_block_editor_assets', [$layer, 'maybeRemoveBlockDirectory']));
    }

    public function test_core_patterns_stay_for_guided_and_for_bypass_users(): void
    {
        Functions\expect('remove_theme_support')->never();
        $this->layer(Schema::editing()->preset('guided'))->register();
        $this->layer(Schema::editing()->preset('locked'), true)->register();
    }
}
