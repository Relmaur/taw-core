<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\RequestVerifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared-secret request authentication: HMAC-SHA256 over {@see CanonicalRequest}.
 *
 * All the non-crypto checks (scheme, headers, key lookup, timestamp drift,
 * nonce shape and replay) live in {@see SignaturePreflight}; this class only
 * adds the HMAC compare and spends the nonce once it passes. Fails closed —
 * any deviation throws {@see VerificationException} and no {@see HubIdentity}
 * is produced.
 *
 * Signature header: lower/upper-hex, 64 chars.
 */
final class HmacRequestVerifier implements RequestVerifier
{
    public const SCHEME = 'hmac-sha256';

    private const SIGNATURE_HEX_LENGTH = 64;

    public function __construct(private SignaturePreflight $preflight)
    {
    }

    public function verify(InboundRequest $request): HubIdentity
    {
        $checked = $this->preflight->check($request, self::SCHEME);
        $key     = $checked['key'];

        $secret = $key->secret();
        if ($secret === null) {
            // Key exists but carries only an Ed25519 public key.
            throw new VerificationException(
                VerificationException::UNKNOWN_KEY_ID,
                'Key has no HMAC secret configured.',
            );
        }

        $expected = hash_hmac('sha256', $checked['canonical']->bytes(), $secret, true);
        $provided = self::decodeSignature($checked['signature']);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new VerificationException(VerificationException::BAD_SIGNATURE);
        }

        $this->preflight->spend($checked['nonce']);

        return new HubIdentity($key->id(), $key->capabilities());
    }

    /**
     * Hex-decode the signature, or '' for anything that isn't a well-formed
     * 64-char hex string. `hash_equals()` still guards the compare; this just
     * rejects obvious garbage early.
     */
    private static function decodeSignature(string $hex): string
    {
        if (strlen($hex) !== self::SIGNATURE_HEX_LENGTH || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
            return '';
        }

        $bin = hex2bin($hex);

        return $bin === false ? '' : $bin;
    }
}
