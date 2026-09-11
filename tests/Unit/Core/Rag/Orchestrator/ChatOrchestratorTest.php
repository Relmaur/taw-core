<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\Orchestrator;

use Brain\Monkey\Functions;
use TAW\Core\Rag\Llm\LlmClientInterface;
use TAW\Core\Rag\Orchestrator\ChatOrchestrator;
use TAW\Core\Rag\Tools\RagTool;
use TAW\Tests\TestCase;

final class ChatOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_json_encode')->alias(static fn ($v) => json_encode($v));
    }

    private function toolCallMessage(string $id, string $toolName, array $arguments): array
    {
        return [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => $id,
                    'type' => 'function',
                    'function' => ['name' => $toolName, 'arguments' => json_encode($arguments)],
                ],
            ],
        ];
    }

    private function finalMessage(string $content): array
    {
        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * @param callable(array $arguments): array $behavior
     */
    private function fakeTool(string $name, callable $behavior): RagTool
    {
        return new class($name, $behavior) implements RagTool {
            public function __construct(private string $name, private $behavior)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function definition(): array
            {
                return ['type' => 'function', 'function' => ['name' => $this->name]];
            }

            public function call(array $arguments): array
            {
                return ($this->behavior)($arguments);
            }
        };
    }

    public function test_responds_directly_when_no_tool_call_is_requested(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chatCompletion')->willReturn($this->finalMessage('Hello there!'));

        $orchestrator = new ChatOrchestrator($llm, [], 'test-model');
        $result = $orchestrator->respond('hi');

        $this->assertSame('Hello there!', $result['message']);
        $this->assertSame([], $result['tool_trace']);
    }

    public function test_single_tool_call_is_dispatched_and_result_fed_back(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chatCompletion')->willReturnOnConsecutiveCalls(
            $this->toolCallMessage('call_1', 'echo_tool', ['text' => 'ping']),
            $this->finalMessage('The tool said: pong')
        );

        $tool = $this->fakeTool('echo_tool', static fn (array $args): array => ['echo' => $args['text'] . '-pong']);

        $orchestrator = new ChatOrchestrator($llm, ['echo_tool' => $tool], 'test-model');
        $result = $orchestrator->respond('please echo ping');

        $this->assertSame('The tool said: pong', $result['message']);
        $this->assertCount(1, $result['tool_trace']);
        $this->assertSame('echo_tool', $result['tool_trace'][0]['tool']);
        $this->assertSame(['echo' => 'ping-pong'], $result['tool_trace'][0]['result']);
    }

    public function test_multiple_sequential_tool_calls_across_iterations(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chatCompletion')->willReturnOnConsecutiveCalls(
            $this->toolCallMessage('call_1', 'tool_a', []),
            $this->toolCallMessage('call_2', 'tool_b', []),
            $this->finalMessage('Done using both tools.')
        );

        $toolA = $this->fakeTool('tool_a', static fn (array $args): array => ['result' => 'a']);
        $toolB = $this->fakeTool('tool_b', static fn (array $args): array => ['result' => 'b']);

        $orchestrator = new ChatOrchestrator($llm, ['tool_a' => $toolA, 'tool_b' => $toolB], 'test-model', 5);
        $result = $orchestrator->respond('use both tools');

        $this->assertSame('Done using both tools.', $result['message']);
        $this->assertCount(2, $result['tool_trace']);
        $this->assertSame('tool_a', $result['tool_trace'][0]['tool']);
        $this->assertSame('tool_b', $result['tool_trace'][1]['tool']);
    }

    public function test_iteration_cap_forces_a_final_answer(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        // Always wants to call a tool — the orchestrator must cap out and
        // force one final non-tool completion rather than looping forever.
        $llm->method('chatCompletion')->willReturnCallback(function (array $messages, array $tools) {
            if ($tools === []) {
                return $this->finalMessage('Forced final answer.');
            }

            return $this->toolCallMessage('call_x', 'loop_tool', []);
        });

        $tool = $this->fakeTool('loop_tool', static fn (array $args): array => ['result' => 'again']);

        $orchestrator = new ChatOrchestrator($llm, ['loop_tool' => $tool], 'test-model', 2);
        $result = $orchestrator->respond('keep looping');

        $this->assertSame('Forced final answer.', $result['message']);
        $this->assertCount(2, $result['tool_trace'], 'exactly maxIterations tool calls should have been dispatched before forcing a final answer');
    }

    public function test_unknown_tool_name_produces_an_error_result_without_throwing(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chatCompletion')->willReturnOnConsecutiveCalls(
            $this->toolCallMessage('call_1', 'nonexistent_tool', []),
            $this->finalMessage('I could not find that tool.')
        );

        $orchestrator = new ChatOrchestrator($llm, [], 'test-model');
        $result = $orchestrator->respond('call a tool that does not exist');

        $this->assertSame('I could not find that tool.', $result['message']);
        $this->assertArrayHasKey('error', $result['tool_trace'][0]['result']);
    }

    public function test_a_tool_throwing_does_not_crash_the_loop(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chatCompletion')->willReturnOnConsecutiveCalls(
            $this->toolCallMessage('call_1', 'broken_tool', []),
            $this->finalMessage('Recovered from the tool failure.')
        );

        $tool = $this->fakeTool('broken_tool', static function (): array {
            throw new \RuntimeException('boom');
        });

        $orchestrator = new ChatOrchestrator($llm, ['broken_tool' => $tool], 'test-model');
        $result = $orchestrator->respond('trigger the broken tool');

        $this->assertSame('Recovered from the tool failure.', $result['message']);
        $this->assertSame('boom', $result['tool_trace'][0]['result']['error']);
    }

    public function test_an_unreachable_llm_returns_a_user_facing_apology_instead_of_throwing(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('chatCompletion')->willThrowException(new \RuntimeException('connection refused'));

        $orchestrator = new ChatOrchestrator($llm, [], 'test-model');
        $result = $orchestrator->respond('hello?');

        $this->assertStringContainsString("couldn't reach the assistant", $result['message']);
        $this->assertSame([], $result['tool_trace']);
    }
}
