<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\OptionsPage;

use Brain\Monkey\Functions;
use TAW\Core\OptionsPage\OptionsPage;
use TAW\Tests\TestCase;

/**
 * A repeater inside a repeater row on an options page (Parallel Staff's
 * blog_widgets → cta_links): rows store the nested repeater as an array,
 * as Metabox does, and the page must render and save that shape.
 */
final class OptionsPageNestedRepeaterTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('register_setting')->alias(function (string $group, string $name, array $args = []): void {
            $this->settings[$name] = $args;
        });
        Functions\when('__')->returnArg(1);
        Functions\when('wp_json_encode')->alias(static fn ($v, int $flags = 0) => json_encode($v, $flags));
        Functions\when('sanitize_text_field')->alias(static fn ($v) => trim((string) $v));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('selected')->justReturn('');
        Functions\when('checked')->justReturn('');
        Functions\stubEscapeFunctions();
        Functions\stubTranslationFunctions();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(OptionsPage::class, 'fieldRegistry'))->setValue(null, []);
        parent::tearDown();
    }

    private function page(): OptionsPage
    {
        return new OptionsPage([
            'id'     => 'theme',
            'title'  => 'Theme',
            'fields' => [
                ['id' => 'blog_widgets', 'type' => 'repeater', 'max' => 5, 'fields' => [
                    ['id' => 'widget_type', 'type' => 'select', 'options' => ['call_to_action' => 'CTA']],
                    ['id' => 'title', 'type' => 'text'],
                    ['id' => 'cta_links', 'type' => 'repeater', 'fields' => [
                        ['id' => 'text', 'type' => 'text'],
                        ['id' => 'url', 'type' => 'url'],
                        ['id' => 'new_tab', 'type' => 'checkbox'],
                    ]],
                ]],
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function stored(): array
    {
        return [[
            'widget_type' => 'call_to_action',
            'title'       => 'Hire with us',
            'cta_links'   => [['text' => 'IT Staff Augmentation', 'url' => 'https://example.com/it', 'new_tab' => '0']],
        ]];
    }

    public function test_a_nested_repeater_stored_as_an_array_renders(): void
    {
        $render = new \ReflectionMethod(OptionsPage::class, 'render_field');
        $field  = $this->page()->getFieldConfig('blog_widgets');
        $this->assertNotNull($field);

        ob_start();
        try {
            $render->invoke($this->page(), $field, '_taw_blog_widgets', json_encode($this->stored()));
        } finally {
            $html = (string) ob_get_clean();
        }

        // The nested rows render, with the nested repeater's JSON in its hidden input.
        $this->assertStringContainsString('IT Staff Augmentation', $html);
        $this->assertStringContainsString('name="taw_repeater[_taw_blog_widgets][0][cta_links]"', $html);
        $this->assertStringNotContainsString('value="Array"', $html);
    }

    public function test_saving_keeps_the_nested_rows_whether_posted_as_json_or_as_an_array(): void
    {
        $page = $this->page();
        $page->register_settings();
        $save = $this->settings['_taw_blog_widgets']['sanitize_callback'];
        $want = json_encode($this->stored(), JSON_UNESCAPED_UNICODE);

        // The form posts each nested repeater as its hidden input's JSON string.
        $posted            = $this->stored();
        $posted[0]['cta_links'] = json_encode($posted[0]['cta_links']);
        $this->assertSame($want, $save(json_encode($posted)));

        // Code (or an unchanged save of what's stored) may hand it the array.
        $this->assertSame($want, $save(json_encode($this->stored())));
    }
}
