<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use TAW\Hub\Security\HubKey;
use TAW\Hub\Security\KeyRing;
use TAW\Tests\TestCase;

/**
 * KeyRing::fromArray() is the shared parser behind both direct construction
 * and the TAW_HUB_KEYS constant path. A single malformed entry must be
 * skipped, never fatal.
 */
final class KeyRingTest extends TestCase
{
    public function test_it_parses_well_formed_entries(): void
    {
        $ring = KeyRing::fromArray([
            'hub-prod' => ['secret' => 's3cr3t', 'capabilities' => ['hub:read', 'hub:deploy']],
        ]);

        $key = $ring->find('hub-prod');

        $this->assertInstanceOf(HubKey::class, $key);
        $this->assertSame('hub-prod', $key->id());
        $this->assertSame('s3cr3t', $key->secret());
        $this->assertSame(['hub:read', 'hub:deploy'], $key->capabilities());
    }

    public function test_an_unknown_key_id_resolves_to_null(): void
    {
        $ring = KeyRing::fromArray(['hub-prod' => ['secret' => 's3cr3t']]);

        $this->assertNull($ring->find('hub-staging'));
    }

    public function test_capabilities_default_to_empty(): void
    {
        $ring = KeyRing::fromArray(['hub-prod' => ['secret' => 's3cr3t']]);

        $this->assertSame([], $ring->find('hub-prod')?->capabilities());
    }

    public function test_entries_without_a_usable_secret_are_skipped(): void
    {
        $ring = KeyRing::fromArray([
            'no-secret'    => ['capabilities' => ['hub:read']],
            'empty-secret' => ['secret' => ''],
            'not-an-array' => 'nope',
            ''             => ['secret' => 's3cr3t'],
            'good'         => ['secret' => 's3cr3t'],
        ]);

        $this->assertNull($ring->find('no-secret'));
        $this->assertNull($ring->find('empty-secret'));
        $this->assertNull($ring->find('not-an-array'));
        $this->assertNull($ring->find(''));
        $this->assertInstanceOf(HubKey::class, $ring->find('good'));
    }

    public function test_non_string_capability_items_are_filtered_out(): void
    {
        $ring = KeyRing::fromArray([
            'hub-prod' => ['secret' => 's3cr3t', 'capabilities' => ['hub:read', 42, '', ['nested']]],
        ]);

        $this->assertSame(['hub:read'], $ring->find('hub-prod')?->capabilities());
    }

    public function test_an_empty_ring_reports_itself_empty(): void
    {
        $this->assertTrue(KeyRing::fromArray([])->isEmpty());
        $this->assertFalse(KeyRing::fromArray(['hub-prod' => ['secret' => 's3cr3t']])->isEmpty());
    }

    public function test_from_environment_without_the_constant_yields_an_empty_ring(): void
    {
        // TAW_HUB_KEYS is not defined in the unit-test process.
        $this->assertTrue(KeyRing::fromEnvironment()->isEmpty());
    }
}
