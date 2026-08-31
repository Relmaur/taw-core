<?php

declare(strict_types=1);

namespace TAW\Hub\Api;

use TAW\Hub\Security\HubAuthMiddleware;
use TAW\Hub\Security\InboundRequest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The one WordPress-coupled seam between the REST API and the WP-free
 * {@see HubAuthMiddleware}: turns a `WP_REST_Request` into an
 * {@see InboundRequest}, runs the middleware, and produces a
 * `permission_callback` return value (`true` / `WP_Error`).
 *
 * On success the resolved {@see \TAW\Hub\Security\HubIdentity} is stashed on
 * the request under {@see HubAuthMiddleware::IDENTITY_ATTR} for the handler.
 */
final class RestRequestAdapter
{
    public function __construct(private HubAuthMiddleware $middleware)
    {
    }

    /**
     * @return \Closure(\WP_REST_Request<array<string, mixed>>): (true|\WP_Error)
     */
    public function permission(string $capability): \Closure
    {
        return function (\WP_REST_Request $request) use ($capability): bool|\WP_Error {
            $outcome = $this->middleware->authorize(
                InboundRequest::fromRestRequest($request),
                $capability,
            );

            if ($outcome->isOk()) {
                $request->set_param(HubAuthMiddleware::IDENTITY_ATTR, $outcome->identity());

                return true;
            }

            return new \WP_Error(
                $outcome->errorCode(),
                'Hub request rejected.',
                ['status' => $outcome->httpStatus()],
            );
        };
    }
}
