<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: see Exporter.

/**
 * Why a publish step was refused: a stable `code` (`publish_moved`,
 * `publish_token_invalid`…), the HTTP status a route answers it with, and
 * what the caller needs to act on it (`data`: the records that moved, the
 * files still missing…). See {@see Publish}.
 */
final class PublishError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }
}
