<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Form\RateLimiter;
use TAW\Core\Form\SubmissionsHandler;
use TAW\Core\Rag\Llm\LlmClient;
use TAW\Core\Rag\Orchestrator\ChatOrchestrator;
use TAW\Core\Rag\RagSettings;
use TAW\Core\Rag\Tools\ArchiveSearchTool;
use TAW\Core\Rag\Tools\BibleLookupTool;
use TAW\Core\Rag\Tools\CatechismLookupTool;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `POST /wp-json/taw/v1/chat` — the Hybrid-RAG chatbot entry point.
 *
 * Public by default ({@see RagSettings::publicChatEnabled()}) — this is
 * meant to be a site-visitor-facing feature, not an admin tool. WP's
 * cookie-auth nonce check only protects *logged-in* callers and does
 * nothing for anonymous requests, so the real defense here is rate
 * limiting via {@see RateLimiter}, applied unconditionally regardless of
 * the public/logged-in-only setting.
 */
final class RagChatEndpoint
{
    private const NAMESPACE = 'taw/v1';
    private const RATE_LIMIT_MAX = 20;
    private const RATE_LIMIT_WINDOW = 600;
    private const MAX_HISTORY_TURNS = 10;
    private const MAX_MESSAGE_CHARS = 4000;

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/chat', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'message' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'validate_callback' => [$this, 'validate_message'],
                    'description' => 'The user message.',
                ],
                'history' => [
                    'required' => false,
                    'default' => [],
                    'validate_callback' => [$this, 'validate_history'],
                    'description' => 'Prior turns of the conversation: [{role, content}, ...].',
                ],
            ],
        ]);
    }

    public function check_permission(): bool
    {
        return RagSettings::publicChatEnabled() || current_user_can('read');
    }

    public function validate_message(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= self::MAX_MESSAGE_CHARS;
    }

    public function validate_history(mixed $value): bool|\WP_Error
    {
        if (!is_array($value)) {
            return new \WP_Error('invalid_history', 'history must be an array.', ['status' => 400]);
        }

        foreach ($value as $turn) {
            if (!is_array($turn) || !isset($turn['role'], $turn['content'])) {
                return new \WP_Error('invalid_history', 'Each history turn needs a role and content.', ['status' => 400]);
            }
            if (!in_array($turn['role'], ['user', 'assistant'], true)) {
                return new \WP_Error('invalid_history', 'history role must be "user" or "assistant".', ['status' => 400]);
            }
        }

        return true;
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $ip = SubmissionsHandler::getUserIp();
        if (RateLimiter::tooManyAttempts('rag_chat', $ip, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
            return new \WP_REST_Response(['error' => 'Too many requests. Please try again shortly.'], 429);
        }

        $message = (string) $request->get_param('message');
        $rawHistory = $request->get_param('history');
        $history = $this->sanitizeHistory(is_array($rawHistory) ? $rawHistory : []);

        $llm = new LlmClient();
        $tools = [
            'lookup_bible' => new BibleLookupTool(),
            'lookup_catechism' => new CatechismLookupTool(),
            'search_unstructured_archive' => new ArchiveSearchTool($llm),
        ];

        $orchestrator = new ChatOrchestrator($llm, $tools, RagSettings::chatModel(), RagSettings::maxToolIterations());
        $result = $orchestrator->respond($message, $history);

        return new \WP_REST_Response(['message' => $result['message']], 200);
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return list<array{role: string, content: string}>
     */
    private function sanitizeHistory(array $history): array
    {
        $clean = [];
        foreach (array_slice($history, -self::MAX_HISTORY_TURNS) as $turn) {
            $clean[] = [
                'role' => (string) ($turn['role'] ?? 'user'),
                'content' => sanitize_textarea_field((string) ($turn['content'] ?? '')),
            ];
        }

        return $clean;
    }
}
