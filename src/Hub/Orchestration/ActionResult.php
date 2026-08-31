<?php

declare(strict_types=1);

namespace TAW\Hub\Orchestration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The uniform return of every {@see Contracts\Action} — success flag,
 * structured data for the response, and human-readable log lines for the
 * audit trail.
 */
final class ActionResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $log
     */
    private function __construct(
        private bool $ok,
        private array $data,
        private array $log,
        private string $error,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $log
     */
    public static function ok(array $data = [], array $log = []): self
    {
        return new self(true, $data, $log, '');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $log
     */
    public static function failed(string $error, array $data = [], array $log = []): self
    {
        return new self(false, $data, $log, $error);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function error(): string
    {
        return $this->error;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return list<string>
     */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * @return array{ok: bool, data: array<string, mixed>, log: list<string>, error: string}
     */
    public function toArray(): array
    {
        return [
            'ok'    => $this->ok,
            'data'  => $this->data,
            'log'   => $this->log,
            'error' => $this->error,
        ];
    }
}
