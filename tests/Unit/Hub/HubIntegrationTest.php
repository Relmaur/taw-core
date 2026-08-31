<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub;

use Brain\Monkey\Functions;
use TAW\Hub\HubIntegration;
use TAW\Tests\TestCase;

/**
 * TAW_HUB_ENABLED is not defined in the unit-test process, so the only state
 * exercisable here is "disabled" — which is exactly the one that matters for
 * every site that never touches the Hub: Theme::boot() calls
 * HubIntegration::init() unconditionally, and it must do nothing.
 */
final class HubIntegrationTest extends TestCase
{
    public function test_init_is_completely_inert_when_disabled(): void
    {
        Functions\expect('add_action')->never();

        HubIntegration::init();

        $this->addToAssertionCount(1);
    }
}
