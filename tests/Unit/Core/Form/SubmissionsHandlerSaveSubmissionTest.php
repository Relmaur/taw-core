<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Form;

use Brain\Monkey\Functions;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Tests\TestCase;

/**
 * Covers saveSubmission()'s meta writes, in particular the
 * '_taw_user_agent' key added alongside the existing '_taw_user_ip' /
 * '_taw_page_url' — generically useful technical context for any form
 * submission, captured from $_SERVER['HTTP_USER_AGENT'] the same
 * defensive way getUserIp() reads $_SERVER['REMOTE_ADDR'] et al.
 */
final class SubmissionsHandlerSaveSubmissionTest extends TestCase
{
    /** @var array<int, array{0: int, 1: string, 2: mixed}> */
    private array $recordedMeta = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->recordedMeta = [];

        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_insert_post')->justReturn(555);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('get_option')->justReturn('');
        Functions\when('sanitize_key')->alias(
            fn(string $key) => preg_replace('/[^a-z0-9_\-]/', '', strtolower($key))
        );

        Functions\when('update_post_meta')->alias(
            function (int $postId, string $key, mixed $value) {
                $this->recordedMeta[] = [$postId, $key, $value];
                return true;
            }
        );
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        parent::tearDown();
    }

    private function metaValue(string $key): mixed
    {
        foreach ($this->recordedMeta as [$postId, $metaKey, $value]) {
            if ($metaKey === $key) {
                return $value;
            }
        }

        return '__not_written__';
    }

    public function test_saves_the_user_agent_from_the_server_superglobal(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (TestAgent) TAW/1.0';

        SubmissionsHandler::saveSubmission('contact', [], ['name' => 'Jane']);

        $this->assertSame('Mozilla/5.0 (TestAgent) TAW/1.0', $this->metaValue('_taw_user_agent'));
    }

    public function test_saves_an_empty_string_when_no_user_agent_header_was_sent(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);

        SubmissionsHandler::saveSubmission('contact', [], ['name' => 'Jane']);

        $this->assertSame('', $this->metaValue('_taw_user_agent'));
    }

    public function test_user_agent_meta_is_sanitized_the_same_way_as_user_ip(): void
    {
        Functions\when('sanitize_text_field')->alias(fn(string $s) => strtoupper($s));
        $_SERVER['HTTP_USER_AGENT'] = 'lowercase-agent';

        SubmissionsHandler::saveSubmission('contact', [], ['name' => 'Jane']);

        $this->assertSame('LOWERCASE-AGENT', $this->metaValue('_taw_user_agent'));
    }
}
