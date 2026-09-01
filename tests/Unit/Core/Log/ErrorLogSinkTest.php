<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Log;

use TAW\Core\Log\ErrorLogSink;
use TAW\Tests\TestCase;

final class ErrorLogSinkTest extends TestCase
{
    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
    }

    private function sink(): ErrorLogSink
    {
        return new ErrorLogSink(function (string $line): void {
            $this->captured[] = $line;
        });
    }

    public function test_writes_one_formatted_human_line_with_context_json(): void
    {
        $this->sink()->write($this->entry(['error' => 'timeout']));

        $this->assertSame(
            '[TAW] [ERROR] mail.emailit_send_failed: Emailit send failed. {"error":"timeout"}',
            $this->captured[0],
        );
    }

    public function test_omits_the_context_blob_when_empty(): void
    {
        $this->sink()->write($this->entry([]));

        $this->assertSame(
            '[TAW] [ERROR] mail.emailit_send_failed: Emailit send failed.',
            $this->captured[0],
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ts: string, level: string, code: string, message: string, context: array<string, mixed>, request_id: string}
     */
    private function entry(array $context): array
    {
        return [
            'ts'         => '2026-09-01T00:00:00+00:00',
            'level'      => 'error',
            'code'       => 'mail.emailit_send_failed',
            'message'    => 'Emailit send failed.',
            'context'    => $context,
            'request_id' => 'abcd1234-100',
        ];
    }
}
