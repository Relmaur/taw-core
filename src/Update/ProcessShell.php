<?php

declare(strict_types=1);

namespace TAW\Update;

use Symfony\Component\Process\Process;

/** The real Shell: symfony/process, no shell, no timeout (Composer can be slow). */
final class ProcessShell implements Shell
{
    public function run(array $command, string $cwd, array $env = []): array
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env + getenv(), null, null);
        $process->run();

        return ['code' => (int) $process->getExitCode(), 'out' => $process->getOutput() . $process->getErrorOutput()];
    }
}
