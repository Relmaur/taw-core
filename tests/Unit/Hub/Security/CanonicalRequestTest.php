<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use TAW\Hub\Security\CanonicalRequest;
use TAW\Hub\Security\InboundRequest;
use TAW\Tests\TestCase;

/**
 * The canonical string layout is a wire contract shared with the Hub — these
 * tests pin the exact bytes so an accidental reformat can't silently break
 * every signature.
 */
final class CanonicalRequestTest extends TestCase
{
    public function test_it_produces_the_frozen_six_line_layout(): void
    {
        $canonical = new CanonicalRequest('post', '/taw-hub/v1/health', '{"a":1}', 1_700_000_000, 'abc123');

        $expected = implode("\n", [
            'v1',
            'POST',
            '/taw-hub/v1/health',
            hash('sha256', '{"a":1}'),
            '1700000000',
            'abc123',
        ]);

        $this->assertSame($expected, $canonical->bytes());
    }

    public function test_the_body_is_hashed_not_included_verbatim(): void
    {
        $canonical = new CanonicalRequest('GET', '/x', 'secret-body', 1, 'n');

        $this->assertStringNotContainsString('secret-body', $canonical->bytes());
        $this->assertStringContainsString(hash('sha256', 'secret-body'), $canonical->bytes());
    }

    public function test_a_different_body_yields_different_bytes(): void
    {
        $a = new CanonicalRequest('GET', '/x', 'one', 1, 'n');
        $b = new CanonicalRequest('GET', '/x', 'two', 1, 'n');

        $this->assertNotSame($a->bytes(), $b->bytes());
    }

    public function test_from_inbound_reads_timestamp_and_nonce_from_headers(): void
    {
        $request = new InboundRequest('POST', '/taw-hub/v1/command', '{}', [
            'x-taw-hub-timestamp' => '1700000123',
            'x-taw-hub-nonce'     => 'nonce-value',
        ]);

        $canonical = CanonicalRequest::fromInbound($request);

        $this->assertSame(1_700_000_123, $canonical->timestamp());
        $this->assertSame('nonce-value', $canonical->nonce());
        $this->assertStringContainsString("\n1700000123\nnonce-value", $canonical->bytes());
    }
}
