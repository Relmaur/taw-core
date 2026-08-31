<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Orchestration;

use Brain\Monkey\Functions;
use TAW\Hub\Orchestration\Actions\SyncBlocksAction;
use TAW\Tests\TestCase;

final class SyncBlocksActionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];

        Functions\when('get_option')->alias(fn (string $k, $d = false) => $this->options[$k] ?? $d);
        Functions\when('update_option')->alias(function (string $k, $v) {
            $this->options[$k] = $v;
            return true;
        });
    }

    public function test_it_rejects_a_non_object_config(): void
    {
        $result = (new SyncBlocksAction())->run(['config' => 'nope']);

        $this->assertFalse($result->isOk());
    }

    public function test_a_first_merge_writes_version_1(): void
    {
        $result = (new SyncBlocksAction())->run(['config' => ['hero' => ['title' => 'Hi']]]);

        $this->assertTrue($result->isOk());
        $this->assertSame(1, $result->data()['applied_version']);
        $this->assertSame(['hero' => ['title' => 'Hi']], $this->options['taw_hub_block_config']['blocks']);
    }

    public function test_merge_preserves_untouched_blocks(): void
    {
        $action = new SyncBlocksAction();
        $action->run(['config' => ['hero' => ['a' => 1], 'cta' => ['b' => 2]]]);
        $action->run(['config' => ['hero' => ['a' => 9]], 'mode' => 'merge']);

        $blocks = $this->options['taw_hub_block_config']['blocks'];
        $this->assertSame(['a' => 9], $blocks['hero']);
        $this->assertSame(['b' => 2], $blocks['cta']);
        $this->assertSame(2, $this->options['taw_hub_block_config']['version']);
    }

    public function test_replace_drops_untouched_blocks(): void
    {
        $action = new SyncBlocksAction();
        $action->run(['config' => ['hero' => ['a' => 1], 'cta' => ['b' => 2]]]);
        $action->run(['config' => ['hero' => ['a' => 1]], 'mode' => 'replace']);

        $this->assertSame(['hero'], array_keys($this->options['taw_hub_block_config']['blocks']));
    }

    public function test_a_stale_expected_version_is_a_conflict(): void
    {
        $action = new SyncBlocksAction();
        $action->run(['config' => ['hero' => ['a' => 1]]]); // -> version 1

        $result = $action->run(['config' => ['hero' => ['a' => 2]], 'expected_version' => 0]);

        $this->assertFalse($result->isOk());
        $this->assertSame('version conflict', $result->error());
        $this->assertSame(1, $this->options['taw_hub_block_config']['version']); // unchanged
    }
}
