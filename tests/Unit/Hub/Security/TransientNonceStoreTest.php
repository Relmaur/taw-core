<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use Brain\Monkey\Functions;
use TAW\Hub\Security\TransientNonceStore;
use TAW\Tests\TestCase;

/**
 * Backed by WP transients — get/set _transient are faked with an in-memory
 * array (same approach as RateLimiterTest).
 */
final class TransientNonceStoreTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, int> */
    private array $ttls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = [];
        $this->ttls  = [];

        Functions\when('get_transient')->alias(fn (string $key) => $this->store[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, $value, int $ttl = 0) {
            $this->store[$key] = $value;
            $this->ttls[$key]  = $ttl;
            return true;
        });
    }

    public function test_an_unseen_nonce_reports_false(): void
    {
        $this->assertFalse((new TransientNonceStore())->seen('fresh-nonce'));
    }

    public function test_a_remembered_nonce_reports_seen(): void
    {
        $store = new TransientNonceStore();
        $store->remember('spent-nonce');

        $this->assertTrue($store->seen('spent-nonce'));
    }

    public function test_distinct_nonces_are_independent(): void
    {
        $store = new TransientNonceStore();
        $store->remember('nonce-a');

        $this->assertTrue($store->seen('nonce-a'));
        $this->assertFalse($store->seen('nonce-b'));
    }

    public function test_the_raw_nonce_is_never_used_as_the_storage_key(): void
    {
        (new TransientNonceStore())->remember('super-secret-nonce');

        foreach (array_keys($this->store) as $key) {
            $this->assertStringStartsWith('taw_hub_nonce_', $key);
            $this->assertStringNotContainsString('super-secret-nonce', $key);
        }
    }

    public function test_the_configured_ttl_is_passed_through(): void
    {
        (new TransientNonceStore(200))->remember('n');

        $this->assertSame([200], array_values($this->ttls));
    }

    public function test_the_default_ttl_covers_twice_the_default_drift_window(): void
    {
        (new TransientNonceStore())->remember('n');

        $this->assertGreaterThanOrEqual(120, array_values($this->ttls)[0]);
    }
}
