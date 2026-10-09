<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rest;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rest\RagChatEndpoint;
use TAW\Tests\TestCase;

/**
 * The "Turnstile is on but its keys are missing" state. Separate process,
 * because RagChatEndpointTest defines the key constants for good (see
 * TurnstileNotConfiguredTest for the same split).
 */
final class RagChatEndpointUnprotectedTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_chat_fails_closed_without_turnstile_keys(): void
    {
        Functions\when('get_option')->alias(static fn ($name, $default = false) => $default);
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects($this->never())->method('chatCompletion');

        $response = (new RagChatEndpoint($llm))->handle(new \WP_REST_Request('POST', '/taw/v1/chat', ['message' => 'hi']));

        $this->assertSame(503, $response->get_status());
        $this->assertSame('not_protected', $response->get_data()['code']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_no_session_is_issued_without_turnstile_keys(): void
    {
        Functions\when('get_option')->alias(static fn ($name, $default = false) => $default);

        $response = (new RagChatEndpoint())->create_session(new \WP_REST_Request('POST', '/taw/v1/chat/session', []));

        $this->assertSame(503, $response->get_status());
        $this->assertSame('not_protected', $response->get_data()['code']);
    }
}
