<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the exact byte string that both the Hub and this site independently
 * feed into HMAC-SHA256. The layout is frozen — any change to field order,
 * separators, or hashing is a scheme-version bump (the leading `v1`).
 *
 *   v1 \n
 *   {UPPERCASE HTTP METHOD} \n
 *   {REST route, e.g. /taw-hub/v1/assets/deploy} \n
 *   {lower-case hex sha256 of the raw request body} \n
 *   {unix timestamp, integer seconds} \n
 *   {nonce}
 *
 * Only the route path is signed, not the query string — every mutating
 * endpoint takes its input in the (signed) body, never from unsigned query
 * params.
 */
final class CanonicalRequest
{
    public const SCHEME_VERSION = 'v1';

    public function __construct(
        private string $method,
        private string $routePath,
        private string $body,
        private int $timestamp,
        private string $nonce,
    ) {
    }

    public static function fromInbound(InboundRequest $request): self
    {
        return new self(
            $request->method,
            $request->path,
            $request->body,
            (int) $request->header('x-taw-hub-timestamp'),
            $request->header('x-taw-hub-nonce'),
        );
    }

    public function timestamp(): int
    {
        return $this->timestamp;
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function bytes(): string
    {
        return implode("\n", [
            self::SCHEME_VERSION,
            strtoupper($this->method),
            $this->routePath,
            hash('sha256', $this->body),
            (string) $this->timestamp,
            $this->nonce,
        ]);
    }
}
