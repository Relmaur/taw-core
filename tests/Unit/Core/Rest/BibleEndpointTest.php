<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rest;

use Brain\Monkey\Functions;
use TAW\Core\Corpus\Bible\BibleReader;
use TAW\Core\Rest\BibleEndpoint;
use TAW\Tests\TestCase;

final class BibleEndpointTest extends TestCase
{
    private function reader(): BibleReader
    {
        return $this->callMethod(new BibleEndpoint(), 'reader');
    }

    public function test_reader_returns_a_bible_reader_by_default(): void
    {
        Functions\when('apply_filters')->returnArg(2);

        $this->assertInstanceOf(BibleReader::class, $this->reader());
    }

    public function test_reader_respects_a_filtered_replacement(): void
    {
        $custom = new class extends BibleReader {
        };

        Functions\when('apply_filters')->justReturn($custom);

        $this->assertSame($custom, $this->reader());
    }

    public function test_reader_falls_back_to_default_when_the_filter_returns_something_invalid(): void
    {
        Functions\when('apply_filters')->justReturn('not a reader');

        $this->assertInstanceOf(BibleReader::class, $this->reader());
    }
}
