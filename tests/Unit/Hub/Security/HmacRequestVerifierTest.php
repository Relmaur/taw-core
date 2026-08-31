<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use TAW\Hub\Security\Contracts\NonceStore;
use TAW\Hub\Security\HmacRequestVerifier;
use TAW\Hub\Security\HubKey;
use TAW\Hub\Security\InboundRequest;
use TAW\Hub\Security\KeyRing;
use TAW\Hub\Security\SignaturePreflight;
use TAW\Hub\Security\VerificationException;
use TAW\Tests\TestCase;

/**
 * The verifier has no WordPress dependency — KeyRing is built directly, the
 * nonce store is an in-memory fake, and the clock is injected — so these
 * tests exercise the crypto/replay logic in isolation.
 */
final class HmacRequestVerifierTest extends TestCase
{
    private const NOW    = 1_700_000_000;
    private const SECRET = 'a-shared-secret-with-enough-entropy';
    private const KEY_ID = 'hub-prod';
    private const NONCE  = 'nonce0123456789abcdef';

    private NonceStore $nonces;
    private HmacRequestVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nonces = new class implements NonceStore {
            /** @var array<string, bool> */
            private array $spent = [];

            public function seen(string $nonce): bool
            {
                return isset($this->spent[$nonce]);
            }

            public function remember(string $nonce): void
            {
                $this->spent[$nonce] = true;
            }
        };

        $ring = new KeyRing([
            self::KEY_ID => new HubKey(self::KEY_ID, self::SECRET, ['hub:read', 'hub:deploy']),
        ]);

        $preflight = new SignaturePreflight($ring, $this->nonces, 60, fn (): int => self::NOW);
        $this->verifier = new HmacRequestVerifier($preflight);
    }

    public function test_a_correctly_signed_request_resolves_to_its_key_identity(): void
    {
        $identity = $this->verifier->verify($this->signed());

        $this->assertSame(self::KEY_ID, $identity->keyId());
        $this->assertSame(['hub:read', 'hub:deploy'], $identity->capabilities());
        $this->assertTrue($identity->can('hub:deploy'));
        $this->assertFalse($identity->can('hub:config'));
    }

    public function test_missing_key_id_or_signature_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::MISSING_AUTH_HEADERS,
            $this->signed(headers: ['x-taw-hub-key-id' => '']),
        );
        $this->assertReason(
            VerificationException::MISSING_AUTH_HEADERS,
            $this->signed(headers: ['x-taw-hub-signature' => '']),
        );
    }

    public function test_an_unknown_key_id_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::UNKNOWN_KEY_ID,
            $this->signed(headers: ['x-taw-hub-key-id' => 'hub-staging']),
        );
    }

    public function test_a_non_hmac_scheme_header_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::UNSUPPORTED_SCHEME,
            $this->signed(headers: ['x-taw-hub-scheme' => 'ed25519']),
        );
    }

    public function test_an_explicit_hmac_scheme_header_is_accepted(): void
    {
        $identity = $this->verifier->verify($this->signed(headers: ['x-taw-hub-scheme' => 'hmac-sha256']));

        $this->assertSame(self::KEY_ID, $identity->keyId());
    }

    public function test_a_tampered_body_breaks_the_signature(): void
    {
        $request = $this->signed();
        $tampered = new InboundRequest(
            $request->method,
            $request->path,
            '{"evil":true}',
            $this->headersFrom($request),
        );

        $this->assertReason(VerificationException::BAD_SIGNATURE, $tampered);
    }

    public function test_a_tampered_route_breaks_the_signature(): void
    {
        $request = $this->signed();
        $tampered = new InboundRequest(
            $request->method,
            '/taw-hub/v1/command',
            $request->body,
            $this->headersFrom($request),
        );

        $this->assertReason(VerificationException::BAD_SIGNATURE, $tampered);
    }

    public function test_a_signature_from_the_wrong_secret_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::BAD_SIGNATURE,
            $this->signed(secret: 'not-the-real-secret'),
        );
    }

    public function test_a_non_hex_signature_is_rejected_as_bad_signature(): void
    {
        $this->assertReason(
            VerificationException::BAD_SIGNATURE,
            $this->signed(headers: ['x-taw-hub-signature' => 'zzzz not hex zzzz']),
        );
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::TIMESTAMP_DRIFT,
            $this->signed(timestamp: self::NOW - 61),
        );
    }

    public function test_a_future_timestamp_beyond_drift_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::TIMESTAMP_DRIFT,
            $this->signed(timestamp: self::NOW + 61),
        );
    }

    public function test_a_non_numeric_timestamp_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::TIMESTAMP_DRIFT,
            $this->signed(headers: ['x-taw-hub-timestamp' => 'not-a-number']),
        );
    }

    public function test_a_timestamp_at_the_edge_of_the_window_is_accepted(): void
    {
        $identity = $this->verifier->verify($this->signed(timestamp: self::NOW - 60));

        $this->assertSame(self::KEY_ID, $identity->keyId());
    }

    public function test_a_malformed_nonce_is_rejected(): void
    {
        $this->assertReason(VerificationException::MALFORMED_NONCE, $this->signed(nonce: 'too-short'));
        $this->assertReason(VerificationException::MALFORMED_NONCE, $this->signed(nonce: 'has spaces in it here'));
    }

    public function test_a_replayed_nonce_is_rejected_on_the_second_use(): void
    {
        $this->verifier->verify($this->signed());

        $this->assertReason(VerificationException::REPLAYED_NONCE, $this->signed());
    }

    public function test_a_failed_verification_does_not_consume_the_nonce(): void
    {
        // First attempt carries a bad signature and must be rejected...
        $this->assertReason(
            VerificationException::BAD_SIGNATURE,
            $this->signed(secret: 'wrong'),
        );

        // ...so the same nonce is still spendable by a correctly signed retry.
        $identity = $this->verifier->verify($this->signed());
        $this->assertSame(self::KEY_ID, $identity->keyId());
    }

    /**
     * Build a signed InboundRequest, applying any header overrides AFTER the
     * signature is computed (so an override can deliberately invalidate it).
     *
     * @param array<string, string> $headers
     */
    private function signed(
        ?string $secret = null,
        string $method = 'POST',
        string $path = '/taw-hub/v1/telemetry/full',
        string $body = '{"ping":true}',
        ?int $timestamp = null,
        string $nonce = self::NONCE,
        array $headers = [],
    ): InboundRequest {
        $secret ??= self::SECRET;
        $timestamp ??= self::NOW;

        $canonical = implode("\n", [
            'v1',
            strtoupper($method),
            $path,
            hash('sha256', $body),
            (string) $timestamp,
            $nonce,
        ]);

        $resolved = array_merge([
            'x-taw-hub-key-id'    => self::KEY_ID,
            'x-taw-hub-timestamp' => (string) $timestamp,
            'x-taw-hub-nonce'     => $nonce,
            'x-taw-hub-signature' => hash_hmac('sha256', $canonical, $secret),
        ], $headers);

        return new InboundRequest($method, $path, $body, $resolved);
    }

    /**
     * @return array<string, string>
     */
    private function headersFrom(InboundRequest $request): array
    {
        return [
            'x-taw-hub-scheme'    => $request->header('x-taw-hub-scheme'),
            'x-taw-hub-key-id'    => $request->header('x-taw-hub-key-id'),
            'x-taw-hub-timestamp' => $request->header('x-taw-hub-timestamp'),
            'x-taw-hub-nonce'     => $request->header('x-taw-hub-nonce'),
            'x-taw-hub-signature' => $request->header('x-taw-hub-signature'),
        ];
    }

    private function assertReason(string $expected, InboundRequest $request): void
    {
        try {
            $this->verifier->verify($request);
            $this->fail("Expected verification to fail with reason '{$expected}'.");
        } catch (VerificationException $e) {
            $this->assertSame($expected, $e->reason());
        }
    }
}
