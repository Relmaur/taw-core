<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use TAW\Hub\Security\Contracts\NonceStore;
use TAW\Hub\Security\Ed25519Verifier;
use TAW\Hub\Security\HubKey;
use TAW\Hub\Security\InboundRequest;
use TAW\Hub\Security\KeyRing;
use TAW\Hub\Security\SignaturePreflight;
use TAW\Hub\Security\VerificationException;
use TAW\Tests\TestCase;

/**
 * Uses a real libsodium keypair generated per-test — the signature the Hub
 * would send is produced with sodium_crypto_sign_detached() over the same
 * canonical string the verifier rebuilds.
 */
final class Ed25519VerifierTest extends TestCase
{
    private const NOW    = 1_700_000_000;
    private const KEY_ID = 'hub-asym';
    private const NONCE  = 'ed25519nonce00000000';

    private string $secretKey;
    private string $publicKeyB64;
    private NonceStore $nonces;
    private Ed25519Verifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secretKey    = sodium_crypto_sign_secretkey($pair);
        $this->publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($pair));

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
            self::KEY_ID => new HubKey(self::KEY_ID, null, ['hub:read'], $this->publicKeyB64),
        ]);

        $preflight = new SignaturePreflight($ring, $this->nonces, 60, fn (): int => self::NOW);
        $this->verifier = new Ed25519Verifier($preflight);
    }

    public function test_a_validly_signed_request_resolves_to_its_identity(): void
    {
        $identity = $this->verifier->verify($this->signed());

        $this->assertSame(self::KEY_ID, $identity->keyId());
        $this->assertSame(['hub:read'], $identity->capabilities());
    }

    public function test_a_url_safe_base64_signature_is_accepted(): void
    {
        $identity = $this->verifier->verify($this->signed(urlSafe: true));

        $this->assertSame(self::KEY_ID, $identity->keyId());
    }

    public function test_a_signature_from_a_different_key_is_rejected(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $request = $this->signed(secretKey: sodium_crypto_sign_secretkey($otherPair));

        $this->assertReason(VerificationException::BAD_SIGNATURE, $request);
    }

    public function test_a_tampered_body_breaks_the_signature(): void
    {
        $good = $this->signed();
        $tampered = new InboundRequest($good->method, $good->path, '{"tampered":true}', [
            'x-taw-hub-scheme'    => 'ed25519',
            'x-taw-hub-key-id'    => self::KEY_ID,
            'x-taw-hub-timestamp' => (string) self::NOW,
            'x-taw-hub-nonce'     => self::NONCE,
            'x-taw-hub-signature' => $good->header('x-taw-hub-signature'),
        ]);

        $this->assertReason(VerificationException::BAD_SIGNATURE, $tampered);
    }

    public function test_a_garbage_signature_is_rejected(): void
    {
        $this->assertReason(
            VerificationException::BAD_SIGNATURE,
            $this->signed(signature: 'not-base64-!!!'),
        );
    }

    public function test_a_key_without_a_public_key_cannot_use_this_scheme(): void
    {
        $ring = new KeyRing([
            self::KEY_ID => new HubKey(self::KEY_ID, 'hmac-secret-only', ['hub:read'], null),
        ]);
        $preflight = new SignaturePreflight($ring, $this->nonces, 60, fn (): int => self::NOW);
        $verifier  = new Ed25519Verifier($preflight);

        try {
            $verifier->verify($this->signed());
            $this->fail('Expected rejection.');
        } catch (VerificationException $e) {
            $this->assertSame(VerificationException::UNKNOWN_KEY_ID, $e->reason());
        }
    }

    public function test_a_replayed_nonce_is_rejected(): void
    {
        $this->verifier->verify($this->signed());
        $this->assertReason(VerificationException::REPLAYED_NONCE, $this->signed());
    }

    public function test_a_failed_signature_does_not_consume_the_nonce(): void
    {
        $this->assertReason(
            VerificationException::BAD_SIGNATURE,
            $this->signed(signature: base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES))),
        );

        $identity = $this->verifier->verify($this->signed());
        $this->assertSame(self::KEY_ID, $identity->keyId());
    }

    private function signed(
        string $method = 'POST',
        string $path = '/taw-hub/v1/telemetry/full',
        string $body = '{"ping":true}',
        ?int $timestamp = null,
        string $nonce = self::NONCE,
        ?string $secretKey = null,
        ?string $signature = null,
        bool $urlSafe = false,
    ): InboundRequest {
        $timestamp ??= self::NOW;
        $secretKey ??= $this->secretKey;

        $canonical = implode("\n", [
            'v1',
            strtoupper($method),
            $path,
            hash('sha256', $body),
            (string) $timestamp,
            $nonce,
        ]);

        if ($signature === null) {
            $raw = sodium_crypto_sign_detached($canonical, $secretKey);
            $signature = base64_encode($raw);
            if ($urlSafe) {
                $signature = rtrim(strtr($signature, '+/', '-_'), '=');
            }
        }

        return new InboundRequest($method, $path, $body, [
            'x-taw-hub-scheme'    => 'ed25519',
            'x-taw-hub-key-id'    => self::KEY_ID,
            'x-taw-hub-timestamp' => (string) $timestamp,
            'x-taw-hub-nonce'     => $nonce,
            'x-taw-hub-signature' => $signature,
        ]);
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
