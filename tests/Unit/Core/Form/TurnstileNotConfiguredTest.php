<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TAW\Core\Form\Turnstile;
use TAW\Tests\TestCase;

/**
 * Covers Turnstile's "keys not configured" state — split into its own
 * class, run in a separate PHP process, because TAW_TURNSTILE_SITE_KEY/
 * SECRET_KEY are real define()'d constants: once set (as TurnstileTest
 * does deliberately, to test the configured state), they can't be
 * un-defined for a later test in the same process. Running this class in
 * isolation guarantees these constants start undefined regardless of
 * test execution order across the suite.
 */
final class TurnstileNotConfiguredTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_is_configured_is_false_when_constants_are_undefined(): void
    {
        $this->assertFalse(Turnstile::isConfigured());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_site_key_is_null_when_not_configured(): void
    {
        $this->assertNull(Turnstile::siteKey());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_verify_fails_closed_when_not_configured(): void
    {
        $this->assertFalse(Turnstile::verify('any-token', '127.0.0.1'));
    }
}
