<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Security;

use Brain\Monkey\Functions;
use TAW\Core\Security\Hardening;
use TAW\Tests\TestCase;

/**
 * Hardening::hideUsersEndpoint() registers a single `rest_endpoints`
 * filter; filterUsersEndpoints() is that filter's callback and holds all
 * the actual logic. These tests lock in the three things that matter:
 * anonymous requests lose the users collection + single-user route,
 * logged-in requests are untouched, and `/wp/v2/users/me` survives in
 * every case (the mobile apps / block editor depend on it).
 */
final class HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Static guard persists across tests in the same process — reset it
        // so each test sees a fresh "filter not yet added" state.
        $prop = new \ReflectionProperty(Hardening::class, 'usersEndpointFilterAdded');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        // Default: the escape-hatch filter returns whatever default is passed
        // (true), i.e. hardening stays on. Individual tests override this.
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value = null) => $value
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stockUserEndpoints(): array
    {
        return [
            '/wp/v2/users'                  => ['stub'],
            '/wp/v2/users/(?P<id>[\d]+)'    => ['stub'],
            '/wp/v2/users/me'               => ['stub'],
            '/wp/v2/posts'                  => ['stub'],
        ];
    }

    public function test_hide_users_endpoint_registers_the_rest_endpoints_filter_once(): void
    {
        Functions\expect('add_filter')
            ->once()
            ->with('rest_endpoints', [Hardening::class, 'filterUsersEndpoints']);

        Hardening::hideUsersEndpoint();
        // Second call is a no-op — add_filter must not fire again.
        Hardening::hideUsersEndpoint();

        $prop = new \ReflectionProperty(Hardening::class, 'usersEndpointFilterAdded');
        $prop->setAccessible(true);
        $this->assertTrue($prop->getValue());
    }

    public function test_anonymous_request_loses_the_users_collection_and_single_user_route(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $filtered = Hardening::filterUsersEndpoints($this->stockUserEndpoints());

        $this->assertArrayNotHasKey('/wp/v2/users', $filtered);
        $this->assertArrayNotHasKey('/wp/v2/users/(?P<id>[\d]+)', $filtered);
    }

    public function test_users_me_always_survives_for_anonymous_requests(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $filtered = Hardening::filterUsersEndpoints($this->stockUserEndpoints());

        $this->assertArrayHasKey('/wp/v2/users/me', $filtered);
        // Unrelated routes are left alone.
        $this->assertArrayHasKey('/wp/v2/posts', $filtered);
    }

    public function test_logged_in_request_is_left_completely_untouched(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $endpoints = $this->stockUserEndpoints();
        $filtered = Hardening::filterUsersEndpoints($endpoints);

        $this->assertSame($endpoints, $filtered);
    }

    public function test_escape_hatch_filter_restores_the_public_collection(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('apply_filters')->alias(
            static fn (string $hook, mixed $value = null)
                => $hook === 'taw_security_hide_users_endpoint' ? false : $value
        );

        $endpoints = $this->stockUserEndpoints();
        $filtered = Hardening::filterUsersEndpoints($endpoints);

        $this->assertSame($endpoints, $filtered);
    }
}
