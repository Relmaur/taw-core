<?php

declare(strict_types=1);

namespace TAW\Hub\Assets;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by the asset pipeline — extraction, manifest validation, or a
 * deployment transaction. {@see self::reason()} is a stable slug; the
 * `/assets/*` routes map it to a 4xx/5xx.
 */
final class PayloadException extends \RuntimeException
{
    public const NO_ZIP_EXTENSION   = 'no_zip_extension';
    public const ARCHIVE_UNREADABLE = 'archive_unreadable';
    public const ARCHIVE_TOO_LARGE  = 'archive_too_large';
    public const TOO_MANY_ENTRIES   = 'too_many_entries';
    public const PATH_TRAVERSAL     = 'path_traversal';
    public const DISALLOWED_FILE    = 'disallowed_file_type';
    public const SYMLINK_ENTRY      = 'symlink_entry';
    public const ENTRY_TOO_LARGE    = 'entry_too_large';
    public const PAYLOAD_TOO_LARGE  = 'payload_too_large';
    public const COMPRESSION_BOMB   = 'compression_bomb';
    public const WRITE_FAILED       = 'write_failed';
    public const MANIFEST_INVALID   = 'manifest_invalid';
    public const MANIFEST_MISSING   = 'manifest_missing';
    public const DEPLOYMENT_UNKNOWN = 'deployment_unknown';
    public const SWAP_FAILED        = 'swap_failed';

    public function __construct(private string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
