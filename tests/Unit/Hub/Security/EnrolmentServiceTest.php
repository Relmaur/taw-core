<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use TAW\Hub\Security\Contracts\EnrolmentLedger;
use TAW\Hub\Security\Contracts\KeyStore;
use TAW\Hub\Security\Contracts\SiteSigner;
use TAW\Hub\Security\EnrolmentException;
use TAW\Hub\Security\EnrolmentService;
use TAW\Tests\TestCase;

final class EnrolmentServiceTest extends TestCase
{
    private const TOKEN = 'one-time-enrolment-token-abc123';

    private string $hubPublicKeyB64;
    private KeyStore $store;
    private EnrolmentLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->hubPublicKeyB64 = base64_encode(sodium_crypto_sign_publickey($pair));

        $this->store = new class implements KeyStore {
            /** @var array<string, array<string, mixed>> */
            public array $keys = [];
            public function all(): array
            {
                return $this->keys;
            }
            public function put(string $keyId, array $spec): void
            {
                $this->keys[$keyId] = $spec;
            }
            public function forget(string $keyId): void
            {
                unset($this->keys[$keyId]);
            }
        };

        $this->ledger = new class implements EnrolmentLedger {
            public bool $consumed = false;
            public ?string $keyId = null;
            public function tokenConsumed(): bool
            {
                return $this->consumed;
            }
            public function markConsumed(string $keyId): void
            {
                $this->consumed = true;
                $this->keyId = $keyId;
            }
        };
    }

    private function service(?string $token = self::TOKEN): EnrolmentService
    {
        $site = new class implements SiteSigner {
            public function publicKeyBase64(): string
            {
                return 'c2l0ZS1wdWJsaWMta2V5LTMyLWJ5dGVzLWxvbmch';
            }
            public function sign(string $message): string
            {
                return str_repeat("\0", 64);
            }
        };

        return new EnrolmentService($this->store, $site, $token, $this->ledger);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enrolment_token'        => self::TOKEN,
            'hub_public_key'         => $this->hubPublicKeyB64,
            'requested_capabilities' => ['hub:read', 'hub:deploy'],
        ], $overrides);
    }

    public function test_a_valid_handshake_registers_the_key_and_burns_the_token(): void
    {
        $result = $this->service()->enrol($this->payload());

        $this->assertStringStartsWith('hub-', $result['key_id']);
        $this->assertSame(['hub:read', 'hub:deploy'], $result['accepted_capabilities']);
        $this->assertNotEmpty($result['site_public_key']);

        $this->assertArrayHasKey($result['key_id'], $this->store->all());
        $this->assertSame($this->hubPublicKeyB64, $this->store->all()[$result['key_id']]['public_key']);
        $this->assertTrue($this->ledger->tokenConsumed());
    }

    public function test_capabilities_are_intersected_with_the_grantable_set(): void
    {
        $result = $this->service()->enrol($this->payload([
            'requested_capabilities' => ['hub:read', 'hub:deploy', 'hub:root', '*'],
        ]));

        $this->assertSame(['hub:read', 'hub:deploy'], $result['accepted_capabilities']);
    }

    public function test_no_requested_capabilities_defaults_to_read_only(): void
    {
        $result = $this->service()->enrol($this->payload(['requested_capabilities' => []]));

        $this->assertSame(['hub:read'], $result['accepted_capabilities']);
    }

    public function test_a_wrong_token_is_rejected_and_nothing_is_stored(): void
    {
        try {
            $this->service()->enrol($this->payload(['enrolment_token' => 'wrong']));
            $this->fail('Expected rejection.');
        } catch (EnrolmentException $e) {
            $this->assertSame(EnrolmentException::BAD_TOKEN, $e->reason());
        }

        $this->assertSame([], $this->store->all());
        $this->assertFalse($this->ledger->tokenConsumed());
    }

    public function test_enrolment_is_disabled_without_a_configured_token(): void
    {
        try {
            $this->service(null)->enrol($this->payload());
            $this->fail('Expected rejection.');
        } catch (EnrolmentException $e) {
            $this->assertSame(EnrolmentException::ENROLMENT_DISABLED, $e->reason());
        }
    }

    public function test_a_spent_token_cannot_be_reused(): void
    {
        $this->ledger->consumed = true;

        try {
            $this->service()->enrol($this->payload());
            $this->fail('Expected rejection.');
        } catch (EnrolmentException $e) {
            $this->assertSame(EnrolmentException::TOKEN_SPENT, $e->reason());
        }
    }

    public function test_a_malformed_hub_public_key_is_rejected(): void
    {
        foreach (['not base64!!', base64_encode('too-short'), ''] as $bad) {
            try {
                $this->service()->enrol($this->payload(['hub_public_key' => $bad]));
                $this->fail('Expected rejection.');
            } catch (EnrolmentException $e) {
                $this->assertSame(EnrolmentException::BAD_HUB_PUBLIC_KEY, $e->reason());
            }
        }
    }
}
