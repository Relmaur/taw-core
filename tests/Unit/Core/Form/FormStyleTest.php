<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use TAW\Core\Form\Form;
use TAW\Tests\TestCase;

/**
 * form.css stays in <head> but doesn't block rendering (issue #186): forms
 * register on every request, so the plain <link> held up first paint on
 * every page, form or not. Keeping it in <head> keeps the cascade order the
 * themes' own form styles rely on.
 */
final class FormStyleTest extends TestCase
{
    private const TAG = "<link rel='stylesheet' id='taw-form-css' href='https://example.test/form.css?ver=1' media='all' />\n";

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('esc_url')->returnArg();
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());
        parent::tearDown();
    }

    public function test_the_form_stylesheet_loads_without_blocking_rendering(): void
    {
        $html = Form::asyncStyleTag(self::TAG, 'taw-form');

        $this->assertStringContainsString('<link rel="stylesheet" id="taw-form-css" href="https://example.test/form.css?ver=1" media="print" onload="this.media=\'all\'">', $html);
        $this->assertStringContainsString('<noscript><link rel="stylesheet" id="taw-form-css" href="https://example.test/form.css?ver=1"></noscript>', $html);
    }

    public function test_the_href_a_cache_plugin_rewrote_is_kept(): void
    {
        $tag = str_replace('https://example.test/form.css?ver=1', 'https://cdn.example/hb/08a2e70c.css', self::TAG);

        $this->assertStringContainsString('href="https://cdn.example/hb/08a2e70c.css" media="print"', Form::asyncStyleTag($tag, 'taw-form'));
    }

    public function test_other_stylesheets_and_already_async_tags_are_left_alone(): void
    {
        $this->assertSame(self::TAG, Form::asyncStyleTag(self::TAG, 'theme-app-style'));

        $async = '<link rel="stylesheet" id="taw-form-css" href="x.css" media="print" onload="this.media=\'all\'">';
        $this->assertSame($async, Form::asyncStyleTag($async, 'taw-form'), "a theme's own async filter ran first");
    }

    public function test_the_filter_keeps_the_plain_link(): void
    {
        Filters\expectApplied('taw_form_css_async')->once()->andReturn(false);

        $this->assertSame(self::TAG, Form::asyncStyleTag(self::TAG, 'taw-form'));
    }

    public function test_many_forms_hook_it_once(): void
    {
        (new \ReflectionProperty(Form::class, 'styleHooked'))->setValue(null, false);

        Actions\expectAdded('wp_enqueue_scripts')->once();
        Filters\expectAdded('style_loader_tag')->once();

        Form::register(['id' => 'style_once_a']);
        Form::register(['id' => 'style_once_b']);
    }
}
