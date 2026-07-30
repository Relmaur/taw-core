<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Support;

use Brain\Monkey\Functions;
use TAW\Support\Alpine;
use TAW\Tests\TestCase;

/**
 * Alpine's own bundle calls queueMicrotask(() => Alpine.start()) the
 * moment it executes, and the HTML spec runs a microtask checkpoint right
 * after each classic <script> finishes — before the next sibling script
 * even runs. Any dependent script (e.g. a component registering itself via
 * Alpine.data() inside an alpine:init listener) enqueued to load *after*
 * 'alpinejs' therefore loses the race unless Alpine's own script execution
 * is deferred. This locks in the 'defer' strategy that fixes it.
 */
final class AlpineTest extends TestCase
{
    public function test_enqueues_alpine_with_defer_strategy(): void
    {
        Functions\when('get_template_directory')->justReturn(\TAW\Helpers\Framework::path());
        Functions\when('get_template_directory_uri')->justReturn('https://example.test/wp-content/themes/my-theme');

        Functions\expect('wp_enqueue_script')
            ->once()
            ->with(
                'alpinejs',
                \Mockery::on(function (string $src): bool {
                    $this->assertStringEndsWith('alpine.min.js', $src);
                    return true;
                }),
                [],
                \Mockery::on(function ($version): bool {
                    $this->assertIsInt($version);
                    return true;
                }),
                true
            );

        Functions\expect('wp_script_add_data')
            ->once()
            ->with('alpinejs', 'strategy', 'defer');

        Alpine::enqueue();
    }
}
