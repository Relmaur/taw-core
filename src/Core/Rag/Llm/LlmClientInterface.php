<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Llm;

if (!defined('ABSPATH')) {
    exit;
}

interface LlmClientInterface
{
    /**
     * @param list<string> $texts
     * @return list<list<float>> One embedding vector per input text, same order.
     */
    public function embeddings(array $texts, string $model): array;

    /**
     * @param list<array<string, mixed>> $messages OpenAI-compatible chat messages.
     * @param list<array<string, mixed>> $tools    OpenAI-compatible function-calling tool definitions (empty = no tools offered).
     * @param array<string, mixed>       $options  Extra body fields (temperature, etc).
     * @return array<string, mixed> The raw assistant message object (may include "tool_calls").
     */
    public function chatCompletion(array $messages, array $tools, string $model, array $options = []): array;
}
