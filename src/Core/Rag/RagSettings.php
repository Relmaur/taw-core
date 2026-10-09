<?php

declare(strict_types=1);

namespace TAW\Core\Rag;

use TAW\Core\OptionsPage\OptionsPage;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings → TAW Chatbot — every non-secret knob for the Hybrid-RAG
 * chatbot, plus the read API for them.
 *
 * The LLM API key is deliberately NOT one of these fields — see
 * {@see self::apiKey()}, which follows the same wp-config-constant-only
 * pattern as {@see \TAW\Core\Form\Turnstile}: the options table is the
 * wrong place for a secret (an OptionsPage with `rest` publishes its fields
 * over the REST API).
 *
 * Opt-in, same posture as {@see \TAW\Core\Icons\Lucide} and
 * {@see \TAW\Core\Media\MediaFolders} — the entire chatbot subsystem
 * (settings page, knowledge-base uploads, WP-content ingestion, and the
 * public taw/v1/chat REST route) stays off unless a theme explicitly calls
 * {@see self::enable()} in customizations.php before Theme::boot().
 *
 * Usage:
 *   // In the theme's inc/customizations.php, before Theme::boot():
 *   TAW\Core\Rag\RagSettings::enable();
 */
final class RagSettings
{
    private const PREFIX = '_taw_';

    public const DEFAULT_BUDGET_MONTHLY = 10;
    public const DEFAULT_BUDGET_DAILY = 1;
    public const DEFAULT_PRICE_CHAT_INPUT = 0.15;
    public const DEFAULT_PRICE_CHAT_OUTPUT = 0.60;
    public const DEFAULT_PRICE_EMBEDDING = 0.02;
    public const DEFAULT_MAX_OUTPUT_TOKENS = 600;
    public const DEFAULT_MAX_MESSAGE_CHARS = 1000;
    public const DEFAULT_MAX_TOOL_ITERATIONS = 3;
    public const DEFAULT_RATE_BURST = 10;
    public const DEFAULT_RATE_DAILY = 60;
    public const DEFAULT_RATE_GLOBAL_PER_MINUTE = 30;

    /**
     * Whether the RAG chatbot has been explicitly enabled for this theme.
     * Must call RagSettings::enable() in customizations.php to activate.
     */
    private static bool $enabled = false;

    /**
     * Opt-in to the Sovereign Hybrid-RAG Chatbot.
     * Call this in the theme's customizations.php before Theme::boot().
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Whether the RAG chatbot has been enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public function __construct()
    {
        new OptionsPage([
            'id'         => 'taw_rag',
            'title'      => 'TAW Chatbot',
            'menu_title' => 'TAW Chatbot',
            'capability' => 'manage_options',
            'prefix'     => self::PREFIX,
            'tabs'       => [
                [
                    'label'  => 'Model & Index',
                    'fields' => [
                        'rag_base_url', 'rag_embedding_model', 'rag_chat_model', 'rag_indexed_post_types',
                        'rag_chunk_size', 'rag_chunk_overlap',
                    ],
                ],
                [
                    'label'  => 'Budget',
                    'fields' => [
                        'rag_budget_monthly_usd', 'rag_budget_daily_usd',
                        'rag_price_chat_input', 'rag_price_chat_output', 'rag_price_embedding',
                    ],
                ],
                [
                    'label'  => 'Limits',
                    'fields' => [
                        'rag_max_output_tokens', 'rag_max_message_chars', 'rag_max_tool_iterations',
                        'rag_rate_burst', 'rag_rate_daily', 'rag_rate_global_per_minute',
                    ],
                ],
                [
                    'label'  => 'Access',
                    'fields' => [
                        'rag_human_check', 'rag_public_chat_enabled', 'rag_chat_paused', 'rag_assistant_scope',
                    ],
                ],
            ],
            'fields'     => [
                [
                    'id'          => 'rag_base_url',
                    'label'       => 'API Base URL',
                    'type'        => 'url',
                    'default'     => 'https://api.openai.com/v1',
                    'description' => 'Any OpenAI-compatible endpoint — the default OpenAI cloud API, or a self-hosted Ollama/vLLM/etc. base URL.',
                ],
                [
                    'id'          => 'rag_embedding_model',
                    'label'       => 'Embedding Model',
                    'type'        => 'text',
                    'default'     => 'text-embedding-3-small',
                ],
                [
                    'id'          => 'rag_chat_model',
                    'label'       => 'Chat Model',
                    'type'        => 'text',
                    'default'     => 'gpt-4o-mini',
                ],
                [
                    'id'          => 'rag_indexed_post_types',
                    'label'       => 'Indexed Post Types',
                    'type'        => 'text',
                    'default'     => 'post,page',
                    'description' => 'Comma-separated post type slugs to embed for the unstructured-archive search tool.',
                ],
                [
                    'id'          => 'rag_chunk_size',
                    'label'       => 'Chunk Size (chars)',
                    'type'        => 'number',
                    'default'     => 3500,
                    'min'         => 500,
                ],
                [
                    'id'          => 'rag_chunk_overlap',
                    'label'       => 'Chunk Overlap (chars)',
                    'type'        => 'number',
                    'default'     => 300,
                    'min'         => 0,
                ],
                [
                    'id'          => 'rag_budget_monthly_usd',
                    'label'       => 'Monthly Budget (USD)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_BUDGET_MONTHLY,
                    'min'         => 0,
                    'step'        => '0.01',
                    'description' => 'Hard ceiling. The chat pauses for the rest of the month once the next request could exceed it. 0 pauses the chat.',
                ],
                [
                    'id'          => 'rag_budget_daily_usd',
                    'label'       => 'Daily Budget (USD)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_BUDGET_DAILY,
                    'min'         => 0,
                    'step'        => '0.01',
                    'description' => 'Hard ceiling per day (site time zone), so one bad day can\'t spend the month. 0 pauses the chat.',
                ],
                [
                    'id'          => 'rag_price_chat_input',
                    'label'       => 'Chat Input Price (USD / 1M tokens)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_PRICE_CHAT_INPUT,
                    'min'         => 0,
                    'step'        => '0.0001',
                    'description' => 'From your provider\'s pricing page, for the chat model above. Used to meter spend against the budgets.',
                ],
                [
                    'id'          => 'rag_price_chat_output',
                    'label'       => 'Chat Output Price (USD / 1M tokens)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_PRICE_CHAT_OUTPUT,
                    'min'         => 0,
                    'step'        => '0.0001',
                ],
                [
                    'id'          => 'rag_price_embedding',
                    'label'       => 'Embedding Price (USD / 1M tokens)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_PRICE_EMBEDDING,
                    'min'         => 0,
                    'step'        => '0.0001',
                ],
                [
                    'id'          => 'rag_max_output_tokens',
                    'label'       => 'Max Answer Length (tokens)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_MAX_OUTPUT_TOKENS,
                    'min'         => 50,
                    'max'         => 4000,
                    'description' => 'Caps every model reply. Roughly 600 tokens ≈ 450 words.',
                ],
                [
                    'id'          => 'rag_max_message_chars',
                    'label'       => 'Max Visitor Message Length (chars)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_MAX_MESSAGE_CHARS,
                    'min'         => 100,
                    'max'         => 4000,
                ],
                [
                    'id'          => 'rag_max_tool_iterations',
                    'label'       => 'Max Tool-Call Iterations',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_MAX_TOOL_ITERATIONS,
                    'min'         => 1,
                    'max'         => 10,
                    'description' => 'Each iteration is one paid model call; one more is spent on the final answer if the cap is hit.',
                ],
                [
                    'id'          => 'rag_rate_burst',
                    'label'       => 'Messages per Visitor per 10 Minutes',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_RATE_BURST,
                    'min'         => 1,
                ],
                [
                    'id'          => 'rag_rate_daily',
                    'label'       => 'Messages per Visitor per Day',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_RATE_DAILY,
                    'min'         => 1,
                ],
                [
                    'id'          => 'rag_rate_global_per_minute',
                    'label'       => 'Messages per Minute (whole site)',
                    'type'        => 'number',
                    'default'     => self::DEFAULT_RATE_GLOBAL_PER_MINUTE,
                    'min'         => 1,
                    'description' => 'Catches traffic spread across many addresses, which per-visitor limits can\'t.',
                ],
                [
                    'id'          => 'rag_human_check',
                    'label'       => 'Human Check',
                    'type'        => 'select',
                    'default'     => 'turnstile',
                    'options'     => [
                        'turnstile' => 'Cloudflare Turnstile, once per conversation',
                        'off'       => 'Off (not recommended with a paid API key)',
                    ],
                    'description' => 'Turnstile needs TAW_TURNSTILE_SITE_KEY and TAW_TURNSTILE_SECRET_KEY in wp-config.php. Without them the chat refuses to run until this is switched off.',
                ],
                [
                    'id'          => 'rag_public_chat_enabled',
                    'label'       => 'Allow Anonymous Visitors to Chat',
                    'type'        => 'checkbox',
                    'default'     => '1',
                    'description' => 'When off, only logged-in users can use the chat endpoint. Rate limiting applies either way.',
                ],
                [
                    'id'          => 'rag_chat_paused',
                    'label'       => 'Pause the Chat',
                    'type'        => 'checkbox',
                    'default'     => '0',
                    'description' => 'Kill switch. Defining TAW_RAG_CHAT_DISABLED in wp-config.php does the same without the database.',
                ],
                [
                    'id'          => 'rag_assistant_scope',
                    'label'       => 'Assistant Scope',
                    'type'        => 'textarea',
                    'default'     => '',
                    'description' => 'What this assistant is for, in a sentence or two (e.g. "the parish, its schedule, and the Catholic faith"). It declines anything else.',
                ],
            ],
        ]);
    }

    /**
     * API key resolution — wp-config constant only, never an options-table
     * field. Define in wp-config.php:
     *
     *   define('TAW_RAG_API_KEY', 'sk-...');
     */
    public static function apiKey(): string
    {
        return defined('TAW_RAG_API_KEY') ? (string) constant('TAW_RAG_API_KEY') : '';
    }

    public static function baseUrl(): string
    {
        return (string) OptionsPage::get('rag_base_url', self::PREFIX, 'https://api.openai.com/v1');
    }

    public static function embeddingModel(): string
    {
        return (string) OptionsPage::get('rag_embedding_model', self::PREFIX, 'text-embedding-3-small');
    }

    public static function chatModel(): string
    {
        return (string) OptionsPage::get('rag_chat_model', self::PREFIX, 'gpt-4o-mini');
    }

    /**
     * @return list<string>
     */
    public static function indexedPostTypes(): array
    {
        $raw = (string) OptionsPage::get('rag_indexed_post_types', self::PREFIX, 'post,page');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function chunkSize(): int
    {
        return max(500, (int) OptionsPage::get('rag_chunk_size', self::PREFIX, 3500));
    }

    public static function chunkOverlap(): int
    {
        return max(0, (int) OptionsPage::get('rag_chunk_overlap', self::PREFIX, 300));
    }

    public static function maxToolIterations(): int
    {
        $value = (int) self::number('rag_max_tool_iterations', self::DEFAULT_MAX_TOOL_ITERATIONS);

        return $value >= 1 && $value <= 10 ? $value : self::DEFAULT_MAX_TOOL_ITERATIONS;
    }

    public static function publicChatEnabled(): bool
    {
        return ((string) OptionsPage::get('rag_public_chat_enabled', self::PREFIX, '1')) === '1';
    }

    /* ---- Budget ---------------------------------------------------- */

    public static function monthlyBudgetUsd(): float
    {
        return max(0.0, self::number('rag_budget_monthly_usd', self::DEFAULT_BUDGET_MONTHLY));
    }

    public static function dailyBudgetUsd(): float
    {
        return max(0.0, self::number('rag_budget_daily_usd', self::DEFAULT_BUDGET_DAILY));
    }

    /** USD per 1M tokens. */
    public static function chatInputPrice(): float
    {
        return max(0.0, self::number('rag_price_chat_input', self::DEFAULT_PRICE_CHAT_INPUT));
    }

    /** USD per 1M tokens. */
    public static function chatOutputPrice(): float
    {
        return max(0.0, self::number('rag_price_chat_output', self::DEFAULT_PRICE_CHAT_OUTPUT));
    }

    /** USD per 1M tokens. */
    public static function embeddingPrice(): float
    {
        return max(0.0, self::number('rag_price_embedding', self::DEFAULT_PRICE_EMBEDDING));
    }

    /* ---- Limits ---------------------------------------------------- */

    public static function maxOutputTokens(): int
    {
        return self::clampInt('rag_max_output_tokens', self::DEFAULT_MAX_OUTPUT_TOKENS, 50, 4000);
    }

    public static function maxMessageChars(): int
    {
        return self::clampInt('rag_max_message_chars', self::DEFAULT_MAX_MESSAGE_CHARS, 100, 4000);
    }

    public static function rateBurst(): int
    {
        return self::clampInt('rag_rate_burst', self::DEFAULT_RATE_BURST, 1, PHP_INT_MAX);
    }

    public static function rateDaily(): int
    {
        return self::clampInt('rag_rate_daily', self::DEFAULT_RATE_DAILY, 1, PHP_INT_MAX);
    }

    public static function rateGlobalPerMinute(): int
    {
        return self::clampInt('rag_rate_global_per_minute', self::DEFAULT_RATE_GLOBAL_PER_MINUTE, 1, PHP_INT_MAX);
    }

    /* ---- Access ---------------------------------------------------- */

    /**
     * 'turnstile' (default) or 'off'. Anything unrecognised reads as
     * 'turnstile' — a typo must never switch protection off.
     */
    public static function humanCheck(): string
    {
        return ((string) OptionsPage::get('rag_human_check', self::PREFIX, 'turnstile')) === 'off' ? 'off' : 'turnstile';
    }

    /**
     * Kill switch: the TAW_RAG_CHAT_DISABLED constant (no database needed,
     * so it works even when the options table is the problem) or the
     * "Pause the Chat" setting.
     */
    public static function chatPaused(): bool
    {
        if (defined('TAW_RAG_CHAT_DISABLED') && constant('TAW_RAG_CHAT_DISABLED')) {
            return true;
        }

        return ((string) OptionsPage::get('rag_chat_paused', self::PREFIX, '0')) === '1';
    }

    public static function assistantScope(): string
    {
        return trim((string) OptionsPage::get('rag_assistant_scope', self::PREFIX, ''));
    }

    /**
     * A numeric setting, falling back to $default when the stored value is
     * empty or not a number (a cleared number input saves '').
     */
    private static function number(string $id, int|float $default): float
    {
        $value = OptionsPage::get($id, self::PREFIX, $default);

        return is_numeric($value) ? (float) $value : (float) $default;
    }

    private static function clampInt(string $id, int $default, int $min, int $max): int
    {
        $value = (int) self::number($id, $default);

        return $value < $min || $value > $max ? $default : $value;
    }
}
