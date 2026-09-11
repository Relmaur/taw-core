<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Llm;

use TAW\Core\Log\Logger;
use TAW\Core\Rag\RagSettings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI-compatible embeddings/chat-completions client, implemented over
 * `wp_remote_post()` — the same external-call idiom already used
 * throughout this codebase ({@see \TAW\Core\Form\Turnstile},
 * `SubmissionsHandler`'s webhook, `ThemeUpdater`) rather than adding a new
 * Composer HTTP-client dependency. Base URL is admin-configurable
 * ({@see RagSettings::baseUrl()}), so this works against OpenAI's cloud
 * API or any self-hosted OpenAI-compatible endpoint (Ollama, vLLM, etc.).
 */
final class LlmClient implements LlmClientInterface
{
    private const TIMEOUT = 30;

    public function embeddings(array $texts, string $model): array
    {
        $response = $this->request('/embeddings', [
            'model' => $model,
            'input' => $texts,
        ]);

        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new LlmClientException('Embedding response missing "data".');
        }

        $vectors = [];
        foreach ($data as $item) {
            $embedding = is_array($item) ? ($item['embedding'] ?? null) : null;
            if (!is_array($embedding)) {
                throw new LlmClientException('Embedding response item missing "embedding".');
            }
            $vectors[] = array_map('floatval', $embedding);
        }

        return $vectors;
    }

    public function chatCompletion(array $messages, array $tools, string $model, array $options = []): array
    {
        $body = array_merge($options, [
            'model' => $model,
            'messages' => $messages,
        ]);
        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        $response = $this->request('/chat/completions', $body);

        $message = $response['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new LlmClientException('Chat completion response missing choices[0].message.');
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(string $path, array $body): array
    {
        $apiKey = RagSettings::apiKey();
        if ($apiKey === '') {
            throw new LlmClientException('TAW_RAG_API_KEY is not defined in wp-config.php.');
        }

        $url = rtrim(RagSettings::baseUrl(), '/') . $path;

        $response = wp_remote_post($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => (string) wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            Logger::warning('rag.llm_request_failed', 'RAG LLM request failed.', [
                'path' => $path,
                'error' => $response->get_error_message(),
            ]);
            throw new LlmClientException('LLM request failed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            Logger::warning('rag.llm_request_failed', 'RAG LLM request returned a non-success response.', [
                'path' => $path,
                'status' => $status,
            ]);
            throw new LlmClientException("LLM request to {$path} failed with status {$status}.");
        }

        return $decoded;
    }
}
