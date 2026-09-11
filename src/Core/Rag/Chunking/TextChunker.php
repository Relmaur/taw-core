<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Chunking;

/**
 * Paragraph-first text chunker for the embedding pipeline. No ABSPATH
 * guard — pure string logic, no WordPress dependency.
 *
 * Not a tokenizer — chunk/overlap sizes are char counts, a deliberate
 * placeholder heuristic (no tokenizer dependency exists in this codebase)
 * admin-tunable via {@see \TAW\Core\Rag\RagSettings}, flagged for retuning
 * once real content volume is known.
 */
final class TextChunker
{
    /**
     * @return list<string>
     */
    public function chunk(string $text, int $maxChars, int $overlap): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $maxChars = max(1, $maxChars);
        $overlap = max(0, min($overlap, $maxChars - 1));

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];
        $paragraphs = array_values(array_filter(
            array_map('trim', $paragraphs),
            static fn (string $p): bool => $p !== ''
        ));

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            foreach ($this->splitOversizedParagraph($paragraph, $maxChars) as $piece) {
                if ($buffer === '') {
                    $buffer = $piece;
                    continue;
                }

                $candidate = $buffer . "\n\n" . $piece;
                if (mb_strlen($candidate) <= $maxChars) {
                    $buffer = $candidate;
                    continue;
                }

                $chunks[] = $buffer;
                $buffer = $this->carryOverlap($buffer, $overlap) . $piece;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitOversizedParagraph(string $paragraph, int $maxChars): array
    {
        if (mb_strlen($paragraph) <= $maxChars) {
            return [$paragraph];
        }

        $pieces = [];
        $length = mb_strlen($paragraph);
        for ($offset = 0; $offset < $length; $offset += $maxChars) {
            $pieces[] = mb_substr($paragraph, $offset, $maxChars);
        }

        return $pieces;
    }

    private function carryOverlap(string $buffer, int $overlap): string
    {
        if ($overlap === 0) {
            return '';
        }

        return mb_substr($buffer, -$overlap) . "\n\n";
    }
}
