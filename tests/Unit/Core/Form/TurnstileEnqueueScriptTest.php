<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\Turnstile;
use TAW\Tests\TestCase;

/**
 * Covers Turnstile::enqueueScript()'s output — the shared TAWTurnstile JS
 * helper that replaces Cloudflare's implicit (auto-scanning) render mode.
 * Implicit mode only ever scans the DOM once, at api.js's own load, so a
 * .cf-turnstile container that appears later (via any client-side
 * navigation, e.g. a page-transition library swapping DOM) never gets a
 * widget rendered into it. Explicit mode plus this helper fixes that
 * structurally — see the method's own doc comment for the full reasoning.
 */
final class TurnstileEnqueueScriptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('esc_js')->returnArg();
        Functions\when('esc_url_raw')->returnArg();

        // $scriptEnqueued is a real static property — reset it via
        // reflection before each test so "print once per request" doesn't
        // leak across tests sharing this process.
        $prop = new \ReflectionProperty(Turnstile::class, 'scriptEnqueued');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }

    private function captureOutput(): string
    {
        ob_start();
        Turnstile::enqueueScript();
        return (string) ob_get_clean();
    }

    public function test_prints_the_taw_turnstile_helper(): void
    {
        $output = $this->captureOutput();

        $this->assertStringContainsString('window.TAWTurnstile', $output);
        $this->assertStringContainsString('render:', $output);
        $this->assertStringContainsString('remove:', $output);
    }

    public function test_script_url_uses_explicit_render_mode(): void
    {
        $output = $this->captureOutput();

        $this->assertStringContainsString('render=explicit', $output);
    }

    public function test_script_url_has_no_html_entity_encoded_ampersand(): void
    {
        // esc_url() would have encoded any '&' as '&#038;', which is wrong
        // inside a JS string literal — script raw-text isn't HTML-entity
        // decoded by the parser, so that would corrupt the URL. Guards
        // against the regression even though today's URL has no '&' to
        // trigger it (esc_url_raw() is the correct choice here instead).
        $output = $this->captureOutput();

        $this->assertStringNotContainsString('&#038;', $output);
    }

    public function test_only_prints_once_across_multiple_calls(): void
    {
        // "window.TAWTurnstile" legitimately appears twice in a single
        // print (the `window.TAWTurnstile = window.TAWTurnstile ||` guard
        // itself), so assert against that single-call baseline rather than
        // a hardcoded 1 — three calls must produce the same count as one,
        // not triple it.
        $singleCallCount = substr_count($this->captureOutput(), 'window.TAWTurnstile');

        $prop = new \ReflectionProperty(Turnstile::class, 'scriptEnqueued');
        $prop->setAccessible(true);
        $prop->setValue(null, false);

        ob_start();
        Turnstile::enqueueScript();
        Turnstile::enqueueScript();
        Turnstile::enqueueScript();
        $output = (string) ob_get_clean();

        $this->assertSame($singleCallCount, substr_count($output, 'window.TAWTurnstile'));
    }
}
