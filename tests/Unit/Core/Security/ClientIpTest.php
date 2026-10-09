<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Security;

use Brain\Monkey\Filters;
use TAW\Core\Security\ClientIp;
use TAW\Tests\TestCase;

final class ClientIpTest extends TestCase
{
    public function test_forwarding_headers_are_ignored_without_trusted_proxies(): void
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '5.6.7.8',
            'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
        ];

        $this->assertSame('203.0.113.7', ClientIp::resolve($server, []));
    }

    public function test_forwarding_headers_are_ignored_when_the_peer_is_not_a_trusted_proxy(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];

        $this->assertSame('203.0.113.7', ClientIp::resolve($server, ['10.0.0.0/8']));
    }

    public function test_the_right_most_untrusted_forwarded_hop_wins_behind_a_trusted_proxy(): void
    {
        // The client spoofed "6.6.6.6"; the real address 198.51.100.4 was
        // appended by the trusted proxy chain (10.0.0.5 is a proxy too).
        $server = [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.4, 10.0.0.5',
        ];

        $this->assertSame('198.51.100.4', ClientIp::resolve($server, ['10.0.0.0/8']));
    }

    public function test_cloudflare_header_is_honoured_only_from_a_trusted_peer(): void
    {
        $server = ['REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '198.51.100.9'];

        $this->assertSame('198.51.100.9', ClientIp::resolve($server, ['173.245.48.0/20']));
        $this->assertSame('173.245.48.10', ClientIp::resolve($server, ['10.0.0.0/8']));
    }

    public function test_a_malformed_hop_stops_the_walk_at_the_peer(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4, not-an-ip'];

        $this->assertSame('10.0.0.2', ClientIp::resolve($server, ['10.0.0.0/8']));
    }

    public function test_x_real_ip_is_the_fallback_behind_a_trusted_proxy(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_REAL_IP' => '198.51.100.4'];

        $this->assertSame('198.51.100.4', ClientIp::resolve($server, ['10.0.0.2']));
    }

    public function test_an_invalid_or_missing_remote_addr_falls_back(): void
    {
        $this->assertSame('0.0.0.0', ClientIp::resolve([], []));
        $this->assertSame('0.0.0.0', ClientIp::resolve(['REMOTE_ADDR' => '<script>'], []));
    }

    public function test_cidr_matching_for_ipv4_and_ipv6(): void
    {
        $this->assertTrue(ClientIp::inRange('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(ClientIp::inRange('11.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(ClientIp::inRange('192.168.1.130', '192.168.1.128/25'));
        $this->assertFalse(ClientIp::inRange('192.168.1.127', '192.168.1.128/25'));
        $this->assertTrue(ClientIp::inRange('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(ClientIp::inRange('2001:db9::1', '2001:db8::/32'));
        $this->assertTrue(ClientIp::inRange('8.8.8.8', '8.8.8.8'));
        $this->assertTrue(ClientIp::inRange('8.8.8.8', '0.0.0.0/0'));
    }

    public function test_mismatched_families_and_bad_ranges_never_match(): void
    {
        $this->assertFalse(ClientIp::inRange('10.0.0.1', '::/0'));
        $this->assertFalse(ClientIp::inRange('10.0.0.1', '10.0.0.0/33'));
        $this->assertFalse(ClientIp::inRange('10.0.0.1', '10.0.0.0/abc'));
        $this->assertFalse(ClientIp::inRange('nope', '10.0.0.0/8'));
    }

    public function test_private_and_loopback_ranges_are_trusted_by_default(): void
    {
        $this->assertSame(ClientIp::PRIVATE_RANGES, ClientIp::trustedProxies());

        // So a local reverse proxy's forwarded address is honoured…
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4'];
        $this->assertSame('198.51.100.4', ClientIp::resolve($server, ClientIp::trustedProxies()));

        // …while a public peer's forwarding headers still aren't.
        $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.4'];
        $this->assertSame('203.0.113.7', ClientIp::resolve($server, ClientIp::trustedProxies()));
    }

    public function test_trusted_proxies_come_through_the_filter(): void
    {
        Filters\expectApplied('taw_trusted_proxies')
            ->once()
            ->with(ClientIp::PRIVATE_RANGES)
            ->andReturn(['173.245.48.0/20', ' ', 42]);

        $this->assertSame(['173.245.48.0/20', '42'], ClientIp::trustedProxies());
    }

    public function test_a_non_array_filter_result_keeps_the_default_list(): void
    {
        Filters\expectApplied('taw_trusted_proxies')->once()->andReturn('nonsense');

        $this->assertSame(ClientIp::PRIVATE_RANGES, ClientIp::trustedProxies());
    }
}
