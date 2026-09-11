<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Orchestrator;

use TAW\Core\Log\Logger;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Tools\RagTool;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Drives the tool-calling loop for one chat turn: send the conversation
 * (+ tool definitions) to the LLM, dispatch any requested tool calls to
 * the matching {@see RagTool}, feed results back, repeat until the model
 * answers without requesting more tools or {@see self::$maxIterations} is
 * reached.
 *
 * A tool call failing (bad arguments, DB error, unreachable embedding API)
 * is caught and fed back to the model as an error-content tool message —
 * never thrown out of respond(). Only an unreachable/erroring *chat*
 * completion itself surfaces as a user-facing apology, since at that
 * point there's no model response left to continue the loop with.
 */
final class ChatOrchestrator
{
    private const SYSTEM_PROMPT = "You are a helpful assistant for this website. Use the available tools to look up Bible verses, Catechism of Trent entries, or search this site's own content before answering questions that call for those authoritative sources. Answer normally for anything else.";

    /**
     * @param array<string, RagTool> $tools Keyed by tool name (must match each tool's name()).
     */
    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly array $tools,
        private readonly string $model,
        private readonly int $maxIterations = 4,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $history
     * @return array{message: string, tool_trace: list<array<string, mixed>>}
     */
    public function respond(string $userMessage, array $history = []): array
    {
        $messages = array_merge(
            [['role' => 'system', 'content' => self::SYSTEM_PROMPT]],
            $history,
            [['role' => 'user', 'content' => $userMessage]]
        );

        $toolDefinitions = array_map(
            static fn (RagTool $tool): array => $tool->definition(),
            array_values($this->tools)
        );

        $trace = [];

        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $assistantMessage = $this->complete($messages, $toolDefinitions, $trace);
            if ($assistantMessage === null) {
                return $this->failure($trace);
            }

            $messages[] = $assistantMessage;

            $toolCalls = $assistantMessage['tool_calls'] ?? null;
            if (!is_array($toolCalls) || $toolCalls === []) {
                return ['message' => (string) ($assistantMessage['content'] ?? ''), 'tool_trace' => $trace];
            }

            foreach ($toolCalls as $toolCall) {
                $result = $this->dispatchToolCall(is_array($toolCall) ? $toolCall : [], $trace);
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) (is_array($toolCall) ? ($toolCall['id'] ?? '') : ''),
                    'content' => (string) wp_json_encode($result),
                ];
            }
        }

        // Iteration cap reached with the model still requesting tools —
        // force one final non-tool answer from whatever's been gathered
        // rather than looping forever or returning nothing.
        $messages[] = [
            'role' => 'user',
            'content' => 'Please give your best answer now based on the information gathered so far, without calling any more tools.',
        ];

        $final = $this->complete($messages, [], $trace);

        return $final === null
            ? $this->failure($trace)
            : ['message' => (string) ($final['content'] ?? ''), 'tool_trace' => $trace];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $toolDefinitions
     * @param list<array<string, mixed>> $trace
     * @return array<string, mixed>|null Null on an unrecoverable LLM request failure.
     */
    private function complete(array $messages, array $toolDefinitions, array &$trace): ?array
    {
        try {
            return $this->llm->chatCompletion($messages, $toolDefinitions, $this->model);
        } catch (\Throwable $e) {
            Logger::warning('rag.llm_request_failed', 'Chat completion request failed.', [
                'error' => $e->getMessage(),
                'tool_calls_so_far' => count($trace),
            ]);

            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $trace
     * @return array{message: string, tool_trace: list<array<string, mixed>>}
     */
    private function failure(array $trace): array
    {
        return [
            'message' => "Sorry, I couldn't reach the assistant right now. Please try again shortly.",
            'tool_trace' => $trace,
        ];
    }

    /**
     * @param array<string, mixed> $toolCall
     * @param list<array<string, mixed>> $trace
     * @return array<string, mixed>
     */
    private function dispatchToolCall(array $toolCall, array &$trace): array
    {
        $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
        $name = (string) ($function['name'] ?? '');
        $arguments = json_decode((string) ($function['arguments'] ?? '{}'), true);
        if (!is_array($arguments)) {
            $arguments = [];
        }

        $tool = $this->tools[$name] ?? null;

        if ($tool === null) {
            $result = ['error' => "Unknown tool '{$name}'."];
            Logger::warning('rag.tool_call_failed', 'Chat requested an unknown tool.', ['tool' => $name]);
            $trace[] = ['tool' => $name, 'arguments' => $arguments, 'result' => $result];

            return $result;
        }

        try {
            $result = $tool->call($arguments);
            Logger::info('rag.tool_call_dispatched', 'Dispatched a RAG tool call.', ['tool' => $name]);
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage()];
            Logger::warning('rag.tool_call_failed', 'A RAG tool call failed.', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
        }

        $trace[] = ['tool' => $name, 'arguments' => $arguments, 'result' => $result];

        return $result;
    }
}
