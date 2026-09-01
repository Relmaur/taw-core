<?php

declare(strict_types=1);

namespace TAW\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The machine sink — one JSON object per line, appended to a rotating file
 * under `wp-content/taw-logs/`. This is what makes a log entry queryable
 * instead of just readable: {@see LogReader} parses it directly, and the
 * `taw-hub-companion` plugin's `/logs` route serves it to the Hub verbatim.
 *
 * Lives in `wp-content/`, not the public `uploads/` media library — same
 * shelf WordPress core itself uses for `upgrade/`, `cache/`. Still under a
 * conventionally web-servable docroot, so on activation-equivalent first
 * write this seeds the directory with a deny-all `.htaccess` and a
 * "silence is golden" `index.php`, the same guard pattern WP security
 * plugins use for their own log directories.
 */
final class JsonlFileSink implements LogSinkInterface
{
    private const FILE = 'taw.log.jsonl';

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes = 5 * 1024 * 1024,
        private readonly int $keep = 3,
    ) {
    }

    public static function default(): self
    {
        $base = defined('WP_CONTENT_DIR') && WP_CONTENT_DIR !== ''
            ? WP_CONTENT_DIR
            : sys_get_temp_dir();

        return new self(rtrim($base, '/') . '/taw-logs');
    }

    public function write(array $entry): void
    {
        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        $this->ensureDirectory();

        $line = $encoded . "\n";
        $file = $this->directory . '/' . self::FILE;

        $existingSize = is_file($file) ? filesize($file) : false;
        if ($existingSize !== false && $existingSize + strlen($line) > $this->maxBytes) {
            $this->rotate($file);
        }

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Shifts `taw.log.jsonl` → `.1` → `.2` … up to `$this->keep`, discarding
     * whatever falls off the end. `rename()` overwrites an existing target,
     * so this needs no separate unlink step on the platforms this framework
     * targets.
     */
    private function rotate(string $file): void
    {
        for ($i = $this->keep; $i >= 1; $i--) {
            $source = $i === 1 ? $file : $this->rotatedPath($i - 1);
            if (is_file($source)) {
                rename($source, $this->rotatedPath($i));
            }
        }
    }

    private function rotatedPath(int $index): string
    {
        return $this->directory . '/taw.log.' . $index . '.jsonl';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        $htaccess = $this->directory . '/.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }

        $index = $this->directory . '/index.php';
        if (!is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
