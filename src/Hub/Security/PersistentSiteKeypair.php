<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\SiteSigner;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * This site's Ed25519 identity, generated on first use and persisted to an
 * autoload-off option.
 *
 * The secret half is sealed with `sodium_crypto_secretbox` under a key
 * derived from a wp-config constant (`TAW_HUB_SECRET`, else `SECURE_AUTH_KEY`)
 * so a database dump alone doesn't expose it. With no such constant available
 * it falls back to storing the raw key and logs a `[TAW Hub]` warning.
 */
final class PersistentSiteKeypair implements SiteSigner
{
    private const OPTION = 'taw_hub_site_keypair';

    private ?string $secretKey = null;
    private ?string $publicKey = null;

    public function publicKeyBase64(): string
    {
        $this->load();

        return base64_encode((string) $this->publicKey);
    }

    public function sign(string $message): string
    {
        $this->load();

        return sodium_crypto_sign_detached($message, (string) $this->secretKey);
    }

    private function load(): void
    {
        if ($this->secretKey !== null) {
            return;
        }

        $stored = get_option(self::OPTION, []);
        if (is_array($stored) && isset($stored['public'], $stored['secret'])) {
            $public = base64_decode((string) $stored['public'], true);
            $secret = $this->unseal((string) $stored['secret']);
            if ($public !== false && $secret !== '') {
                $this->publicKey = $public;
                $this->secretKey = $secret;

                return;
            }
        }

        $pair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $this->secretKey = sodium_crypto_sign_secretkey($pair);

        update_option(self::OPTION, [
            'public' => base64_encode($this->publicKey),
            'secret' => $this->seal($this->secretKey),
        ], false);
    }

    private function sealKey(): ?string
    {
        foreach (['TAW_HUB_SECRET', 'SECURE_AUTH_KEY', 'AUTH_KEY'] as $constant) {
            if (defined($constant)) {
                $value = constant($constant);
                if (is_string($value) && $value !== '') {
                    return sodium_crypto_generichash($value, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                }
            }
        }

        return null;
    }

    private function seal(string $plaintext): string
    {
        $key = $this->sealKey();
        if ($key === null) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[TAW Hub] No TAW_HUB_SECRET / SECURE_AUTH_KEY — site key stored unsealed.');

            return 'raw:' . base64_encode($plaintext);
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'box:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private function unseal(string $stored): string
    {
        if (str_starts_with($stored, 'raw:')) {
            $decoded = base64_decode(substr($stored, 4), true);

            return $decoded === false ? '' : $decoded;
        }

        if (!str_starts_with($stored, 'box:')) {
            return '';
        }

        $key = $this->sealKey();
        $blob = base64_decode(substr($stored, 4), true);
        if ($key === null || $blob === false || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce      = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        } catch (\SodiumException) {
            return '';
        }

        return $plaintext === false ? '' : $plaintext;
    }
}
