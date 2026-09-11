<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Tools;

if (!defined('ABSPATH')) {
    exit;
}

interface RagTool
{
    public function name(): string;

    /**
     * @return array<string, mixed> OpenAI-compatible function-calling tool definition.
     */
    public function definition(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> JSON-serializable tool result (an "error" key on failure).
     */
    public function call(array $arguments): array;
}
