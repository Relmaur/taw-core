<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\RateLimiter;
use TAW\Tests\TestCase;

/**
 * RateLimiter is backed by WP transients — this suite fakes get/set/delete
 * _transient with a simple in-memory array so the fixed-window logic can be
 * verified without a real WordPress install or object cache.
 */
final class RateLimiterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = [];

        Functions\when('get_transient')->alias(fn(string $key) => $this->store[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, $value) {
            $this->store[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key) {
            unset($this->store[$key]);
            return true;
        });
    }

    public function test_first_attempt_is_never_rejected(): void
    {
        $this->assertFalse(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 5, 60));
    }

    public function test_attempts_under_the_max_are_not_rejected(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 5, 60));
        }
    }

    public function test_the_attempt_that_reaches_max_is_still_allowed_but_the_next_is_not(): void
    {
        // max=3: attempts 1, 2, 3 succeed (count goes 1 -> 2 -> 3), the 4th
        // is rejected because count (3) already equals maxAttempts.
        $this->assertFalse(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 3, 60));
        $this->assertFalse(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 3, 60));
        $this->assertFalse(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 3, 60));
        $this->assertTrue(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 3, 60));
    }

    public function test_different_identities_have_independent_counters(): void
    {
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::tooManyAttempts('contact', '1.1.1.1', 3, 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('contact', '1.1.1.1', 3, 60));

        // A different IP hitting the same form/bucket should not be
        // affected by the first IP's counter.
        $this->assertFalse(RateLimiter::tooManyAttempts('contact', '2.2.2.2', 3, 60));
    }

    public function test_different_buckets_have_independent_counters(): void
    {
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::tooManyAttempts('contact_form', '1.2.3.4', 3, 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('contact_form', '1.2.3.4', 3, 60));

        // Same IP, different form id — independent bucket, independent count.
        $this->assertFalse(RateLimiter::tooManyAttempts('newsletter_form', '1.2.3.4', 3, 60));
    }

    public function test_reset_clears_the_counter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::tooManyAttempts('contact', '1.2.3.4', 3, 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 3, 60));

        RateLimiter::reset('contact', '1.2.3.4');

        $this->assertFalse(RateLimiter::tooManyAttempts('contact', '1.2.3.4', 3, 60));
    }
}
