<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rest;

use Brain\Monkey\Functions;
use TAW\Core\Log\LogSinkInterface;
use TAW\Core\Log\Logger;
use TAW\Core\Rag\Guard\ChatLimits;
use TAW\Core\Rag\Guard\ChatSession;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Usage\UsageMeter;
use TAW\Core\Rest\RagChatEndpoint;
use TAW\Tests\Support\FakeWpdb;
use TAW\Tests\TestCase;

/**
 * The Turnstile keys are process-global constants, so this class only
 * covers the "configured" state; RagChatEndpointUnprotectedTest covers
 * the rest in a separate process.
 */
final class RagChatEndpointTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!defined('TAW_TURNSTILE_SITE_KEY')) {
            define('TAW_TURNSTILE_SITE_KEY', 'test-site-key');
        }
        if (!defined('TAW_TURNSTILE_SECRET_KEY')) {
            define('TAW_TURNSTILE_SECRET_KEY', 'test-secret-key');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('sanitize_textarea_field')->alias(static fn ($v) => trim((string) $v));
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(fn (string $key): mixed => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function (string $key, mixed $value): bool {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function (string $key): bool {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('wp_salt')->justReturn('test-salt');
        Functions\when('current_time')->alias(static fn (string $format): string => date($format, (int) strtotime('2026-10-09')));
        Functions\when('wp_mail')->justReturn(true);

        $GLOBALS['wpdb'] = new FakeWpdb();
        UsageMeter::resetForTests();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        Logger::setSinks(new class implements LogSinkInterface {
            public function write(array $entry): void
            {
            }
        });
    }

    protected function tearDown(): void
    {
        Logger::reset();
        unset($GLOBALS['wpdb'], $_SERVER['REMOTE_ADDR']);
        parent::tearDown();
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
        $this->options['_taw_rag_public_chat_enabled'] = '0';
        Functions\when('current_user_can')->justReturn(true);

        $this->assertTrue((new RagChatEndpoint())->check_permission());
    }

    public function test_check_permission_denies_anonymous_when_public_disabled(): void
    {
        $this->options['_taw_rag_public_chat_enabled'] = '0';
        Functions\when('current_user_can')->justReturn(false);

        $this->assertFalse((new RagChatEndpoint())->check_permission());
    }

    public function test_validate_message_rejects_empty_and_over_long_messages(): void
    {
        $endpoint = new RagChatEndpoint();

        $this->assertFalse($endpoint->validate_message(''));
        $this->assertFalse($endpoint->validate_message('   '));
        $this->assertFalse($endpoint->validate_message(str_repeat('a', 1001)));
        $this->assertTrue($endpoint->validate_message(str_repeat('a', 1000)));
        $this->assertTrue($endpoint->validate_message('hello'));
    }

    public function test_the_message_cap_follows_the_setting(): void
    {
        $this->options['_taw_rag_max_message_chars'] = '200';

        $this->assertFalse((new RagChatEndpoint())->validate_message(str_repeat('a', 201)));
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

    public function test_sanitize_history_keeps_the_last_turns_and_cuts_each_one(): void
    {
        $turns = [];
        for ($i = 0; $i < 15; $i++) {
            $turns[] = ['role' => 'user', 'content' => "turn {$i}"];
        }
        $turns[] = ['role' => 'assistant', 'content' => str_repeat('x', 5000)];

        $result = (new RagChatEndpoint())->sanitize_history($turns);

        $this->assertCount(ChatLimits::HISTORY_TURNS, $result);
        $this->assertSame('turn 10', $result[0]['content']);
        $this->assertSame(ChatLimits::HISTORY_TURN_CHARS, mb_strlen($result[5]['content']));
    }

    public function test_sanitize_history_tolerates_a_non_array_value(): void
    {
        $this->assertSame([], (new RagChatEndpoint())->sanitize_history('garbage'));
    }

    /* ---- handle(): the gates, in order ------------------------------ */

    public function test_the_kill_switch_refuses_before_anything_else(): void
    {
        $this->options['_taw_rag_chat_paused'] = '1';
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('chatCompletion');

        $response = (new RagChatEndpoint($llm))->handle($this->chatRequest(session: null));

        $this->assertRefused($response, 503, 'paused');
    }

    public function test_a_missing_or_forged_session_is_refused(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('chatCompletion');
        $endpoint = new RagChatEndpoint($llm);

        $this->assertRefused($endpoint->handle($this->chatRequest(session: null)), 401, 'session_required');
        $this->assertRefused($endpoint->handle($this->chatRequest(session: 'forged.token')), 401, 'session_required');
    }

    public function test_the_human_check_can_be_switched_off(): void
    {
        $this->options['_taw_rag_human_check'] = 'off';

        $response = (new RagChatEndpoint($this->answeringLlm()))->handle($this->chatRequest(session: null));

        $this->assertSame(200, $response->get_status());
    }

    public function test_an_unknown_human_check_value_keeps_protection_on(): void
    {
        $this->options['_taw_rag_human_check'] = 'of';

        $response = (new RagChatEndpoint($this->answeringLlm()))->handle($this->chatRequest(session: null));

        $this->assertRefused($response, 401, 'session_required');
    }

    public function test_a_valid_session_gets_an_answer_with_the_output_cap(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->once())
            ->method('chatCompletion')
            ->with($this->anything(), $this->anything(), 'gpt-4o-mini', ['max_completion_tokens' => 600])
            ->willReturn(['role' => 'assistant', 'content' => 'Hola']);

        $response = (new RagChatEndpoint($llm))->handle($this->chatRequest());

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['message' => 'Hola'], $response->get_data());
    }

    public function test_other_providers_get_max_tokens(): void
    {
        $this->options['_taw_rag_base_url'] = 'http://localhost:11434/v1';
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->once())
            ->method('chatCompletion')
            ->with($this->anything(), $this->anything(), $this->anything(), ['max_tokens' => 600])
            ->willReturn(['role' => 'assistant', 'content' => 'ok']);

        (new RagChatEndpoint($llm))->handle($this->chatRequest());
    }

    public function test_the_per_visitor_burst_limit_returns_429_with_retry_after(): void
    {
        $this->options['_taw_rag_human_check'] = 'off';
        $this->options['_taw_rag_rate_burst'] = '2';
        $endpoint = new RagChatEndpoint($this->answeringLlm());

        $endpoint->handle($this->chatRequest(session: null));
        $endpoint->handle($this->chatRequest(session: null));
        $response = $endpoint->handle($this->chatRequest(session: null));

        $this->assertRefused($response, 429, 'rate_limited');
        $this->assertSame('600', $response->headers['Retry-After']);
    }

    public function test_a_spoofed_forwarded_for_header_does_not_reset_the_limit(): void
    {
        $this->options['_taw_rag_human_check'] = 'off';
        $this->options['_taw_rag_rate_burst'] = '1';
        $endpoint = new RagChatEndpoint($this->answeringLlm());

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
        $endpoint->handle($this->chatRequest(session: null));
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2.2.2.2';
        $response = $endpoint->handle($this->chatRequest(session: null));
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->assertRefused($response, 429, 'rate_limited');
    }

    public function test_the_site_wide_limit_catches_many_addresses(): void
    {
        $this->options['_taw_rag_human_check'] = 'off';
        $this->options['_taw_rag_rate_global_per_minute'] = '2';
        $endpoint = new RagChatEndpoint($this->answeringLlm());

        foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
            $_SERVER['REMOTE_ADDR'] = $ip;
            $this->assertSame(200, $endpoint->handle($this->chatRequest(session: null))->get_status());
        }
        $_SERVER['REMOTE_ADDR'] = '198.51.100.3';

        $this->assertRefused($endpoint->handle($this->chatRequest(session: null)), 429, 'rate_limited');
    }

    public function test_an_exhausted_budget_refuses_without_calling_the_model(): void
    {
        $this->options['_taw_rag_budget_daily_usd'] = '0';
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('chatCompletion');

        $response = (new RagChatEndpoint($llm))->handle($this->chatRequest());

        $this->assertRefused($response, 503, 'budget_exhausted');
    }

    public function test_the_budget_reserves_the_worst_case_not_the_average(): void
    {
        // A daily budget smaller than one worst-case request: nothing has
        // been spent, yet the request must still be refused.
        $this->options['_taw_rag_budget_daily_usd'] = '0.001';
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('chatCompletion');

        $response = (new RagChatEndpoint($llm))->handle($this->chatRequest());

        $this->assertRefused($response, 503, 'budget_exhausted');
    }

    /* ---- create_session() ------------------------------------------- */

    public function test_a_verified_turnstile_token_buys_a_session(): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":true}');

        $response = (new RagChatEndpoint())->create_session($this->sessionRequest('cf-token'));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(ChatSession::OK, ChatSession::consume($response->get_data()['token']));
    }

    public function test_a_failed_turnstile_check_gets_no_session(): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":false}');

        $response = (new RagChatEndpoint())->create_session($this->sessionRequest('bad'));

        $this->assertRefused($response, 403, 'human_check_failed');
    }

    public function test_session_creation_is_rate_limited_per_visitor(): void
    {
        Functions\when('wp_remote_post')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"success":false}');
        $endpoint = new RagChatEndpoint();

        for ($i = 0; $i < 10; $i++) {
            $endpoint->create_session($this->sessionRequest('x'));
        }

        $this->assertRefused($endpoint->create_session($this->sessionRequest('x')), 429, 'rate_limited');
    }

    /* ---- helpers ----------------------------------------------------- */

    private function chatRequest(?string $session = 'issue'): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/taw/v1/chat', ['message' => 'Hola', 'history' => []]);
        if ($session === 'issue') {
            $session = ChatSession::issue()['token'];
        }
        if ($session !== null) {
            $request->set_header(ChatSession::HEADER, $session);
        }

        return $request;
    }

    private function sessionRequest(string $token): \WP_REST_Request
    {
        return new \WP_REST_Request('POST', '/taw/v1/chat/session', ['turnstile_token' => $token]);
    }

    private function answeringLlm(): LlmClientInterface
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chatCompletion')->willReturn(['role' => 'assistant', 'content' => 'ok']);

        return $llm;
    }

    private function assertRefused(\WP_REST_Response $response, int $status, string $code): void
    {
        $this->assertSame($status, $response->get_status());
        $this->assertSame($code, $response->get_data()['code']);
        $this->assertNotEmpty($response->get_data()['error']);
    }
}
