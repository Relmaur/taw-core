<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * Runs a command (argument list, never a shell string) in a folder. The
 * update engine's only way out of the process, so tests swap it for a fake.
 */
interface Shell
{
    /**
     * @param list<string> $command
     * @param array<string, string> $env extra environment
     * @return array{code: int, out: string} combined stdout + stderr
     */
    public function run(array $command, string $cwd, array $env = []): array;
}
