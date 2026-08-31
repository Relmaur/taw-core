<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\EnrolmentLedger;
use TAW\Hub\Security\Contracts\KeyStore;
use TAW\Hub\Security\Contracts\SiteSigner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `/handshake` logic: trade a valid one-time enrolment token for a
 * registered Ed25519 credential.
 *
 * Trust model — token-authenticated, single-use. The Hub presents the token
 * that was placed in `TAW_HUB_ENROLMENT_TOKEN` (out of band, by whoever
 * provisions the site) plus its own public key. On success the key is stored,
 * the token is burned, and the site returns its own public key so the Hub can
 * verify anything this site later signs. WordPress-free; every seam is
 * injected.
 */
final class EnrolmentService
{
    /** Capabilities a handshake may grant. `*` is never grantable this way. */
    private const GRANTABLE = ['hub:read', 'hub:deploy', 'hub:config', 'hub:maintenance'];

    public function __construct(
        private KeyStore $store,
        private SiteSigner $site,
        private ?string $enrolmentToken,
        private EnrolmentLedger $ledger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{key_id: string, site_public_key: string, accepted_capabilities: list<string>}
     * @throws EnrolmentException
     */
    public function enrol(array $payload): array
    {
        if ($this->enrolmentToken === null || $this->enrolmentToken === '') {
            throw new EnrolmentException(EnrolmentException::ENROLMENT_DISABLED);
        }

        $token = is_string($payload['enrolment_token'] ?? null) ? $payload['enrolment_token'] : '';
        if (!hash_equals($this->enrolmentToken, $token)) {
            throw new EnrolmentException(EnrolmentException::BAD_TOKEN);
        }

        if ($this->ledger->tokenConsumed()) {
            throw new EnrolmentException(EnrolmentException::TOKEN_SPENT);
        }

        $publicKey = self::decodePublicKey($payload['hub_public_key'] ?? null);
        if ($publicKey === '') {
            throw new EnrolmentException(EnrolmentException::BAD_HUB_PUBLIC_KEY);
        }

        $granted = self::grantable($payload['requested_capabilities'] ?? null);
        $keyId   = 'hub-' . substr(hash('sha256', $publicKey), 0, 16);

        $this->store->put($keyId, [
            'public_key'   => base64_encode($publicKey),
            'capabilities' => $granted,
        ]);
        $this->ledger->markConsumed($keyId);

        return [
            'key_id'                => $keyId,
            'site_public_key'       => $this->site->publicKeyBase64(),
            'accepted_capabilities' => $granted,
        ];
    }

    private static function decodePublicKey(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? $decoded
            : '';
    }

    /**
     * @return list<string>
     */
    private static function grantable(mixed $requested): array
    {
        if (!is_array($requested)) {
            return ['hub:read'];
        }

        $granted = array_values(array_intersect(
            self::GRANTABLE,
            array_filter($requested, 'is_string'),
        ));

        return $granted === [] ? ['hub:read'] : $granted;
    }
}
