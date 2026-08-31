<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Orchestration;

use Brain\Monkey\Functions;
use TAW\Hub\Orchestration\Actions\FlushCachesAction;
use TAW\Tests\TestCase;

final class FlushCachesActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_cache_flush')->justReturn(true);
        Functions\when('flush_rewrite_rules')->justReturn(null);
        Functions\when('do_action')->justReturn(null);
    }

    public function test_default_scopes_flush_object_and_rewrites(): void
    {
        $result = (new FlushCachesAction())->run([]);

        $this->assertTrue($result->isOk());
        $this->assertContains('object', $result->data()['flushed']);
        $this->assertContains('rewrites', $result->data()['flushed']);
    }

    public function test_an_explicit_scope_list_is_honoured(): void
    {
        $result = (new FlushCachesAction())->run(['scopes' => ['object']]);

        $this->assertSame(['object'], $result->data()['flushed']);
    }

    public function test_an_unknown_scope_is_ignored_and_falls_back_to_all(): void
    {
        $result = (new FlushCachesAction())->run(['scopes' => ['bogus']]);

        // intersection is empty -> treated as "all"
        $this->assertContains('object', $result->data()['flushed']);
    }

    public function test_capability_is_maintenance(): void
    {
        $this->assertSame('hub:maintenance', (new FlushCachesAction())->capability());
    }
}
