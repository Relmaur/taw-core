<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Log;

use TAW\Core\Log\JsonlFileSink;
use TAW\Tests\TestCase;

final class JsonlFileSinkTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/taw-jsonl-sink-' . getmypid() . '-' . uniqid();
    }

    protected function tearDown(): void
    {
        $it = @new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it ?: [] as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_appends_one_json_object_per_line(): void
    {
        $sink = new JsonlFileSink($this->dir);
        $sink->write($this->entry('form.a', 'first'));
        $sink->write($this->entry('form.b', 'second'));

        $lines = file($this->dir . '/taw.log.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $this->assertSame('form.a', json_decode($lines[0], true)['code']);
        $this->assertSame('second', json_decode($lines[1], true)['message']);
    }

    public function test_seeds_the_directory_with_deny_all_guards(): void
    {
        (new JsonlFileSink($this->dir))->write($this->entry('x.y', 'z'));

        $this->assertFileExists($this->dir . '/.htaccess');
        $this->assertFileExists($this->dir . '/index.php');
        $this->assertStringContainsString('denied', (string) file_get_contents($this->dir . '/.htaccess'));
    }

    public function test_rotates_once_the_live_file_would_exceed_max_bytes(): void
    {
        $sink = new JsonlFileSink($this->dir, maxBytes: 300, keep: 2);

        for ($i = 0; $i < 40; $i++) {
            $sink->write($this->entry('n.' . $i, str_repeat('x', 50)));
        }

        $this->assertFileExists($this->dir . '/taw.log.jsonl');
        $this->assertFileExists($this->dir . '/taw.log.1.jsonl');
        $this->assertFileExists($this->dir . '/taw.log.2.jsonl');
        // keep = 2 → a third rotated file is never retained.
        $this->assertFileDoesNotExist($this->dir . '/taw.log.3.jsonl');
    }

    /**
     * @return array{ts: string, level: string, code: string, message: string, context: array<string, mixed>, request_id: string}
     */
    private function entry(string $code, string $message): array
    {
        return [
            'ts'         => '2026-09-01T00:00:00+00:00',
            'level'      => 'info',
            'code'       => $code,
            'message'    => $message,
            'context'    => [],
            'request_id' => 'r-1',
        ];
    }
}
