<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use TAW\Hub\Security\Contracts\NonceStore;
use TAW\Hub\Security\Contracts\RequestVerifier;
use TAW\Hub\Security\HubIdentity;
use TAW\Hub\Security\InboundRequest;
use TAW\Hub\Security\KeyRing;
use TAW\Hub\Security\SchemeRouter;
use TAW\Hub\Security\SignaturePreflight;
use TAW\Hub\Security\VerificationException;
use TAW\Tests\TestCase;

final class SchemeRouterTest extends TestCase
{
    private function tagVerifier(string $tag): RequestVerifier
    {
        return new class ($tag) implements RequestVerifier {
            public function __construct(private string $tag)
            {
            }
            public function verify(InboundRequest $request): HubIdentity
            {
                return new HubIdentity($this->tag, ['hub:read']);
            }
        };
    }

    private function request(?string $scheme): InboundRequest
    {
        $headers = [];
        if ($scheme !== null) {
            $headers['x-taw-hub-scheme'] = $scheme;
        }

        return new InboundRequest('POST', '/taw-hub/v1/health', '{}', $headers);
    }

    public function test_absent_scheme_header_routes_to_the_default(): void
    {
        $router = new SchemeRouter([
            'hmac-sha256' => $this->tagVerifier('via-hmac'),
            'ed25519'     => $this->tagVerifier('via-ed25519'),
        ]);

        $this->assertSame('via-hmac', $router->verify($this->request(null))->keyId());
    }

    public function test_scheme_header_selects_the_matching_verifier(): void
    {
        $router = new SchemeRouter([
            'hmac-sha256' => $this->tagVerifier('via-hmac'),
            'ed25519'     => $this->tagVerifier('via-ed25519'),
        ]);

        $this->assertSame('via-ed25519', $router->verify($this->request('ed25519'))->keyId());
        $this->assertSame('via-ed25519', $router->verify($this->request('ED25519'))->keyId());
    }

    public function test_an_unknown_scheme_is_rejected(): void
    {
        $router = new SchemeRouter(['hmac-sha256' => $this->tagVerifier('via-hmac')]);

        try {
            $router->verify($this->request('rot13'));
            $this->fail('Expected rejection.');
        } catch (VerificationException $e) {
            $this->assertSame(VerificationException::UNSUPPORTED_SCHEME, $e->reason());
        }
    }

    public function test_standard_wires_hmac_and_ed25519_and_rejects_anything_else(): void
    {
        $nonces = new class implements NonceStore {
            public function seen(string $nonce): bool
            {
                return false;
            }
            public function remember(string $nonce): void
            {
            }
        };
        $preflight = new SignaturePreflight(KeyRing::fromArray([]), $nonces, 60, fn (): int => 0);
        $router = SchemeRouter::standard($preflight);

        // hmac + ed25519 dispatch into their verifiers (which then reject the
        // unknown key via preflight); an unknown scheme never dispatches.
        foreach (['hmac-sha256', 'ed25519'] as $known) {
            try {
                $router->verify($this->request($known));
                $this->fail('Expected rejection.');
            } catch (VerificationException $e) {
                $this->assertNotSame(VerificationException::UNSUPPORTED_SCHEME, $e->reason());
            }
        }

        try {
            $router->verify($this->request('nope'));
            $this->fail('Expected rejection.');
        } catch (VerificationException $e) {
            $this->assertSame(VerificationException::UNSUPPORTED_SCHEME, $e->reason());
        }
    }
}
