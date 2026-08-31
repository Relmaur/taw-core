<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\RequestVerifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dispatches a request to the verifier for its `X-TAW-Hub-Scheme` header
 * (defaulting to HMAC when the header is absent). This is the verifier the
 * REST middleware actually holds.
 *
 * Each concrete verifier still re-checks the scheme via {@see SignaturePreflight}
 * as defense in depth, so constructing one directly stays safe.
 */
final class SchemeRouter implements RequestVerifier
{
    /**
     * @param array<string, RequestVerifier> $verifiers Keyed by lower-case scheme id.
     */
    public function __construct(
        private array $verifiers,
        private string $defaultScheme = HmacRequestVerifier::SCHEME,
    ) {
    }

    /**
     * The standard router: HMAC-SHA256 + Ed25519, sharing one preflight.
     */
    public static function standard(SignaturePreflight $preflight): self
    {
        return new self([
            HmacRequestVerifier::SCHEME => new HmacRequestVerifier($preflight),
            Ed25519Verifier::SCHEME     => new Ed25519Verifier($preflight),
        ]);
    }

    public function verify(InboundRequest $request): HubIdentity
    {
        $scheme = strtolower($request->header('x-taw-hub-scheme'));
        if ($scheme === '') {
            $scheme = $this->defaultScheme;
        }

        $verifier = $this->verifiers[$scheme] ?? null;
        if (!$verifier instanceof RequestVerifier) {
            throw new VerificationException(
                VerificationException::UNSUPPORTED_SCHEME,
                "Unsupported signature scheme: {$scheme}",
            );
        }

        return $verifier->verify($request);
    }
}
