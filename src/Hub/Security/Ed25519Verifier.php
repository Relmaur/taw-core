<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\RequestVerifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Asymmetric request authentication: an Ed25519 detached signature over
 * {@see CanonicalRequest}, verified against the public key enrolled for the
 * sending Hub (libsodium, always available on PHP >= 7.2).
 *
 * Shares every non-crypto check with the HMAC path via {@see SignaturePreflight}.
 * The nonce is spent only after the signature verifies.
 *
 * Wire encoding: the `X-TAW-Hub-Signature` header is base64 (standard or
 * URL-safe) of the 64-byte signature; the enrolled `public_key` is base64 of
 * the 32-byte key.
 */
final class Ed25519Verifier implements RequestVerifier
{
    public const SCHEME = 'ed25519';

    public function __construct(private SignaturePreflight $preflight)
    {
    }

    public function verify(InboundRequest $request): HubIdentity
    {
        $checked = $this->preflight->check($request, self::SCHEME);
        $key     = $checked['key'];

        $publicKey = self::decodeBinary($key->publicKey(), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        if ($publicKey === '') {
            // Key exists but carries only an HMAC secret (or a malformed pubkey).
            throw new VerificationException(
                VerificationException::UNKNOWN_KEY_ID,
                'Key has no usable Ed25519 public key configured.',
            );
        }

        $signature = self::decodeBinary($checked['signature'], SODIUM_CRYPTO_SIGN_BYTES);
        if ($signature === '') {
            throw new VerificationException(VerificationException::BAD_SIGNATURE);
        }

        try {
            $ok = sodium_crypto_sign_verify_detached(
                $signature,
                $checked['canonical']->bytes(),
                $publicKey,
            );
        } catch (\SodiumException) {
            throw new VerificationException(VerificationException::BAD_SIGNATURE);
        }

        if ($ok !== true) {
            throw new VerificationException(VerificationException::BAD_SIGNATURE);
        }

        $this->preflight->spend($checked['nonce']);

        return new HubIdentity($key->id(), $key->capabilities());
    }

    /**
     * Base64-decode (accepting URL-safe alphabet) and require an exact byte
     * length; '' on any failure.
     */
    private static function decodeBinary(?string $encoded, int $expectedLength): string
    {
        if ($encoded === null || $encoded === '') {
            return '';
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== $expectedLength) {
            return '';
        }

        return $decoded;
    }
}
