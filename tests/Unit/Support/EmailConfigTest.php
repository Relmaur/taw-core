<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Support;

use Brain\Monkey\Functions;
use TAW\Support\EmailConfig;
use TAW\Tests\TestCase;

/**
 * Regression coverage for EmailConfig::useEmailit(). This class previously
 * referenced \Emailit\Client — a class that has never existed in the real
 * emailit/emailit-php SDK (the actual client is \Emailit\EmailitClient) —
 * so interceptForEmailit() always hit the "SDK not installed" fallback and
 * every mail silently went out via plain wp_mail(), with no error surfaced
 * anywhere. These tests exercise the real, installed SDK class (required
 * as a require-dev dependency specifically for this suite) so a future
 * class-name/rename drift fails here instead of disappearing into a
 * silent no-op again.
 */
final class EmailConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_bloginfo')->justReturn('Test Site');
        Functions\when('add_filter')->justReturn(true);

        // Matches WP core's real wp_strip_all_tags() implementation closely
        // enough for these tests: strip script/style blocks, strip tags, and
        // (when $removeBreaks is true) collapse whitespace/newlines.
        Functions\when('wp_strip_all_tags')->alias(
            function (string $text, bool $removeBreaks = false): string {
                $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
                $text = strip_tags($text);
                if ($removeBreaks) {
                    $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
                }
                return trim($text);
            }
        );
    }

    protected function tearDown(): void
    {
        // EmailConfig keeps its configuration in private statics that would
        // otherwise leak between test methods (and even between test
        // classes, since PHPUnit runs the whole suite in one process).
        $ref = new \ReflectionClass(EmailConfig::class);
        foreach (['emailitApiKey' => null, 'fromEmail' => '', 'fromName' => ''] as $prop => $default) {
            $property = $ref->getProperty($prop);
            $property->setAccessible(true);
            $property->setValue(null, $default);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function sampleMailAtts(): array
    {
        return [
            'to' => 'someone@example.com',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ];
    }

    public function test_empty_api_key_short_circuits_without_touching_the_sdk(): void
    {
        EmailConfig::useEmailit('', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, $this->sampleMailAtts());

        $this->assertNull($result);
    }

    /**
     * The core regression test: with a valid key configured and the real
     * SDK installed, interceptForEmailit() must reach the actual send
     * attempt rather than bailing out at the "not installed" class_exists()
     * check. We overload \Emailit\EmailitClient (Mockery's "new SomeClass()"
     * interception) so the real HTTP call never happens, but the class name
     * referenced by EmailConfig.php must be exactly right for the overload
     * to ever be exercised at all — if EmailConfig reverted to referencing
     * \Emailit\Client, class_exists() would fail before ever reaching
     * `new`, and the ->once() expectations below would fail verification
     * (Brain Monkey's tearDown() calls Mockery::close(), which asserts
     * every expectation was actually met) instead of the test passing.
     */
    public function test_valid_key_with_real_sdk_installed_attempts_a_send_via_the_real_client_class(): void
    {
        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->once()->andReturn((object) []);

        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->once()->andReturn($emailService);

        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, $this->sampleMailAtts());

        $this->assertTrue($result);
    }

    /**
     * Regression test: HTML messages used to go out HTML-only, with 'text'
     * explicitly set to null and stripped by array_filter() before ever
     * reaching the SDK — no plain-text alternative part at all. Captures
     * the actual params handed to EmailService::send() and asserts both
     * parts are present, non-empty, and that 'text' is genuinely a
     * tag-stripped derivative of the HTML (not just a copy of it, and not
     * merely "not the exact HTML string" — it must contain no tags and no
     * leftover newlines from the source markup).
     */
    public function test_html_message_sends_multipart_with_both_html_and_stripped_text_parts(): void
    {
        $capturedParams = null;

        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->once()->andReturnUsing(
            function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return (object) [];
            }
        );

        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->once()->andReturn($emailService);

        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $htmlMessage = "<p>Hello <strong>World</strong></p>\n\n<p>Line two.</p>";

        $result = EmailConfig::interceptForEmailit(null, [
            'to' => 'someone@example.com',
            'subject' => 'Test',
            'message' => $htmlMessage,
            'headers' => ['Content-Type: text/html; charset=UTF-8'],
        ]);

        $this->assertTrue($result);
        $this->assertNotNull($capturedParams);

        $this->assertArrayHasKey('html', $capturedParams);
        $this->assertSame($htmlMessage, $capturedParams['html']);

        $this->assertArrayHasKey('text', $capturedParams);
        $this->assertNotEmpty($capturedParams['text']);
        $this->assertStringNotContainsString('<', $capturedParams['text']);
        $this->assertStringNotContainsString("\n", $capturedParams['text']);
        $this->assertSame('Hello World Line two.', $capturedParams['text']);
    }

    /**
     * Behavior-preserving check for the non-HTML path: no Content-Type:
     * text/html header means the plain-message branch is unchanged — only
     * 'text' is sent, 'html' stays absent.
     */
    public function test_plain_text_message_still_sends_only_the_text_part(): void
    {
        $capturedParams = null;

        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->once()->andReturnUsing(
            function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return (object) [];
            }
        );

        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->once()->andReturn($emailService);

        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, $this->sampleMailAtts());

        $this->assertTrue($result);
        $this->assertNotNull($capturedParams);
        $this->assertArrayNotHasKey('html', $capturedParams);
        $this->assertSame('Body', $capturedParams['text']);
    }

    /**
     * Regression test: a real 2-recipient array used to be collapsed into a
     * single comma-joined string ("a@x.com, b@y.com") before reaching the
     * SDK. Emailit's API has no WP/PHPMailer-style "comma string means
     * multiple recipients" convention — it only understands a plain string
     * (one recipient) or a real string[] — so that joined string was handed
     * to Emailit as if it were one address, and Emailit's own address
     * parsing extracted "everything after the first @" as the domain,
     * producing exactly the malformed "gmail.com, comercializacion@..."
     * domain seen in production delivery failures. 'to' must arrive at the
     * SDK as a real array when there's more than one recipient.
     */
    public function test_multiple_recipients_as_array_are_sent_as_a_real_array_not_joined(): void
    {
        $capturedParams = null;

        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->once()->andReturnUsing(
            function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return (object) [];
            }
        );

        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->once()->andReturn($emailService);

        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, [
            'to'      => ['chcapitalmexico@gmail.com', 'comercializacion@chcapital.mx'],
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $this->assertTrue($result);
        $this->assertNotNull($capturedParams);
        $this->assertSame(
            ['chcapitalmexico@gmail.com', 'comercializacion@chcapital.mx'],
            $capturedParams['to']
        );
    }

    /**
     * Same bug, reached via the other call shape: Form::sendWithTemplate()
     * pre-joins a multi-recipient array into a single comma-separated
     * string before it ever reaches wp_mail()/this filter. That string must
     * still be split back into a real array here, not passed through as-is.
     */
    public function test_comma_separated_string_is_split_into_a_real_array(): void
    {
        $capturedParams = null;

        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->once()->andReturnUsing(
            function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return (object) [];
            }
        );

        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->once()->andReturn($emailService);

        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, [
            'to'      => 'chcapitalmexico@gmail.com, comercializacion@chcapital.mx',
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $this->assertTrue($result);
        $this->assertNotNull($capturedParams);
        $this->assertSame(
            ['chcapitalmexico@gmail.com', 'comercializacion@chcapital.mx'],
            $capturedParams['to']
        );
    }

    /**
     * A single recipient — whether passed as a one-element array or a plain
     * string — must still be sent as a plain string, not a one-element
     * array, matching the SDK's documented to: string|string[] contract and
     * the pre-existing single-recipient tests above.
     */
    public function test_single_recipient_is_still_sent_as_a_plain_string(): void
    {
        $capturedParams = null;

        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->once()->andReturnUsing(
            function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return (object) [];
            }
        );

        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->once()->andReturn($emailService);

        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, [
            'to'      => ['someone@example.com'],
            'subject' => 'Test',
            'message' => 'Body',
            'headers' => [],
        ]);

        $this->assertTrue($result);
        $this->assertNotNull($capturedParams);
        $this->assertSame('someone@example.com', $capturedParams['to']);
    }

    public function test_sdk_exception_during_send_falls_back_to_null_gracefully(): void
    {
        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->once()->andThrow(new \RuntimeException('API unreachable'));

        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->once()->andReturn($emailService);

        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, $this->sampleMailAtts());

        $this->assertNull($result);
    }

    /** Mocks the SDK and returns a reference to the params it was sent. */
    private function captureSend(?array &$captured): void
    {
        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->andReturnUsing(
            function (array $params) use (&$captured) {
                $captured = $params;
                return (object) [];
            }
        );
        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->andReturn($emailService);
    }

    public function test_reply_to_cc_and_bcc_headers_reach_emailit(): void
    {
        $captured = null;
        $this->captureSend($captured);
        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, [
            'to'      => 'studio@example.com',
            'subject' => 'Test',
            'message' => '<p>Hi</p>',
            'headers' => [
                'Content-Type: text/html; charset=UTF-8',
                'From: Someone Else <other@example.com>',
                'Reply-To: client@example.com',
                'Cc: a@example.com, b@example.com',
                'Bcc: c@example.com',
            ],
        ]);

        $this->assertTrue($result);
        $this->assertSame('client@example.com', $captured['reply_to']);
        $this->assertSame(['a@example.com', 'b@example.com'], $captured['cc']);
        $this->assertSame(['c@example.com'], $captured['bcc']);
        $this->assertSame('Test Site <hello@example.com>', $captured['from'], 'the configured sender, not the From header');
        $this->assertSame('<p>Hi</p>', $captured['html']);
    }

    public function test_headers_as_one_string_are_parsed_too(): void
    {
        $captured = null;
        $this->captureSend($captured);
        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        EmailConfig::interceptForEmailit(null, [
            'to' => 'x@example.com', 'subject' => 'T', 'message' => '<b>x</b>',
            'headers' => "Content-Type: text/html\r\nReply-To: r@example.com",
        ]);

        $this->assertSame('r@example.com', $captured['reply_to']);
        $this->assertSame('<b>x</b>', $captured['html']);
        $this->assertArrayNotHasKey('cc', $captured);
        $this->assertArrayNotHasKey('attachments', $captured);
    }

    public function test_attachments_are_sent_base64_with_their_names(): void
    {
        Functions\when('wp_check_filetype')->alias(
            fn (string $name): array => ['ext' => 'csv', 'type' => str_ends_with($name, '.csv') ? 'text/csv' : false]
        );
        $dir = sys_get_temp_dir() . '/taw-emailit-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/guests.csv', "a,b\n1,2\n");
        file_put_contents($dir . '/raw.bin', 'xyz');

        $captured = null;
        $this->captureSend($captured);
        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, [
            'to' => 'x@example.com', 'subject' => 'T', 'message' => 'Body', 'headers' => [],
            'attachments' => [$dir . '/guests.csv', 'Shown name.txt' => $dir . '/raw.bin'],
        ]);

        unlink($dir . '/guests.csv');
        unlink($dir . '/raw.bin');
        rmdir($dir);

        $this->assertTrue($result);
        $this->assertSame([
            ['filename' => 'guests.csv', 'content' => base64_encode("a,b\n1,2\n"), 'content_type' => 'text/csv'],
            ['filename' => 'Shown name.txt', 'content' => base64_encode('xyz')],
        ], $captured['attachments']);
    }

    public function test_an_unreadable_attachment_falls_back_to_wp_mail_instead_of_dropping_it(): void
    {
        $sent = false;
        $emailService = \Mockery::mock();
        $emailService->shouldReceive('send')->andReturnUsing(function () use (&$sent) {
            $sent = true;
            return (object) [];
        });
        $client = \Mockery::mock('overload:' . \Emailit\EmailitClient::class);
        $client->shouldReceive('emails')->andReturn($emailService);
        EmailConfig::useEmailit('fake-api-key', 'hello@example.com');

        $result = EmailConfig::interceptForEmailit(null, [
            'to' => 'x@example.com', 'subject' => 'T', 'message' => 'Body', 'headers' => [],
            'attachments' => ['/nonexistent/' . uniqid() . '.csv'],
        ]);

        $this->assertNull($result, 'null = let wp_mail() send it, attachment included');
        $this->assertFalse($sent);
    }
}
