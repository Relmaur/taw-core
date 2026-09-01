<?php

declare(strict_types=1);

namespace TAW\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reads back what {@see JsonlFileSink} wrote — the shared building block for
 * `bin/taw log:tail` and, in `taw-hub-companion`, the `/logs` REST route.
 *
 * The sink caps the live file at ~5MB by default, so a full-file read on
 * every call is deliberate here: it keeps this class stateless and correct
 * (no partial-line seeking to get wrong) at a cost that stays bounded.
 */
final class LogReader
{
    private const FILE = 'taw.log.jsonl';

    public function __construct(private readonly string $directory)
    {
    }

    public static function default(): self
    {
        $base = defined('WP_CONTENT_DIR') && WP_CONTENT_DIR !== ''
            ? WP_CONTENT_DIR
            : sys_get_temp_dir();

        return new self(rtrim($base, '/') . '/taw-logs');
    }

    /**
     * @return list<array<string, mixed>> Most recent entries last, newest at the end (chronological, matching the file).
     */
    public function tail(int $limit = 100, ?string $level = null, ?string $since = null, ?string $codePrefix = null): array
    {
        $file = $this->directory . '/' . self::FILE;
        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            if ($level !== null && ($decoded['level'] ?? null) !== $level) {
                continue;
            }
            if ($since !== null && (string) ($decoded['ts'] ?? '') < $since) {
                continue;
            }
            if ($codePrefix !== null && !str_starts_with((string) ($decoded['code'] ?? ''), $codePrefix)) {
                continue;
            }

            $entries[] = $decoded;
        }

        if ($limit <= 0) {
            return [];
        }

        return array_slice($entries, -$limit);
    }
}
