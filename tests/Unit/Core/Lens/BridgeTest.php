<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Lens;

use PHPUnit\Framework\TestCase;
use TAW\Core\Lens\Bridge;

final class BridgeTest extends TestCase
{
    private string $theme = '';

    protected function setUp(): void
    {
        $this->theme = sys_get_temp_dir() . '/taw-lens-bridge-' . uniqid();
        mkdir($this->theme . '/.taw/lens', 0777, true);
        Bridge::useAliveCheck(static fn (int $pid): bool => $pid === 100);
    }

    protected function tearDown(): void
    {
        Bridge::useAliveCheck(null);
        exec('rm -rf ' . escapeshellarg($this->theme));
    }

    /** @param array<string, mixed> $info */
    private function record(array $info): void
    {
        file_put_contents($this->theme . '/' . Bridge::RUN_FILE, (string) json_encode($info));
    }

    public function test_it_reads_the_running_bridge(): void
    {
        $this->record(['pid' => 100, 'port' => 47615, 'token' => str_repeat('a1', 24), 'origins' => ['https://acme.local', 7], 'protocol' => 1, 'started' => '2026-11-07T10:00:00Z']);

        $this->assertSame([
            'pid'      => 100,
            'port'     => 47615,
            'token'    => str_repeat('a1', 24),
            'origins'  => ['https://acme.local'],
            'protocol' => 1,
            'started'  => '2026-11-07T10:00:00Z',
        ], Bridge::current($this->theme));
        $this->assertSame(['running' => true, 'url' => 'http://127.0.0.1:47615', 'token' => str_repeat('a1', 24), 'protocol' => 1], Bridge::forPage($this->theme));
    }

    public function test_none_when_missing_stale_or_malformed(): void
    {
        $this->assertNull(Bridge::current($this->theme));
        $this->assertSame(['running' => false], Bridge::forPage($this->theme));

        $this->record(['pid' => 200, 'port' => 47615, 'token' => str_repeat('a1', 24)]);
        $this->assertNull(Bridge::current($this->theme), 'its process is gone');

        foreach ([
            ['pid' => 100, 'port' => 0, 'token' => str_repeat('a1', 24)],
            ['pid' => 100, 'port' => 47615, 'token' => 'short'],
            ['pid' => 100, 'port' => 47615, 'token' => str_repeat('Z', 48)],
            ['pid' => '100', 'port' => 47615, 'token' => str_repeat('a1', 24)],
        ] as $bad) {
            $this->record($bad);
            $this->assertNull(Bridge::current($this->theme), (string) json_encode($bad));
        }
        file_put_contents($this->theme . '/' . Bridge::RUN_FILE, '{nope');
        $this->assertNull(Bridge::current($this->theme));
    }

    public function test_the_last_session_for_resume(): void
    {
        $this->assertNull(Bridge::lastSession($this->theme));
        file_put_contents($this->theme . '/' . Bridge::LAST_SESSION, "5d7e716e-881d-4f40-8acd-8d5e2c5c722d\n");
        $this->assertSame('5d7e716e-881d-4f40-8acd-8d5e2c5c722d', Bridge::lastSession($this->theme));
        file_put_contents($this->theme . '/' . Bridge::LAST_SESSION, '../../etc/passwd');
        $this->assertNull(Bridge::lastSession($this->theme));
    }

    public function test_the_php_and_node_halves_agree(): void
    {
        $bridge = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/lens-bridge/lib/bridge.mjs');
        $runfile = (string) file_get_contents(dirname(__DIR__, 4) . '/resources/lens-bridge/lib/runfile.mjs');

        $this->assertStringContainsString('export const PROTOCOL = ' . Bridge::PROTOCOL . ';', $bridge);
        $this->assertSame('.taw/lens/bridge.json', Bridge::RUN_FILE);
        $this->assertStringContainsString("join('.taw', 'lens')", $runfile);
        $this->assertStringContainsString("export const RUN_FILE = 'bridge.json';", $runfile);
        $this->assertStringContainsString("'last-session'", $runfile);
    }
}
