<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub;

use Brain\Monkey\Functions;
use TAW\Hub\HubConfig;
use TAW\Tests\TestCase;

/**
 * TAW_HUB_ENABLED is a real constant and process-global once set — like the
 * Turnstile suite, this class only ever exercises the state it can create
 * without needing to un-define anything. The "disabled" default is the
 * common case and is covered wherever HubConfig isn't defined.
 */
final class HubConfigTest extends TestCase
{
    public function test_disabled_by_default(): void
    {
        // TAW_HUB_ENABLED is not defined in the unit-test process.
        $this->assertFalse(HubConfig::enabled());
    }

    public function test_drift_defaults_to_60_and_is_clamped(): void
    {
        Functions\when('apply_filters')->returnArg(2);
        $this->assertSame(60, HubConfig::maxTimestampDrift());

        Functions\when('apply_filters')->justReturn(5000);
        $this->assertSame(300, HubConfig::maxTimestampDrift());

        Functions\when('apply_filters')->justReturn(1);
        $this->assertSame(5, HubConfig::maxTimestampDrift());

        Functions\when('apply_filters')->justReturn(90);
        $this->assertSame(90, HubConfig::maxTimestampDrift());
    }
}
