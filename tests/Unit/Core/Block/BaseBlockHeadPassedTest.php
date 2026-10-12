<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Block;

use Brain\Monkey\Functions;
use TAW\Core\Block\BaseBlock;
use TAW\Tests\TestCase;

require_once __DIR__ . '/Doubles/WpHook.php';

/**
 * Issue #190: a BlockRegistry::queue()'d block enqueues on wp_enqueue_scripts,
 * which WordPress fires from wp_head (priority 1). did_action('wp_head') is
 * already 1 there, so a $critical block printed a render-blocking <link>
 * instead of scheduling its inline <style> for wp_head 11.
 */
final class BaseBlockHeadPassedTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wp_filter']);
        parent::tearDown();
    }

    private function headPassed(int $priority): bool
    {
        return (new \ReflectionMethod(BaseBlock::class, 'headPassed'))->invoke(null, $priority);
    }

    private function wpHeadRunningAt(int $priority): void
    {
        Functions\when('did_action')->justReturn(1);
        Functions\when('doing_action')->justReturn(true);
        $hook = new \WP_Hook();
        $hook->priority = $priority;
        $GLOBALS['wp_filter']['wp_head'] = $hook;
    }

    public function test_before_wp_head_everything_can_still_be_scheduled(): void
    {
        Functions\when('did_action')->justReturn(0);

        $this->assertFalse($this->headPassed(11));
    }

    public function test_from_wp_enqueue_scripts_inside_wp_head_inline_css_is_still_scheduled(): void
    {
        $this->wpHeadRunningAt(1); // wp_enqueue_scripts

        $this->assertFalse($this->headPassed(8), 'dev styles still print at wp_print_styles (8)');
        $this->assertFalse($this->headPassed(11), 'critical CSS inlines at 11');
        $this->assertFalse($this->headPassed(50), 'async links print at 50');
    }

    public function test_later_in_wp_head_only_later_priorities_can_run(): void
    {
        $this->wpHeadRunningAt(20);

        $this->assertTrue($this->headPassed(11));
        $this->assertFalse($this->headPassed(50));
    }

    public function test_after_wp_head_nothing_can_be_scheduled(): void
    {
        Functions\when('did_action')->justReturn(1);
        Functions\when('doing_action')->justReturn(false);

        $this->assertTrue($this->headPassed(50));
    }
}
