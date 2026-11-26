<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Seo;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TAW\Core\Seo\Schema;
use TAW\Tests\TestCase;

/**
 * `taw_seo_schema_graph`: a theme adds to the JSON-LD nodes (an
 * Organization description, areaServed, sameAs from its own options).
 * Whatever it returns, the page gets valid JSON or no script at all.
 */
final class SchemaGraphFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPushed();

        Functions\when('get_option')->alias(fn (string $key, mixed $default = false) => $default);
        Functions\when('home_url')->alias(fn (string $path = '') => 'https://example.com' . $path);
        Functions\when('get_bloginfo')->justReturn('My Site');
        Functions\when('is_singular')->justReturn(false);
        Functions\when('wp_json_encode')->alias(fn ($value, int $flags = 0) => json_encode($value, $flags));
    }

    protected function tearDown(): void
    {
        $this->resetPushed();
        parent::tearDown();
    }

    public function test_the_filter_receives_the_nodes_and_its_additions_print(): void
    {
        Filters\expectApplied('taw_seo_schema_graph')->once()->andReturnUsing(function (array $graph) {
            foreach ($graph as $i => $node) {
                if (($node['@id'] ?? '') === 'https://example.com/#organization') {
                    $graph[$i]['description'] = 'Bookkeeping for small firms.';
                    $graph[$i]['areaServed'] = ['Austin', 'Round Rock'];
                }
            }
            return $graph;
        });

        $document = $this->renderedDocument();

        $this->assertSame('https://schema.org', $document['@context']);
        $this->assertSame('Organization', $document['@graph'][0]['@type']);
        $this->assertSame('Bookkeeping for small firms.', $document['@graph'][0]['description']);
        $this->assertSame(['Austin', 'Round Rock'], $document['@graph'][0]['areaServed']);
        $this->assertSame('WebSite', $document['@graph'][1]['@type']);
    }

    public function test_without_a_filter_the_graph_is_unchanged(): void
    {
        $document = $this->renderedDocument();

        $this->assertSame([
            ['@type' => 'Organization', '@id' => 'https://example.com/#organization', 'name' => 'My Site', 'url' => 'https://example.com/'],
            ['@type' => 'WebSite', '@id' => 'https://example.com/#website', 'url' => 'https://example.com/', 'name' => 'My Site',
                'publisher' => ['@id' => 'https://example.com/#organization']],
        ], $document['@graph']);
    }

    public function test_pushed_nodes_reach_the_filter(): void
    {
        Schema::push(['@type' => 'FAQPage', 'mainEntity' => []]);
        $types = [];
        Filters\expectApplied('taw_seo_schema_graph')->andReturnUsing(function (array $graph) use (&$types) {
            $types = array_column($graph, '@type');
            return $graph;
        });

        $this->render();

        $this->assertSame(['Organization', 'WebSite', 'FAQPage'], $types);
    }

    public function test_nodes_that_are_not_arrays_are_dropped(): void
    {
        Filters\expectApplied('taw_seo_schema_graph')->andReturnUsing(
            fn (array $graph) => ['first' => 'garbage', 'second' => $graph[1], 3 => null, 4 => 42]
        );

        $document = $this->renderedDocument();

        $this->assertSame([0], array_keys($document['@graph']), 'a list again, not an object');
        $this->assertSame('WebSite', $document['@graph'][0]['@type']);
    }

    public function test_a_non_array_answer_prints_nothing(): void
    {
        Filters\expectApplied('taw_seo_schema_graph')->andReturn('garbage');

        $this->assertSame('', $this->render());
    }

    public function test_an_empty_answer_prints_nothing(): void
    {
        Filters\expectApplied('taw_seo_schema_graph')->andReturn([]);

        $this->assertSame('', $this->render());
    }

    public function test_a_graph_json_cannot_encode_prints_nothing(): void
    {
        Filters\expectApplied('taw_seo_schema_graph')->andReturn([['@type' => 'Thing', 'name' => "\xB1\x31"]]);

        $this->assertSame('', $this->render());
    }

    public function test_a_string_cannot_close_the_script_element(): void
    {
        Filters\expectApplied('taw_seo_schema_graph')->andReturn([['@type' => 'Thing', 'name' => '</script><script>alert(1)</script>']]);

        $html = $this->render();

        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertSame('</script><script>alert(1)</script>', $this->decode($html)['@graph'][0]['name']);
    }

    /* ── helpers ──────────────────────────────────────────────────────── */

    private function render(): string
    {
        $schema = (new \ReflectionClass(Schema::class))->newInstanceWithoutConstructor();
        ob_start();
        try {
            $schema->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return array<string, mixed> */
    private function renderedDocument(): array
    {
        return $this->decode($this->render());
    }

    /** @return array<string, mixed> */
    private function decode(string $html): array
    {
        $this->assertMatchesRegularExpression('#^<script type="application/ld\+json">.*</script>\n$#s', $html);
        $json = substr($html, strlen('<script type="application/ld+json">'), -strlen("</script>\n"));
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);

        return $document;
    }

    private function resetPushed(): void
    {
        (new \ReflectionProperty(Schema::class, 'graph'))->setValue(null, []);
    }
}
