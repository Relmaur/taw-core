<?php

declare(strict_types=1);

namespace TAW\Hub\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A framework-agnostic snapshot of the parts of an HTTP request the Hub
 * security layer needs: verb, REST route, raw body bytes, and the handful of
 * `X-TAW-Hub-*` headers.
 *
 * Decoupling the verifier from {@see \WP_REST_Request} keeps the crypto logic
 * unit-testable with no WordPress bootstrap — {@see self::fromRestRequest()}
 * is the only WP-coupled seam, and it does nothing but copy fields across.
 */
final class InboundRequest
{
    private const HUB_HEADERS = [
        'x-taw-hub-scheme',
        'x-taw-hub-key-id',
        'x-taw-hub-timestamp',
        'x-taw-hub-nonce',
        'x-taw-hub-signature',
    ];

    /**
     * @param array<string, string> $headers Lower-cased header name => value.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $body,
        private array $headers,
    ) {
    }

    public static function fromRestRequest(\WP_REST_Request $request): self
    {
        $headers = [];
        foreach (self::HUB_HEADERS as $name) {
            // WP_REST_Request::get_header() canonicalises the name itself, so
            // the client's casing / separator style doesn't matter here.
            $headers[$name] = (string) $request->get_header($name);
        }

        return new self(
            strtoupper((string) $request->get_method()),
            (string) $request->get_route(),
            (string) $request->get_body(),
            $headers,
        );
    }

    /**
     * Header value by name (case-insensitive); empty string when absent.
     */
    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
