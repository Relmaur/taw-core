<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use Brain\Monkey\Functions;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

/**
 * gradient_text stores an ordered JSON array of `{text, highlighted}`
 * segments — e.g. a "How can **we help**?" heading needs plain-gradient-
 * plain, which a single trailing-highlight convention can't express.
 */
final class GradientTextFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('sanitize_text_field')->alias(
            static fn (mixed $v): string => trim(preg_replace('/[\r\n\t]+/', ' ', (string) $v) ?? '')
        );
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('esc_html')->alias(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
        Functions\when('esc_attr')->alias(static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES));
    }

    public function test_sanitizes_mixed_plain_and_highlighted_segments(): void
    {
        $input = json_encode([
            ['text' => 'How can ', 'highlighted' => false],
            ['text' => 'we help', 'highlighted' => true],
            ['text' => '?', 'highlighted' => false],
        ]);

        $stored = Metabox::sanitizeGradientTextValue($input);

        $this->assertSame([
            ['text' => 'How can ', 'highlighted' => false],
            ['text' => 'we help', 'highlighted' => true],
            ['text' => '?', 'highlighted' => false],
        ], json_decode($stored, true));
    }

    /**
     * Regression: sanitize_text_field()'s own trim() destroyed the word-
     * boundary space between a highlighted phrase and its neighbor — the
     * field type's primary use case (a highlighted phrase mid-sentence, not
     * only ever the trailing segment). Reproduced and reported against
     * v1.37.0 while migrating real heading+highlight pairs to gradient_text.
     */
    public function test_boundary_space_between_a_highlighted_phrase_and_its_neighbor_survives(): void
    {
        $input = json_encode([
            ['text' => 'Three ways', 'highlighted' => true],
            ['text' => ' we help you own more and rent less.', 'highlighted' => false],
        ]);

        $sanitized = json_decode(Metabox::sanitizeGradientTextValue($input), true);
        $rendered = Metabox::renderGradientText($sanitized, 'hl');

        $this->assertSame('Three ways', $sanitized[0]['text']);
        $this->assertSame(' we help you own more and rent less.', $sanitized[1]['text']);
        $this->assertSame(
            '<span class="hl">Three ways</span> we help you own more and rent less.',
            $rendered
        );
    }

    public function test_a_trailing_boundary_space_on_a_highlighted_segment_survives(): void
    {
        $input = json_encode([
            ['text' => 'Save time ', 'highlighted' => true],
            ['text' => 'and money', 'highlighted' => false],
        ]);

        $sanitized = json_decode(Metabox::sanitizeGradientTextValue($input), true);

        $this->assertSame(
            '<span class="hl">Save time </span>and money',
            Metabox::renderGradientText($sanitized, 'hl')
        );
    }

    public function test_a_whitespace_only_segment_is_still_dropped_not_padded(): void
    {
        $input = json_encode([
            ['text' => 'A', 'highlighted' => false],
            ['text' => '   ', 'highlighted' => false],
            ['text' => 'B', 'highlighted' => false],
        ]);

        $stored = json_decode(Metabox::sanitizeGradientTextValue($input), true);

        $this->assertSame([
            ['text' => 'A', 'highlighted' => false],
            ['text' => 'B', 'highlighted' => false],
        ], $stored);
    }

    public function test_segment_with_empty_text_is_dropped(): void
    {
        $input = json_encode([
            ['text' => '', 'highlighted' => true],
            ['text' => 'Kept', 'highlighted' => false],
        ]);

        $stored = Metabox::sanitizeGradientTextValue($input);

        $this->assertSame([['text' => 'Kept', 'highlighted' => false]], json_decode($stored, true));
    }

    public function test_non_boolean_highlighted_values_normalize_to_real_booleans(): void
    {
        $input = json_encode([
            ['text' => 'A', 'highlighted' => '1'],
            ['text' => 'B', 'highlighted' => 1],
            ['text' => 'C', 'highlighted' => 'yes'],
        ]);

        $stored = json_decode(Metabox::sanitizeGradientTextValue($input), true);

        $this->assertTrue($stored[0]['highlighted']);
        $this->assertTrue($stored[1]['highlighted']);
        $this->assertFalse($stored[2]['highlighted']);
    }

    public function test_invalid_json_returns_empty_array(): void
    {
        $this->assertSame('[]', Metabox::sanitizeGradientTextValue('not json'));
    }

    public function test_renders_plain_and_highlighted_spans(): void
    {
        $segments = [
            ['text' => 'How can ', 'highlighted' => false],
            ['text' => 'we help', 'highlighted' => true],
            ['text' => '?', 'highlighted' => false],
        ];

        $html = Metabox::renderGradientText($segments, 'bg-clip-text text-transparent bg-gradient-to-r from-cyan-500 to-blue-500');

        $this->assertSame(
            'How can <span class="bg-clip-text text-transparent bg-gradient-to-r from-cyan-500 to-blue-500">we help</span>?',
            $html
        );
    }

    public function test_render_accepts_a_json_string_directly(): void
    {
        $json = json_encode([['text' => 'Hi', 'highlighted' => true]]);

        $this->assertSame('<span class="hl">Hi</span>', Metabox::renderGradientText($json, 'hl'));
    }
}
