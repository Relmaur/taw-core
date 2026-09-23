<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use TAW\Core\Boot;
use TAW\Core\Rest\FieldMetaRegistrar;
use TAW\Tests\TestCase;

/**
 * Boot::data() — the data-only entry point (ADR-0003).
 *
 * The contract: it wires exactly the data layer (content import/export
 * screen, the content export REST route, REST-registered field meta),
 * nothing presentational, and only once per request however many
 * consumers ask for it.
 */
final class BootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Boot::resetForTests();
    }

    protected function tearDown(): void
    {
        Boot::resetForTests();
        parent::tearDown();
    }

    public function test_data_registers_the_data_layer_hooks(): void
    {
        Boot::data();

        // Tools → TAW Data screen.
        $this->assertTrue(has_action('admin_menu'));
        $this->assertTrue(has_action('admin_post_taw_content_export'));
        // GET taw/v1/content/export.
        $this->assertTrue(has_action('rest_api_init'));
        // REST-registered field meta, at the priority that runs after
        // fieldsets and MetaBlock metaboxes have been compiled (init:20).
        $this->assertSame(20, has_action('init', [FieldMetaRegistrar::class, 'registerPostMeta']));
        $this->assertTrue(Boot::isDataBooted());
    }

    public function test_data_registers_nothing_presentational(): void
    {
        Boot::data();

        // The classic-theme toolkit's signature hooks must stay untouched —
        // this is what lets a block theme use the data layer safely.
        $this->assertFalse(has_action('wp_enqueue_scripts'));
        $this->assertFalse(has_action('wp_head'));
        $this->assertFalse(has_action('after_setup_theme'));
    }

    public function test_data_is_idempotent(): void
    {
        Actions\expectAdded('rest_api_init')->once();
        Actions\expectAdded('admin_menu')->once();

        Boot::data();
        Boot::data();
        Boot::data();

        // The once() expectations above are verified by Mockery at tearDown;
        // count them so PHPUnit doesn't flag this test as assertion-free.
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_boot_class_loads_without_wordpress(): void
    {
        // Boot deliberately has no `if (!defined('ABSPATH')) exit;` guard, so
        // bin/taw commands can autoload it before WordPress boots (same rule
        // and same subprocess check as Content\PreBootAutoloadTest).
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; var_export(\\TAW\\Core\\Boot::isDataBooted()); echo "|LOADED";',
            var_export($autoload, true)
        );

        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exit);
        $joined = implode("\n", $output);

        $this->assertSame(0, $exit, "Subprocess exited non-zero:\n{$joined}");
        $this->assertSame('false|LOADED', $joined);
    }

    public function test_the_rest_meta_opt_out_filter_still_applies(): void
    {
        Filters\expectApplied('taw_register_meta_in_rest')->once()->andReturn(false);

        Boot::data();

        $this->assertFalse(has_action('init', [FieldMetaRegistrar::class, 'registerPostMeta']));
        // The rest of the data layer still boots.
        $this->assertTrue(has_action('rest_api_init'));
    }
}
