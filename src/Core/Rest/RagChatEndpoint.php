<?php

declare(strict_types=1);

namespace TAW\Core\Rest;

use TAW\Core\Form\RateLimiter;
use TAW\Core\Form\Turnstile;
use TAW\Core\Log\Logger;
use TAW\Core\Rag\Guard\ChatLimits;
use TAW\Core\Rag\Guard\ChatSession;
use TAW\Core\Rag\Llm\LlmClient;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Orchestrator\ChatOrchestrator;
use TAW\Core\Rag\RagSettings;
use TAW\Core\Rag\Tools\CanonLawLookupTool;
use TAW\Core\Rag\Tools\RagTool;
use TAW\Core\Rag\Tools\SearchKnowledgeBaseTool;
use TAW\Core\Rag\Usage\UsageMeter;
use TAW\Core\Security\ClientIp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `POST /wp-json/taw/v1/chat` — the Hybrid-RAG chatbot entry point — and
 * `POST /wp-json/taw/v1/chat/session`, which trades a Turnstile token for a
 * signed chat session.
 *
 * Public by default ({@see RagSettings::publicChatEnabled()}), and every
 * message can spend money on a paid LLM API, so each request passes a
 * series of gates before anything billable runs, cheapest first:
 *
 *   1. kill switch           → 503 `paused`
 *   2. human check (session) → 503 `not_protected` / 401 `session_required`
 *   3. throttles             → 429 `rate_limited` (per visitor, then site-wide)
 *   4. budget reserve        → 503 `budget_exhausted`
 *
 * Only then does the orchestrator run, under the per-request caps in
 * {@see ChatLimits}. Refusals carry a stable `code` for the widget to map
 * to its own (localized) copy; `error` is an English fallback.
 */
final class RagChatEndpoint
{
    private const NAMESPACE = 'taw/v1';

    private const BURST_WINDOW = 600;
    private const DAILY_WINDOW = 86400;
    private const SITE_WINDOW = 60;

    /** Turnstile verifications per visitor per 10 minutes. Each one calls Cloudflare. */
    private const SESSION_RATE_MAX = 10;
    private const SESSION_RATE_WINDOW = 600;

    private const REFUSALS = [
        'paused' => 'The assistant is paused right now.',
        'not_protected' => 'The assistant is not available right now.',
        'session_required' => 'Please confirm you are human to continue.',
        'human_check_failed' => 'The human check failed. Please try again.',
        'rate_limited' => 'Too many requests. Please try again shortly.',
        'budget_exhausted' => 'The assistant is paused for now. Please try again later.',
    ];

    /**
     * @param LlmClientInterface|null $llm Injected by tests; production uses the real client.
     */
    public function __construct(private readonly ?LlmClientInterface $llm = null)
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
                    'sanitize_callback' => [$this, 'sanitize_history'],
                    'validate_callback' => [$this, 'validate_history'],
                    'description' => 'Prior turns of the conversation: [{role, content}, ...].',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/chat/session', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_session'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'turnstile_token' => [
                    'required' => false,
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'The Cloudflare Turnstile response token.',
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
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= RagSettings::maxMessageChars();
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

    /**
     * History is client-supplied, so it's untrusted and unbounded: a client
     * could send long fabricated "assistant" turns to inflate every model
     * call. Only the last few turns are kept, each one cut short.
     *
     * @return list<array{role: string, content: string}>
     */
    public function sanitize_history(mixed $value): array
    {
        $history = is_array($value) ? $value : [];

        $clean = [];
        foreach (array_slice($history, -ChatLimits::HISTORY_TURNS) as $turn) {
            $content = sanitize_textarea_field((string) (is_array($turn) ? ($turn['content'] ?? '') : ''));
            $clean[] = [
                'role' => (string) (is_array($turn) ? ($turn['role'] ?? 'user') : 'user'),
                'content' => mb_substr($content, 0, ChatLimits::HISTORY_TURN_CHARS),
            ];
        }

        return $clean;
    }

    /**
     * Trade a Turnstile token for a signed chat session ({@see ChatSession}),
     * so the human check runs once per conversation, not once per message.
     */
    public function create_session(\WP_REST_Request $request): \WP_REST_Response
    {
        if (RagSettings::chatPaused()) {
            return self::refusal('paused', 503);
        }

        if (RagSettings::humanCheck() === 'off') {
            return new \WP_REST_Response(ChatSession::issue(), 200);
        }

        if (!Turnstile::isConfigured()) {
            return self::refusal('not_protected', 503);
        }

        $ip = ClientIp::get();
        if (RateLimiter::tooManyAttempts('rag_chat_session', $ip, self::SESSION_RATE_MAX, self::SESSION_RATE_WINDOW)) {
            return self::refusal('rate_limited', 429, self::SESSION_RATE_WINDOW);
        }

        if (!Turnstile::verify((string) $request->get_param('turnstile_token'), $ip)) {
            return self::refusal('human_check_failed', 403);
        }

        return new \WP_REST_Response(ChatSession::issue(), 200);
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        if (RagSettings::chatPaused()) {
            return self::refusal('paused', 503);
        }

        // Fail closed: with a paid API key behind a public form, "Turnstile
        // is on but its keys are missing" must never mean "unprotected".
        if (RagSettings::humanCheck() === 'turnstile') {
            if (!Turnstile::isConfigured()) {
                return self::refusal('not_protected', 503);
            }
            if (ChatSession::consume((string) $request->get_header(ChatSession::HEADER)) !== ChatSession::OK) {
                return self::refusal('session_required', 401);
            }
        }

        $throttled = self::throttle(ClientIp::get());
        if ($throttled !== null) {
            return $throttled;
        }

        $llm = $this->llm ?? new LlmClient();
        $tools = self::toolsFor($llm);
        $systemPrompt = ChatOrchestrator::systemPrompt(RagSettings::assistantScope());

        // Reserve the most this request could possibly cost, so the budget
        // can't be overshot by the request that crosses it.
        if (UsageMeter::blockReason(self::reserveFor($tools, $systemPrompt)) !== null) {
            return self::refusal('budget_exhausted', 503);
        }

        $history = $this->sanitize_history($request->get_param('history'));
        $orchestrator = new ChatOrchestrator(
            $llm,
            $tools,
            RagSettings::chatModel(),
            RagSettings::maxToolIterations(),
            [self::outputTokenParameter() => RagSettings::maxOutputTokens()],
            $systemPrompt
        );
        $result = $orchestrator->respond((string) $request->get_param('message'), $history);

        // Identifiers only — never the visitor's message, answer or address.
        Logger::info('rag.chat', 'Chat turn answered.', [
            'tools' => array_column($result['tool_trace'], 'tool'),
            'history_turns' => count($history),
        ]);

        return new \WP_REST_Response(['message' => $result['message']], 200);
    }

    /**
     * The worst case one chat request can cost with today's settings and
     * tools, in micro-dollars — what {@see self::handle()} reserves against
     * the budget. Public so the Usage screen and `rag:usage` show the very
     * same number. Builds the tool list without any network call.
     */
    public static function currentReserveMicros(): int
    {
        return self::reserveFor(
            self::toolsFor(new LlmClient()),
            ChatOrchestrator::systemPrompt(RagSettings::assistantScope())
        );
    }

    /**
     * @param array<string, RagTool> $tools
     */
    private static function reserveFor(array $tools, string $systemPrompt): int
    {
        $toolDefinitions = array_map(static fn (RagTool $tool): array => $tool->definition(), array_values($tools));

        return ChatLimits::worstCaseCostMicros(
            mb_strlen($systemPrompt),
            mb_strlen((string) wp_json_encode($toolDefinitions))
        );
    }

    /**
     * @return array<string, RagTool>
     */
    private static function toolsFor(LlmClientInterface $llm): array
    {
        $searchTool = new SearchKnowledgeBaseTool($llm);
        $tools = [$searchTool->name() => $searchTool];

        // Structured canon lookup — only when the site serves a Code and one
        // is installed (see CanonLawLookupTool's docblock for why it isn't
        // just another knowledge base).
        if (CanonLawEndpoint::isEnabled() && CanonLawLookupTool::installedEdition() !== null) {
            $canonTool = new CanonLawLookupTool();
            $tools[$canonTool->name()] = $canonTool;
        }

        return $tools;
    }

    /**
     * Per-visitor limits run first: a visitor already over their own limit
     * is refused without touching the site-wide counter, so one noisy
     * client can't burn the whole site's capacity and lock everyone out.
     * The site-wide limit then catches traffic spread over many addresses.
     */
    private static function throttle(string $ip): ?\WP_REST_Response
    {
        if (RateLimiter::tooManyAttempts('rag_chat', $ip, RagSettings::rateBurst(), self::BURST_WINDOW)) {
            return self::refusal('rate_limited', 429, self::BURST_WINDOW);
        }

        if (RateLimiter::tooManyAttempts('rag_chat_daily', $ip, RagSettings::rateDaily(), self::DAILY_WINDOW)) {
            return self::refusal('rate_limited', 429, self::DAILY_WINDOW);
        }

        if (RateLimiter::tooManyAttempts('rag_chat_site', 'site', RagSettings::rateGlobalPerMinute(), self::SITE_WINDOW)) {
            return self::refusal('rate_limited', 429, self::SITE_WINDOW);
        }

        return null;
    }

    /**
     * OpenAI's current models reject `max_tokens` in favour of
     * `max_completion_tokens`; most other OpenAI-compatible servers (Ollama,
     * vLLM) only know `max_tokens`.
     */
    private static function outputTokenParameter(): string
    {
        $host = (string) parse_url(RagSettings::baseUrl(), PHP_URL_HOST);

        return $host === 'api.openai.com' ? 'max_completion_tokens' : 'max_tokens';
    }

    private static function refusal(string $code, int $status, ?int $retryAfter = null): \WP_REST_Response
    {
        $body = ['error' => self::REFUSALS[$code], 'code' => $code];
        if ($retryAfter !== null) {
            $body['retry_after'] = $retryAfter;
        }

        $response = new \WP_REST_Response($body, $status);
        if ($retryAfter !== null) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
