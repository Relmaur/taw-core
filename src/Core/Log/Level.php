<?php

declare(strict_types=1);

namespace TAW\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The severity levels {@see Logger} accepts. Loosely PSR-3, trimmed to what
 * a theme framework actually produces — `alert`/`emergency` are dropped as
 * overkill for anything short of a multi-site outage.
 */
final class Level
{
    public const DEBUG    = 'debug';
    public const INFO     = 'info';
    public const NOTICE   = 'notice';
    public const WARNING  = 'warning';
    public const ERROR    = 'error';
    public const CRITICAL = 'critical';

    /**
     * Ordered lowest → highest, for anything that needs to compare severity
     * (e.g. a future "only ship warning-and-up to the Hub" filter).
     *
     * @var list<string>
     */
    public const ALL = [
        self::DEBUG,
        self::INFO,
        self::NOTICE,
        self::WARNING,
        self::ERROR,
        self::CRITICAL,
    ];

    public static function isValid(string $level): bool
    {
        return in_array($level, self::ALL, true);
    }
}
