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
 * pattern as {@see \TAW\Core\Form\Turnstile}: OptionsPage fields are
 * readable via the REST API by anyone with `edit_posts`, which makes the
 * options table the wrong place for a secret.
 */
final class RagSettings
{
    private const PREFIX = '_taw_';

    public function __construct()
    {
        new OptionsPage([
            'id'         => 'taw_rag',
            'title'      => 'TAW Chatbot',
            'menu_title' => 'TAW Chatbot',
            'capability' => 'manage_options',
            'prefix'     => self::PREFIX,
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
                    'id'          => 'rag_max_tool_iterations',
                    'label'       => 'Max Tool-Call Iterations',
                    'type'        => 'number',
                    'default'     => 4,
                    'min'         => 1,
                    'max'         => 10,
                ],
                [
                    'id'          => 'rag_public_chat_enabled',
                    'label'       => 'Allow Anonymous Visitors to Chat',
                    'type'        => 'checkbox',
                    'default'     => '1',
                    'description' => 'When off, only logged-in users can use the chat endpoint. Rate limiting applies either way.',
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
        $value = (int) OptionsPage::get('rag_max_tool_iterations', self::PREFIX, 4);

        return $value >= 1 && $value <= 10 ? $value : 4;
    }

    public static function publicChatEnabled(): bool
    {
        return ((string) OptionsPage::get('rag_public_chat_enabled', self::PREFIX, '1')) === '1';
    }
}
