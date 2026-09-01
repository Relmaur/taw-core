<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Log;

use TAW\Core\Log\LogReader;
use TAW\Tests\TestCase;

final class LogReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-log-reader-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_returns_empty_when_the_log_file_is_absent(): void
    {
        $this->assertSame([], (new LogReader($this->dir))->tail());
    }

    public function test_tails_the_newest_entries_in_chronological_order(): void
    {
        $this->seed([
            ['code' => 't.a', 'message' => 'a', 'level' => 'info'],
            ['code' => 't.b', 'message' => 'b', 'level' => 'info'],
            ['code' => 't.c', 'message' => 'c', 'level' => 'info'],
            ['code' => 't.d', 'message' => 'd', 'level' => 'info'],
        ]);

        $entries = (new LogReader($this->dir))->tail(2);

        $this->assertCount(2, $entries);
        $this->assertSame('c', $entries[0]['message']);
        $this->assertSame('d', $entries[1]['message']);
    }

    public function test_filters_by_level(): void
    {
        $this->seed([
            ['code' => 't.a', 'message' => 'a', 'level' => 'info'],
            ['code' => 't.b', 'message' => 'b', 'level' => 'error'],
            ['code' => 't.c', 'message' => 'c', 'level' => 'warning'],
            ['code' => 't.d', 'message' => 'd', 'level' => 'error'],
        ]);

        $entries = (new LogReader($this->dir))->tail(100, 'error');

        $this->assertSame(['b', 'd'], array_column($entries, 'message'));
    }

    public function test_filters_by_code_prefix(): void
    {
        $this->seed([
            ['code' => 'form.email_delivery_failed', 'message' => 'a', 'level' => 'error'],
            ['code' => 'mail.emailit_send_failed', 'message' => 'b', 'level' => 'error'],
            ['code' => 'form.turnstile_request_failed', 'message' => 'c', 'level' => 'warning'],
        ]);

        $entries = (new LogReader($this->dir))->tail(100, null, null, 'form.');

        $this->assertSame(['a', 'c'], array_column($entries, 'message'));
    }

    public function test_filters_by_since_timestamp(): void
    {
        $this->seed([
            ['ts' => '2026-09-01T08:00:00+00:00', 'code' => 't.a', 'message' => 'a', 'level' => 'info'],
            ['ts' => '2026-09-01T12:00:00+00:00', 'code' => 't.b', 'message' => 'b', 'level' => 'info'],
            ['ts' => '2026-09-01T16:00:00+00:00', 'code' => 't.c', 'message' => 'c', 'level' => 'info'],
        ]);

        $entries = (new LogReader($this->dir))->tail(100, null, '2026-09-01T12:00:00+00:00');

        $this->assertSame(['b', 'c'], array_column($entries, 'message'));
    }

    public function test_skips_malformed_lines(): void
    {
        file_put_contents(
            $this->dir . '/taw.log.jsonl',
            "not json at all\n" . json_encode(['ts' => 'x', 'code' => 't.ok', 'message' => 'ok', 'level' => 'info']) . "\n",
        );

        $entries = (new LogReader($this->dir))->tail();

        $this->assertCount(1, $entries);
        $this->assertSame('ok', $entries[0]['message']);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function seed(array $entries): void
    {
        $lines = '';
        foreach ($entries as $entry) {
            $lines .= json_encode($entry + ['ts' => '2026-09-01T00:00:00+00:00', 'context' => [], 'request_id' => 'r']) . "\n";
        }
        file_put_contents($this->dir . '/taw.log.jsonl', $lines);
    }
}
