<?php

declare(strict_types=1);

namespace TAW\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The human sink — one readable line to PHP's `error_log()`, the same place
 * every hand-written `error_log('[TAW …] …')` call already went. Zero
 * infrastructure: works with whatever the host already tails (hosting
 * panel, `wp debug log`, `tail -f`).
 *
 * Format: `[TAW] [LEVEL] subsystem.code: message {"context":"as json"}`
 */
final class ErrorLogSink implements LogSinkInterface
{
    private \Closure $writer;

    /**
     * @param (\Closure(string): mixed)|null $writer Line writer; defaults to PHP's `error_log()`. Injectable for tests.
     */
    public function __construct(?\Closure $writer = null)
    {
        $this->writer = $writer ?? static fn (string $line): mixed => error_log($line);
    }

    public function write(array $entry): void
    {
        $line = sprintf('[TAW] [%s] %s: %s', strtoupper($entry['level']), $entry['code'], $entry['message']);

        if ($entry['context'] !== []) {
            $encoded = json_encode($entry['context'], JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $line .= ' ' . $encoded;
            }
        }

        ($this->writer)($line);
    }
}
