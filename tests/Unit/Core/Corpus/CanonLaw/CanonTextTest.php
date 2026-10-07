<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Corpus\CanonLaw;

use TAW\Core\Corpus\CanonLaw\CanonText;
use TAW\Tests\TestCase;

final class CanonTextTest extends TestCase
{
    public function test_plain_text_passes_through_unamended(): void
    {
        $parsed = CanonText::parse("§ 1. Primer párrafo.\n§ 2. Segundo párrafo.");

        $this->assertSame("§ 1. Primer párrafo.\n§ 2. Segundo párrafo.", $parsed['text']);
        $this->assertFalse($parsed['amended']);
        $this->assertNull($parsed['amendment']);
    }

    public function test_mark_before_the_paragraph_sign_is_stripped(): void
    {
        $parsed = CanonText::parse("n § 1. Se ha de creer con fe divina.\n§ 2. Otro.");

        $this->assertSame("§ 1. Se ha de creer con fe divina.\n§ 2. Otro.", $parsed['text']);
        $this->assertTrue($parsed['amended']);
    }

    public function test_mark_after_the_paragraph_sign_is_stripped_mid_text_too(): void
    {
        $parsed = CanonText::parse("§ 1. Texto previo.\n§ 2. n No se debe erigir un seminario.");

        $this->assertSame("§ 1. Texto previo.\n§ 2. No se debe erigir un seminario.", $parsed['text']);
        $this->assertTrue($parsed['amended']);
    }

    public function test_bare_leading_mark_is_stripped(): void
    {
        $this->assertSame('Es necesario que todo clérigo…', CanonText::parse('n Es necesario que todo clérigo…')['text']);
    }

    public function test_book_six_dash_mark_is_stripped(): void
    {
        $parsed = CanonText::parse("- § 1. Debe ser castigado…\n§ 2. Y además…");

        $this->assertSame("§ 1. Debe ser castigado…\n§ 2. Y además…", $parsed['text']);
        $this->assertTrue($parsed['amended']);
    }

    public function test_a_word_starting_with_n_is_not_a_mark(): void
    {
        $parsed = CanonText::parse("§ 1. nadie puede ser obligado.\nNo obstante, n.º 3.");

        $this->assertSame("§ 1. nadie puede ser obligado.\nNo obstante, n.º 3.", $parsed['text']);
        $this->assertFalse($parsed['amended']);
    }

    public function test_legend_line_is_dropped_in_both_spellings(): void
    {
        $this->assertSame('Texto.', CanonText::parse("n Texto.\n(n Indica que el texto corresponde a la nueva versión)")['text']);
        $this->assertSame('Texto.', CanonText::parse("Texto.\n(n: Indica que el texto corresponde a la nueva versión)")['text']);
    }

    public function test_original_wording_block_is_split_off_with_its_note(): void
    {
        $raw = "n § 1. Texto vigente.\n"
            . "(n Indica que el texto corresponde a la nueva versión)\n"
            . "[Redacción original de los cánones modificados por Su Santidad el Papa Francisco (cf. Motu Proprio De concordia inter Codices, 2016)]:\n"
            . "111 § 1. Texto original del 111.\n"
            . "112 § 1. Texto original del 112.";

        $parsed = CanonText::parse($raw);

        $this->assertSame('§ 1. Texto vigente.', $parsed['text']);
        $this->assertTrue($parsed['amended']);
        $this->assertSame(
            'Redacción original de los cánones modificados por Su Santidad el Papa Francisco (cf. Motu Proprio De concordia inter Codices, 2016)',
            $parsed['amendment']['note'] ?? null
        );
        $this->assertSame("111 § 1. Texto original del 111.\n112 § 1. Texto original del 112.", $parsed['amendment']['original_text'] ?? null);
    }

    public function test_a_block_with_no_wording_keeps_its_note_and_marks_the_canon_amended(): void
    {
        $parsed = CanonText::parse("Texto vigente.\n[Redacción original del canon modificado por Su Santidad el Papa Francisco (cf. Motu proprio Communis vita, 2019)]:");

        $this->assertSame('Texto vigente.', $parsed['text']);
        $this->assertTrue($parsed['amended']);
        $this->assertNotNull($parsed['amendment']);
        $this->assertNull($parsed['amendment']['original_text']);
        $this->assertStringContainsString('Communis vita', $parsed['amendment']['note'] ?? '');
    }

    public function test_clean_excerpt_strips_marks_and_folds_lines(): void
    {
        $this->assertSame(
            '§ 1. La <mark>alianza</mark> matrimonial… § 2. Por tanto…',
            CanonText::cleanExcerpt("n § 1. La <mark>alianza</mark> matrimonial…\n§ 2. n Por tanto…\n(n Indica que el texto corresponde a la nueva versión)")
        );
    }
}
