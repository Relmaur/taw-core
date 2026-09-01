<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Log;

use TAW\Core\Log\Level;
use TAW\Tests\TestCase;

final class LevelTest extends TestCase
{
    public function test_recognises_every_supported_level(): void
    {
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
            $this->assertTrue(Level::isValid($level), $level);
        }
    }

    public function test_rejects_unknown_or_dropped_levels(): void
    {
        $this->assertFalse(Level::isValid('alert'));
        $this->assertFalse(Level::isValid('emergency'));
        $this->assertFalse(Level::isValid('ERROR'));
        $this->assertFalse(Level::isValid(''));
    }

    public function test_all_is_ordered_low_to_high(): void
    {
        $this->assertSame(
            ['debug', 'info', 'notice', 'warning', 'error', 'critical'],
            Level::ALL,
        );
    }
}
