<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Llm;

use Brain\Monkey\Functions;
use TAW\Core\Rag\Llm\LlmClient;
use TAW\Core\Rag\Llm\LlmClientException;
use TAW\Tests\TestCase;

final class LlmClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
        Functions\when('get_option')->justReturn(false);

        if (!defined('TAW_RAG_API_KEY')) {
            define('TAW_RAG_API_KEY', 'sk-test');
        }
    }

    public function test_embeddings_parses_the_response(): void
    {
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3]],
                ['embedding' => [0.4, 0.5, 0.6]],
            ],
        ]));
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);

        $vectors = (new LlmClient())->embeddings(['a', 'b'], 'test-model');

        $this->assertCount(2, $vectors);
        $this->assertEqualsWithDelta([0.1, 0.2, 0.3], $vectors[0], 0.0001);
    }

    public function test_wp_error_response_throws_llm_client_exception(): void
    {
        Functions\when('is_wp_error')->justReturn(true);
        Functions\when('wp_remote_post')->justReturn(new class {
            public function get_error_message(): string
            {
                return 'connection timed out';
            }
        });

        $this->expectException(LlmClientException::class);
        (new LlmClient())->embeddings(['a'], 'test-model');
    }

    public function test_non_success_status_throws(): void
    {
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 500]]);

        $this->expectException(LlmClientException::class);
        (new LlmClient())->embeddings(['a'], 'test-model');
    }

    public function test_chat_completion_returns_the_assistant_message(): void
    {
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'Hello!']],
            ],
        ]));
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200]]);

        $message = (new LlmClient())->chatCompletion([['role' => 'user', 'content' => 'hi']], [], 'test-model');

        $this->assertSame('Hello!', $message['content']);
    }
}
