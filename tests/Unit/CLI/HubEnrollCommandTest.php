<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use TAW\CLI\HubEnrollCommand;
use TAW\Tests\TestCase;

/**
 * Covers the pure, security-critical helpers of {@see HubEnrollCommand}:
 * request-body encoding, Ed25519 key decoding, and — most importantly — the
 * ADR-0003 RESPONSE-signature verification the command runs before it trusts
 * anything the Hub says back. The HTTP round-trip and WordPress option reads
 * are left to the theme's end-to-end smoke path.
 */
final class HubEnrollCommandTest extends TestCase
{
    /** @var array{secret: string, public: string} */
    private array $hubKey;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->hubKey = [
            'secret' => sodium_crypto_sign_secretkey($pair),
            'public' => sodium_crypto_sign_publickey($pair),
        ];
    }

    // -- buildRequestBody -------------------------------------------------

    public function test_build_request_body_does_not_escape_slashes(): void
    {
        $body = HubEnrollCommand::buildRequestBody([
            'name'            => 'Example',
            'base_url'        => 'https://site.example/path',
            'site_public_key' => 'abc+/def=',
            'site_key_id'     => 'site-abc123',
            'enrolment_token' => 'enrol_xxxxxxxx',
        ]);

        $this->assertStringContainsString('https://site.example/path', $body);
        $this->assertStringNotContainsString('https:\/\/', $body);
        $this->assertSame(
            ['name', 'base_url', 'site_public_key', 'site_key_id', 'enrolment_token'],
            array_keys((array) json_decode($body, true)),
        );
    }

    // -- decodeEd25519Key ----------------------------------------------

    public function test_decode_key_accepts_standard_and_url_safe_base64(): void
    {
        $standard = base64_encode($this->hubKey['public']);
        $urlSafe  = rtrim(strtr($standard, '+/', '-_'), '=');

        $this->assertSame($this->hubKey['public'], HubEnrollCommand::decodeEd25519Key($standard));
        $this->assertSame($this->hubKey['public'], HubEnrollCommand::decodeEd25519Key($urlSafe));
    }

    public function test_decode_key_rejects_wrong_length(): void
    {
        $this->assertNull(HubEnrollCommand::decodeEd25519Key(base64_encode('too short')));
        $this->assertNull(HubEnrollCommand::decodeEd25519Key('not base64 !!!'));
    }

    // -- verifyResponseSignature -------------------------------------

    public function test_verify_accepts_a_correctly_signed_response(): void
    {
        $body    = '{"site_id":12,"key_id":"site-abc","hub_key_id":"hub-local","status":"pending"}';
        $now     = 1_800_000_000;
        $headers = $this->sign($body, $now, 'hub-local');

        HubEnrollCommand::verifyResponseSignature(
            $body,
            $this->lookup($headers),
            $this->hubKey['public'],
            'hub-local',
            $now,
        );

        $this->expectNotToPerformAssertions();
    }

    public function test_verify_rejects_a_tampered_body(): void
    {
        $body    = '{"site_id":12,"status":"pending"}';
        $now     = 1_800_000_000;
        $headers = $this->sign($body, $now, 'hub-local');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cryptographic verification failed');

        HubEnrollCommand::verifyResponseSignature(
            '{"site_id":13,"status":"active"}', // swapped after signing
            $this->lookup($headers),
            $this->hubKey['public'],
            'hub-local',
            $now,
        );
    }

    public function test_verify_rejects_a_wrong_key_id(): void
    {
        $body    = '{"ok":true}';
        $now     = 1_800_000_000;
        $headers = $this->sign($body, $now, 'hub-prod');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match the expected');

        HubEnrollCommand::verifyResponseSignature(
            $body,
            $this->lookup($headers),
            $this->hubKey['public'],
            'hub-local',
            $now,
        );
    }

    public function test_verify_rejects_stale_timestamp(): void
    {
        $body    = '{"ok":true}';
        $signed  = 1_800_000_000;
        $headers = $this->sign($body, $signed, 'hub-local');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('clock-drift window');

        HubEnrollCommand::verifyResponseSignature(
            $body,
            $this->lookup($headers),
            $this->hubKey['public'],
            'hub-local',
            $signed + 120,
        );
    }

    public function test_verify_rejects_a_signature_from_another_key(): void
    {
        $body     = '{"ok":true}';
        $now      = 1_800_000_000;
        $attacker = sodium_crypto_sign_keypair();
        $headers  = $this->sign($body, $now, 'hub-local', sodium_crypto_sign_secretkey($attacker));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cryptographic verification failed');

        HubEnrollCommand::verifyResponseSignature(
            $body,
            $this->lookup($headers),
            $this->hubKey['public'],
            'hub-local',
            $now,
        );
    }

    public function test_verify_rejects_a_response_with_no_signature_headers(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no X-Taw-Hub-* signature headers');

        HubEnrollCommand::verifyResponseSignature(
            '{"ok":true}',
            static fn (string $name): string => '',
            $this->hubKey['public'],
            'hub-local',
            1_800_000_000,
        );
    }

    // -- redactToken --------------------------------------------------

    public function test_redact_token_masks_the_enrolment_token_only(): void
    {
        $body     = HubEnrollCommand::buildRequestBody([
            'name'            => 'Example',
            'base_url'        => 'https://site.example',
            'site_public_key' => 'pubkey',
            'site_key_id'     => 'site-abc123',
            'enrolment_token' => 'enrol_abcdefghijklmnopqrstuvwxyz',
        ]);
        $redacted = json_decode(HubEnrollCommand::redactToken($body), true);

        $this->assertSame('site-abc123', $redacted['site_key_id']);
        $this->assertStringNotContainsString('ghijklmnop', $redacted['enrolment_token']);
        $this->assertStringContainsString('…', $redacted['enrolment_token']);
    }

    // -- helpers --------------------------------------------------------

    /**
     * Build the five X-Taw-Hub-* headers for a RESPONSE signature over the
     * enrolment path, exactly as the Hub's ResponseSigner would.
     *
     * @return array<string, string>
     */
    private function sign(string $body, int $timestamp, string $keyId, ?string $secretKey = null): array
    {
        $nonce     = 'nonce-' . bin2hex(random_bytes(6));
        $canonical = implode("\n", [
            'TAW-HUB-v1',
            'RESPONSE',
            '/api/fleet/enroll',
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
        $signature = sodium_crypto_sign_detached($canonical, $secretKey ?? $this->hubKey['secret']);

        return [
            'X-Taw-Hub-Algo'      => 'ed25519',
            'X-Taw-Hub-Key-Id'    => $keyId,
            'X-Taw-Hub-Timestamp' => (string) $timestamp,
            'X-Taw-Hub-Nonce'     => $nonce,
            'X-Taw-Hub-Signature' => base64_encode($signature),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return callable(string): string
     */
    private function lookup(array $headers): callable
    {
        $lower = [];
        foreach ($headers as $k => $v) {
            $lower[strtolower($k)] = $v;
        }

        return static fn (string $name): string => $lower[strtolower($name)] ?? '';
    }
}
