<?php

declare(strict_types=1);

namespace TAW\Core\Log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The framework's log facade — a structured replacement for the hand-rolled
 * `error_log('[TAW X] …')` calls scattered across the codebase.
 *
 * Every entry carries both a human sentence and a machine-stable dot-path
 * `code` (`subsystem.event`, e.g. `form.email_delivery_failed`) plus a
 * `context` array of the concrete values involved — the code is what an AI
 * agent or the TAW Hub filters/greps on, the message is what a person reads.
 * By default every entry goes to two sinks: {@see ErrorLogSink} (human,
 * zero infra) and {@see JsonlFileSink} (machine, what `bin/taw log:tail`
 * and the `taw-hub-companion` `/logs` route read).
 *
 * Usage:
 *   Logger::warning('form.turnstile_request_failed', 'Turnstile verification request failed.', [
 *       'error' => $response->get_error_message(),
 *   ]);
 *
 * `code` naming convention — lowercase, dot-namespaced by subsystem:
 * `form.*`, `mail.*`, `svg.*`, `theme_updater.*`, `sync.*` … Treat existing
 * codes as a stable contract once shipped; add new ones freely, don't repurpose
 * old ones for a different meaning.
 */
final class Logger
{
    /** @var list<LogSinkInterface>|null */
    private static ?array $sinks = null;

    private static ?string $requestId = null;

    public static function debug(string $code, string $message, array $context = []): void
    {
        self::log(Level::DEBUG, $code, $message, $context);
    }

    public static function info(string $code, string $message, array $context = []): void
    {
        self::log(Level::INFO, $code, $message, $context);
    }

    public static function notice(string $code, string $message, array $context = []): void
    {
        self::log(Level::NOTICE, $code, $message, $context);
    }

    public static function warning(string $code, string $message, array $context = []): void
    {
        self::log(Level::WARNING, $code, $message, $context);
    }

    public static function error(string $code, string $message, array $context = []): void
    {
        self::log(Level::ERROR, $code, $message, $context);
    }

    public static function critical(string $code, string $message, array $context = []): void
    {
        self::log(Level::CRITICAL, $code, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $code, string $message, array $context = []): void
    {
        if (!Level::isValid($level)) {
            $level = Level::INFO;
        }

        $entry = [
            'ts'         => gmdate('c'),
            'level'      => $level,
            'code'       => $code,
            'message'    => $message,
            'context'    => $context,
            'request_id' => self::requestId(),
        ];

        // Lets a consuming site (or taw-hub-companion) enrich every entry —
        // e.g. tag it with a site identifier — without taw-core knowing
        // anything about what's listening.
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('taw_core_log_entry', $entry, $code, $level);
            if (is_array($filtered)) {
                /** @var array{ts: string, level: string, code: string, message: string, context: array<string, mixed>, request_id: string} $filtered */
                $entry = $filtered;
            }
        }

        foreach (self::sinks() as $sink) {
            $sink->write($entry);
        }
    }

    /**
     * Swap the active sinks — for tests, or a site that wants to add/replace
     * destinations outside the `taw_core_log_sinks` filter.
     */
    public static function setSinks(LogSinkInterface ...$sinks): void
    {
        self::$sinks = $sinks;
    }

    /**
     * Back to the default sinks (and a fresh request id) — mainly for test
     * teardown between cases.
     */
    public static function reset(): void
    {
        self::$sinks     = null;
        self::$requestId = null;
    }

    private static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(4)) . '-' . (string) time();
        }

        return self::$requestId;
    }

    /**
     * @return list<LogSinkInterface>
     */
    private static function sinks(): array
    {
        if (self::$sinks === null) {
            self::$sinks = self::defaultSinks();
        }

        return self::$sinks;
    }

    /**
     * @return list<LogSinkInterface>
     */
    private static function defaultSinks(): array
    {
        $sinks = [new ErrorLogSink(), JsonlFileSink::default()];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('taw_core_log_sinks', $sinks);
            if (is_array($filtered)) {
                $sinks = array_values(array_filter($filtered, static fn (mixed $sink): bool => $sink instanceof LogSinkInterface));
            }
        }

        return $sinks;
    }
}
