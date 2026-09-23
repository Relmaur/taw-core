<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Editing;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TAW\Core\Boot;
use TAW\Core\Editing\Editing;
use TAW\Core\Schema\Registry;
use TAW\Core\Schema\Schema;
use TAW\Tests\TestCase;

/**
 * Boot::editing() (ADR-0005 § 7): separate from Boot::data(), idempotent,
 * and switchable off with TAW_EDITING_OFF.
 */
final class BootEditingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Boot::resetForTests();
        Registry::resetForTests();
    }

    protected function tearDown(): void
    {
        Boot::resetForTests();
        Registry::resetForTests();
        parent::tearDown();
    }

    public function test_editing_boots_the_data_layer_and_schedules_the_policy(): void
    {
        Boot::editing();

        $this->assertTrue(Boot::isEditingBooted());
        $this->assertTrue(Boot::isDataBooted());
        $this->assertSame(Editing::APPLY_PRIORITY, has_action('init', [Editing::class, 'apply']));
    }

    public function test_data_alone_never_schedules_editing(): void
    {
        Boot::data();

        $this->assertFalse(has_action('init', [Editing::class, 'apply']));
        $this->assertFalse(Boot::isEditingBooted());
    }

    public function test_editing_is_idempotent(): void
    {
        Boot::editing();
        Boot::editing();

        $this->assertSame(Editing::APPLY_PRIORITY, has_action('init', [Editing::class, 'apply']));
        $this->assertCount(1, $GLOBALS['wp_filter']['init'][Editing::APPLY_PRIORITY] ?? [null], 'Registered once.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_taw_editing_off_turns_it_into_a_no_op(): void
    {
        define('TAW_EDITING_OFF', true);

        Boot::editing();

        $this->assertTrue(Boot::isEditingBooted());
        $this->assertFalse(Boot::isDataBooted(), 'Nothing is booted, not even the data layer.');
        $this->assertFalse(has_action('init', [Editing::class, 'apply']));
    }

    public function test_apply_wires_bypass_and_both_layers(): void
    {
        Registry::instance()->add(Schema::editing()->preset('guided'));
        Functions\when('current_user_can')->justReturn(false);

        Editing::apply();

        $this->assertNotFalse(has_filter('user_has_cap'));
        $this->assertNotFalse(has_filter('allowed_block_types_all'));
        $this->assertNotFalse(has_filter('should_load_remote_block_patterns'));
        $this->assertNotFalse(has_action('enqueue_block_editor_assets'));
        $this->assertSame('guided', Editing::policy()->preset);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_apply_reports_a_bad_preset_constant(): void
    {
        define('TAW_EDITING_PRESET', 'strict');
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('_doing_it_wrong')->once()->with(\Mockery::any(), \Mockery::pattern('/TAW_EDITING_PRESET must be one of/'), \Mockery::any());

        Editing::apply();

        $this->assertSame('open', Editing::policy()->preset);
    }
}
