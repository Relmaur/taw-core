<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Log;

use TAW\Core\Log\Logger;
use TAW\Core\Log\LogSinkInterface;
use TAW\Tests\TestCase;

final class LoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        Logger::reset();
        parent::tearDown();
    }

    public function test_builds_a_structured_entry_and_writes_it_to_every_sink(): void
    {
        $a = $this->spySink();
        $b = $this->spySink();
        Logger::setSinks($a, $b);

        Logger::error('form.email_delivery_failed', 'nope', ['form_id' => 'contact']);

        $this->assertCount(1, $a->entries);
        $this->assertCount(1, $b->entries);

        $entry = $a->entries[0];
        $this->assertSame('error', $entry['level']);
        $this->assertSame('form.email_delivery_failed', $entry['code']);
        $this->assertSame('nope', $entry['message']);
        $this->assertSame(['form_id' => 'contact'], $entry['context']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $entry['ts']);
        $this->assertNotSame('', $entry['request_id']);
    }

    public function test_request_id_is_stable_across_calls_within_one_request(): void
    {
        $sink = $this->spySink();
        Logger::setSinks($sink);

        Logger::info('a.b', 'one');
        Logger::info('a.c', 'two');

        $this->assertSame($sink->entries[0]['request_id'], $sink->entries[1]['request_id']);
    }

    public function test_unknown_level_falls_back_to_info(): void
    {
        $sink = $this->spySink();
        Logger::setSinks($sink);

        Logger::log('bogus', 'x.y', 'msg');

        $this->assertSame('info', $sink->entries[0]['level']);
    }

    public function test_each_helper_maps_to_its_level(): void
    {
        $sink = $this->spySink();
        Logger::setSinks($sink);

        Logger::debug('c', 'm');
        Logger::info('c', 'm');
        Logger::notice('c', 'm');
        Logger::warning('c', 'm');
        Logger::error('c', 'm');
        Logger::critical('c', 'm');

        $this->assertSame(
            ['debug', 'info', 'notice', 'warning', 'error', 'critical'],
            array_column($sink->entries, 'level'),
        );
    }

    private function spySink(): LogSinkInterface
    {
        return new class implements LogSinkInterface {
            /** @var list<array<string, mixed>> */
            public array $entries = [];

            public function write(array $entry): void
            {
                $this->entries[] = $entry;
            }
        };
    }
}
