<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

use TAW\Hub\Security\Contracts\AuditSink;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default {@see AuditSink} — writes one `[TAW Hub]` line per decision to the
 * PHP error log, matching the `[TAW Form]` / `[TAW EmailConfig]` convention
 * elsewhere in the framework. Never throws.
 *
 * Phase 3 adds a persistent, queryable audit log; this stays as the fallback
 * and for environments that don't enable it.
 */
final class ErrorLogAuditSink implements AuditSink
{
    public function rejected(InboundRequest $request, string $reason): void
    {
        $this->write("rejected {$this->target($request)} — {$reason}");
    }

    public function denied(InboundRequest $request, HubIdentity $identity, string $capability): void
    {
        $this->write(
            "denied {$this->target($request)} — key={$identity->keyId()} lacks {$capability}",
        );
    }

    public function accepted(InboundRequest $request, HubIdentity $identity): void
    {
        $this->write("accepted {$this->target($request)} — key={$identity->keyId()}");
    }

    private function target(InboundRequest $request): string
    {
        return $request->method . ' ' . $request->path;
    }

    private function write(string $line): void
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log("[TAW Hub] {$line}");
    }
}
