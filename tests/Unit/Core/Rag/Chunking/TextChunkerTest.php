<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Chunking;

use TAW\Core\Rag\Chunking\TextChunker;
use TAW\Tests\TestCase;

final class TextChunkerTest extends TestCase
{
    public function test_empty_text_produces_no_chunks(): void
    {
        $this->assertSame([], (new TextChunker())->chunk('   ', 100, 10));
    }

    public function test_short_text_is_a_single_chunk(): void
    {
        $chunks = (new TextChunker())->chunk('Hello world.', 100, 10);

        $this->assertSame(['Hello world.'], $chunks);
    }

    public function test_text_longer_than_max_chars_splits_into_multiple_chunks(): void
    {
        $text = implode("\n\n", [
            str_repeat('a', 40),
            str_repeat('b', 40),
            str_repeat('c', 40),
        ]);

        $chunks = (new TextChunker())->chunk($text, 50, 0);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(50, mb_strlen($chunk));
        }
    }

    public function test_no_chunk_is_empty(): void
    {
        $text = implode("\n\n", array_fill(0, 5, str_repeat('x', 30)));

        $chunks = (new TextChunker())->chunk($text, 60, 15);

        foreach ($chunks as $chunk) {
            $this->assertNotSame('', trim($chunk));
        }
    }

    public function test_overlap_carries_tail_of_previous_chunk_into_the_next(): void
    {
        $text = str_repeat('a', 30) . "\n\n" . str_repeat('b', 30);

        $chunks = (new TextChunker())->chunk($text, 35, 10);

        $this->assertCount(2, $chunks);
        $tailOfFirst = mb_substr($chunks[0], -10);
        $this->assertStringStartsWith($tailOfFirst, $chunks[1]);
    }

    public function test_a_single_paragraph_far_larger_than_max_chars_is_hard_split(): void
    {
        $text = str_repeat('a', 250);

        $chunks = (new TextChunker())->chunk($text, 100, 0);

        $this->assertCount(3, $chunks);
        $this->assertSame(250, mb_strlen(implode('', $chunks)));
    }

    public function test_reassembled_chunks_contain_every_paragraph(): void
    {
        $text = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";

        $chunks = (new TextChunker())->chunk($text, 25, 0);
        $joined = implode(' ', $chunks);

        $this->assertStringContainsString('First paragraph.', $joined);
        $this->assertStringContainsString('Second paragraph.', $joined);
        $this->assertStringContainsString('Third paragraph.', $joined);
    }
}
