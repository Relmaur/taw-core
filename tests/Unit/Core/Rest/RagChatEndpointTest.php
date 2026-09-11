<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rest;

use Brain\Monkey\Functions;
use TAW\Core\Rest\RagChatEndpoint;
use TAW\Tests\TestCase;

final class RagChatEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('sanitize_textarea_field')->alias(static fn ($v) => trim((string) $v));
    }

    public function test_check_permission_is_public_by_default(): void
    {
        // Simulate WP's real get_option() semantics: an unset option falls
        // back to the caller-supplied default (RagSettings::publicChatEnabled()
        // defaults to '1' — public).
        Functions\when('get_option')->alias(static fn ($name, $default = false) => $default);

        $this->assertTrue((new RagChatEndpoint())->check_permission());
    }

    public function test_check_permission_falls_back_to_logged_in_when_public_disabled(): void
    {
        Functions\when('get_option')->justReturn('0');
        Functions\when('current_user_can')->justReturn(true);

        $this->assertTrue((new RagChatEndpoint())->check_permission());
    }

    public function test_check_permission_denies_anonymous_when_public_disabled(): void
    {
        Functions\when('get_option')->justReturn('0');
        Functions\when('current_user_can')->justReturn(false);

        $this->assertFalse((new RagChatEndpoint())->check_permission());
    }

    public function test_validate_message_rejects_empty_string(): void
    {
        $endpoint = new RagChatEndpoint();

        $this->assertFalse($endpoint->validate_message(''));
        $this->assertFalse($endpoint->validate_message('   '));
        $this->assertFalse($endpoint->validate_message(str_repeat('a', 4001)));
        $this->assertTrue($endpoint->validate_message('hello'));
    }

    public function test_validate_history_rejects_non_array(): void
    {
        $result = (new RagChatEndpoint())->validate_history('not an array');

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_validate_history_rejects_bad_role(): void
    {
        $result = (new RagChatEndpoint())->validate_history([
            ['role' => 'system', 'content' => 'hi'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_validate_history_accepts_well_formed_turns(): void
    {
        $result = (new RagChatEndpoint())->validate_history([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
        ]);

        $this->assertTrue($result);
    }

    public function test_sanitize_history_caps_to_max_turns_and_sanitizes_content(): void
    {
        $turns = [];
        for ($i = 0; $i < 15; $i++) {
            $turns[] = ['role' => 'user', 'content' => "turn {$i}"];
        }

        $result = (new RagChatEndpoint())->sanitize_history($turns);

        $this->assertCount(10, $result);
        $this->assertSame('turn 5', $result[0]['content']);
        $this->assertSame('turn 14', $result[9]['content']);
    }

    public function test_sanitize_history_tolerates_a_non_array_value(): void
    {
        $this->assertSame([], (new RagChatEndpoint())->sanitize_history('garbage'));
    }
}
