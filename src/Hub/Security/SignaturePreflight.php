<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\NonceStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything a signed Hub request must satisfy *except* the signature check
 * itself: scheme match, auth headers present, key known, timestamp in-window,
 * nonce well-formed and unspent.
 *
 * Shared by every {@see Contracts\RequestVerifier} so HMAC and Ed25519 can't
 * drift apart on the parts that aren't the crypto. The nonce is deliberately
 * NOT spent here — a verifier calls {@see self::spend()} only after its
 * signature check passes, so bad-signature floods can't burn a live nonce or
 * fill the store.
 */
final class SignaturePreflight
{
    /** @var callable(): int */
    private $clock;

    /**
     * @param int                    $maxDriftSeconds Accept `|now − timestamp|` up to this many seconds.
     * @param (callable(): int)|null  $clock          Time source (unix seconds); defaults to time().
     */
    public function __construct(
        private KeyRing $keys,
        private NonceStore $nonces,
        private int $maxDriftSeconds = 60,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param non-empty-string $scheme The scheme the calling verifier implements.
     * @return array{key: HubKey, canonical: CanonicalRequest, nonce: string, signature: string}
     * @throws VerificationException
     */
    public function check(InboundRequest $request, string $scheme): array
    {
        $sentScheme = strtolower($request->header('x-taw-hub-scheme'));
        if ($sentScheme !== '' && $sentScheme !== $scheme) {
            throw new VerificationException(
                VerificationException::UNSUPPORTED_SCHEME,
                "Unsupported signature scheme: {$sentScheme}",
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

        return [
            'key'       => $key,
            'canonical' => $canonical,
            'nonce'     => $nonce,
            'signature' => $signature,
        ];
    }

    /**
     * Spend the nonce. A verifier calls this only after its signature check
     * has passed.
     */
    public function spend(string $nonce): void
    {
        $this->nonces->remember($nonce);
    }
}
