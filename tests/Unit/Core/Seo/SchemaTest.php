<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Seo;

use Brain\Monkey\Functions;
use TAW\Core\Seo\Schema;
use TAW\Tests\TestCase;

/**
 * Schema::faqPage() is the one piece of the JSON-LD graph builder that's
 * pure data-shaping (no WP query context involved) — it's what the FAQ
 * block calls to turn its repeater rows into a FAQPage node, so a
 * malformed or half-empty row here would silently ship invalid structured
 * data to every page using that block.
 */
final class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_strip_all_tags')->alias(fn (string $text) => strip_tags($text));
    }

    public function test_builds_a_question_node_per_row(): void
    {
        $node = Schema::faqPage([
            ['question' => 'What is TAW?', 'answer' => 'A WordPress framework.'],
            ['question' => 'Is it free?', 'answer' => 'Yes.'],
        ]);

        $this->assertSame('FAQPage', $node['@type']);
        $this->assertCount(2, $node['mainEntity']);
        $this->assertSame('What is TAW?', $node['mainEntity'][0]['name']);
        $this->assertSame('Question', $node['mainEntity'][0]['@type']);
        $this->assertSame('A WordPress framework.', $node['mainEntity'][0]['acceptedAnswer']['text']);
        $this->assertSame('Answer', $node['mainEntity'][0]['acceptedAnswer']['@type']);
    }

    public function test_skips_rows_missing_a_question_or_answer(): void
    {
        $node = Schema::faqPage([
            ['question' => 'Complete row', 'answer' => 'Has both.'],
            ['question' => '', 'answer' => 'No question.'],
            ['question' => 'No answer', 'answer' => ''],
            ['question' => '   ', 'answer' => 'Whitespace-only question.'],
        ]);

        $this->assertCount(1, $node['mainEntity']);
        $this->assertSame('Complete row', $node['mainEntity'][0]['name']);
    }

    public function test_returns_empty_main_entity_for_no_items(): void
    {
        $node = Schema::faqPage([]);

        $this->assertSame('FAQPage', $node['@type']);
        $this->assertSame([], $node['mainEntity']);
    }

    public function test_strips_html_from_answers(): void
    {
        $node = Schema::faqPage([
            ['question' => 'Q', 'answer' => '<p>Rich <strong>text</strong> answer.</p>'],
        ]);

        $this->assertSame('Rich text answer.', $node['mainEntity'][0]['acceptedAnswer']['text']);
    }

    /**
     * The settings page (home of SeoMeta::OUTPUT_MODE_FIELD, the manual
     * override for unreliable plugin auto-detection) must stay reachable
     * even when a plugin is currently detected as active — otherwise a
     * site owner could never flip 'force_on' to correct a false negative.
     * Only the JSON-LD render itself should stand down.
     */
    public function test_constructor_registers_options_but_not_render_when_seo_plugin_active(): void
    {
        Functions\when('get_option')->justReturn('force_off');

        Functions\expect('add_action')
            ->once()
            ->with('init', \Mockery::type('array'));

        $this->assertInstanceOf(Schema::class, new Schema());
    }

    public function test_constructor_registers_options_and_render_when_no_seo_plugin_active(): void
    {
        Functions\when('get_option')->justReturn('force_on');

        Functions\expect('add_action')
            ->once()
            ->with('init', \Mockery::type('array'));

        Functions\expect('add_action')
            ->once()
            ->with('wp_footer', \Mockery::type('array'), 99);

        $this->assertInstanceOf(Schema::class, new Schema());
    }
}
