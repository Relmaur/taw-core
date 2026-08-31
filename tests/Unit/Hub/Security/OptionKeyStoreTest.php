<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use Brain\Monkey\Functions;
use TAW\Hub\Security\KeyRing;
use TAW\Hub\Security\OptionKeyStore;
use TAW\Tests\TestCase;

final class OptionKeyStoreTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(fn (string $k, $default = false) => $this->options[$k] ?? $default);
        Functions\when('update_option')->alias(function (string $k, $v) {
            $this->options[$k] = $v;
            return true;
        });
    }

    public function test_put_then_all_round_trips(): void
    {
        $store = new OptionKeyStore();
        $store->put('hub-x', ['public_key' => 'abc', 'capabilities' => ['hub:read']]);

        $this->assertSame(
            ['hub-x' => ['public_key' => 'abc', 'capabilities' => ['hub:read']]],
            $store->all(),
        );
    }

    public function test_forget_removes_an_entry(): void
    {
        $store = new OptionKeyStore();
        $store->put('hub-x', ['public_key' => 'abc']);
        $store->put('hub-y', ['public_key' => 'def']);
        $store->forget('hub-x');

        $this->assertSame(['hub-y'], array_keys($store->all()));
    }

    public function test_a_corrupt_option_value_reads_as_empty(): void
    {
        $this->options['taw_hub_enrolled_keys'] = 'not-an-array';

        $this->assertSame([], (new OptionKeyStore())->all());
    }

    public function test_keyring_merges_enrolled_keys(): void
    {
        $store = new OptionKeyStore();
        $store->put('hub-enrolled', ['public_key' => base64_encode(str_repeat('k', 32)), 'capabilities' => ['hub:read']]);

        $ring = KeyRing::fromEnvironment($store);

        $this->assertNotNull($ring->find('hub-enrolled'));
    }
}
