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
}
