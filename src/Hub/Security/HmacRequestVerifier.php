<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\NonceStore;
use TAW\Hub\Security\Contracts\RequestVerifier;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared-secret request authentication: HMAC-SHA256 over {@see CanonicalRequest},
 * plus a strict timestamp-drift bound and single-use nonce enforcement.
 *
 * Checks run cheapest-first and the verifier fails closed — any deviation
 * throws {@see VerificationException} and no {@see HubIdentity} is produced.
 * The request nonce is only spent ({@see NonceStore::remember()}) once the
 * signature itself has passed, so a flood of unsigned/bad-signature requests
 * can neither exhaust the nonce store nor burn a legitimate nonce.
 *
 * The `X-TAW-Hub-Scheme` header, when sent, must be `hmac-sha256`; an
 * `ed25519` value is rejected as `unsupported_scheme` until the asymmetric
 * verifier lands.
 */
final class HmacRequestVerifier implements RequestVerifier
{
    public const SCHEME = 'hmac-sha256';

    /** HMAC-SHA256 in lower/upper hex. */
    private const SIGNATURE_HEX_LENGTH = 64;

    /** @var callable(): int */
    private $clock;

    /**
     * @param int                  $maxDriftSeconds Accept `|now − timestamp|` up to this many seconds.
     * @param (callable(): int)|null $clock         Time source (unix seconds); defaults to time().
     */
    public function __construct(
        private KeyRing $keys,
        private NonceStore $nonces,
        private int $maxDriftSeconds = 60,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function verify(InboundRequest $request): HubIdentity
    {
        $scheme = strtolower($request->header('x-taw-hub-scheme'));
        if ($scheme !== '' && $scheme !== self::SCHEME) {
            throw new VerificationException(
                VerificationException::UNSUPPORTED_SCHEME,
                "Unsupported signature scheme: {$scheme}",
            );
        }

        $keyId     = $request->header('x-taw-hub-key-id');
        $signature = $request->header('x-taw-hub-signature');
        if ($keyId === '' || $signature === '') {
            throw new VerificationException(VerificationException::MISSING_AUTH_HEADERS);
        }

        $key = $this->keys->find($keyId);
        if (!$key instanceof HubKey) {
            throw new VerificationException(VerificationException::UNKNOWN_KEY_ID);
        }

        $canonical = CanonicalRequest::fromInbound($request);

        $now = ($this->clock)();
        if ($canonical->timestamp() <= 0 || abs($now - $canonical->timestamp()) > $this->maxDriftSeconds) {
            throw new VerificationException(VerificationException::TIMESTAMP_DRIFT);
        }

        $nonce = $canonical->nonce();
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) !== 1) {
            throw new VerificationException(VerificationException::MALFORMED_NONCE);
        }
        if ($this->nonces->seen($nonce)) {
            throw new VerificationException(VerificationException::REPLAYED_NONCE);
        }

        $expected = hash_hmac('sha256', $canonical->bytes(), $key->secret(), true);
        $provided = self::decodeSignature($signature);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new VerificationException(VerificationException::BAD_SIGNATURE);
        }

        $this->nonces->remember($nonce);

        return new HubIdentity($key->id(), $key->capabilities());
    }

    /**
     * Hex-decode the signature header, or return '' for anything that isn't a
     * well-formed 64-char hex string. `hash_equals()` still guards the compare;
     * this just rejects obvious garbage early.
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
