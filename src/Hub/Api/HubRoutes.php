<?php

declare(strict_types=1);

namespace TAW\Hub\Api;

use TAW\Hub\HubConfig;
use TAW\Hub\HubServices;
use TAW\Hub\Security\EnrolmentException;
use TAW\Hub\Security\EnrolmentService;
use TAW\Hub\Security\HubAuthMiddleware;
use TAW\Hub\Security\HubIdentity;
use TAW\Hub\Telemetry\AssetInventory;
use TAW\Hub\Telemetry\BlockInventory;
use TAW\Hub\Telemetry\EnvironmentReport;
use TAW\Hub\Telemetry\TelemetrySnapshot;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers every `wp-json/taw-hub/v1/` route:
 *
 *   GET  /health                                       hub:read
 *   GET  /telemetry/(environment|blocks|assets|full)   hub:read
 *   POST /handshake                                    (enrolment token in body)
 *   GET  /command/actions                              hub:read
 *   POST /command   { action, args }                   route: hub:read; action: its own capability
 *   POST /cache/flush   { scopes[] }                   hub:maintenance
 *   PUT  /config/blocks { config, mode }               hub:config
 *   GET  /audit  ?limit=&since=                        hub:read
 *
 * `/assets/deploy` and `/assets/rollback` are driven through `/command`
 * (`deploy-assets` / `rollback-assets`) so there's one dispatch + audit path.
 *
 * A no-op unless {@see HubConfig::enabled()} — the routes aren't registered,
 * so an un-enrolled site just 404s.
 */
final class HubRoutes
{
    public const NAMESPACE = 'taw-hub/v1';

    public function __construct(
        private RestRequestAdapter $auth,
        private EnrolmentService $enrolment,
        private CommandDispatcher $commands,
        private \TAW\Hub\Orchestration\ActionRegistry $registry,
        private \TAW\Hub\Orchestration\AuditLog $audit,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $services = HubServices::boot();

        return new self(
            $services->auth,
            $services->enrolment,
            $services->commands,
            $services->registry,
            $services->audit,
        );
    }

    public function register(): void
    {
        if (!HubConfig::enabled()) {
            return;
        }

        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $read = $this->auth->permission('hub:read');

        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'health'],
            'permission_callback' => $read,
        ]);

        register_rest_route(self::NAMESPACE, '/telemetry/(?P<scope>environment|blocks|assets|full)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'telemetry'],
            'permission_callback' => $read,
            'args'                => ['scope' => ['required' => true, 'sanitize_callback' => 'sanitize_key']],
        ]);

        register_rest_route(self::NAMESPACE, '/handshake', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handshake'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/command/actions', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'actions'],
            'permission_callback' => $read,
        ]);

        register_rest_route(self::NAMESPACE, '/command', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'command'],
            'permission_callback' => $read,
        ]);

        register_rest_route(self::NAMESPACE, '/cache/flush', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'flushCache'],
            'permission_callback' => $this->auth->permission('hub:maintenance'),
        ]);

        register_rest_route(self::NAMESPACE, '/config/blocks', [
            'methods'             => 'PUT',
            'callback'            => [$this, 'syncBlocks'],
            'permission_callback' => $this->auth->permission('hub:config'),
        ]);

        register_rest_route(self::NAMESPACE, '/audit', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'auditTrail'],
            'permission_callback' => $read,
        ]);
    }

    public function health(): \WP_REST_Response
    {
        return new \WP_REST_Response(['status' => 'ok', 'environment' => EnvironmentReport::collect()]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function telemetry(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(match ((string) $request->get_param('scope')) {
            'environment' => EnvironmentReport::collect(),
            'blocks'      => BlockInventory::collect(),
            'assets'      => AssetInventory::collect(),
            default       => TelemetrySnapshot::collect(),
        });
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handshake(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $payload = $request->get_json_params() ?: [];

        try {
            return new \WP_REST_Response($this->enrolment->enrol($payload), 201);
        } catch (EnrolmentException $e) {
            $status = in_array(
                $e->reason(),
                [EnrolmentException::BAD_TOKEN, EnrolmentException::TOKEN_SPENT, EnrolmentException::ENROLMENT_DISABLED],
                true,
            ) ? 403 : 400;

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("[TAW Hub] handshake rejected — {$e->reason()}");

            return new \WP_Error('taw_hub_enrolment_failed', 'Enrolment could not be completed.', ['status' => $status]);
        }
    }

    public function actions(): \WP_REST_Response
    {
        return new \WP_REST_Response(['actions' => $this->registry->describe()]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function command(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = $request->get_json_params() ?: [];
        $action  = is_string($payload['action'] ?? null) ? $payload['action'] : '';
        $args    = is_array($payload['args'] ?? null) ? $payload['args'] : [];

        $result = $this->commands->dispatch($this->identity($request), $action, $args);

        return new \WP_REST_Response($result['body'], $result['status']);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function flushCache(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = $request->get_json_params() ?: [];
        $args    = ['scopes' => $payload['scopes'] ?? null];

        $result = $this->commands->dispatch($this->identity($request), 'flush-caches', $args);

        return new \WP_REST_Response($result['body'], $result['status']);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function syncBlocks(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = $request->get_json_params() ?: [];

        $result = $this->commands->dispatch($this->identity($request), 'sync-blocks', [
            'config'           => $payload['config'] ?? null,
            'mode'             => $payload['mode'] ?? 'merge',
            'expected_version' => $payload['expected_version'] ?? null,
        ]);

        return new \WP_REST_Response($result['body'], $result['status']);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function auditTrail(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'events' => $this->audit->query(
                (int) ($request->get_param('limit') ?? 100),
                (int) ($request->get_param('since') ?? 0),
            ),
        ]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    private function identity(\WP_REST_Request $request): HubIdentity
    {
        $identity = $request->get_param(HubAuthMiddleware::IDENTITY_ATTR);

        // permission_callback always runs first and stashes this; the fallback
        // is just a total-failure guard.
        return $identity instanceof HubIdentity ? $identity : new HubIdentity('-', []);
    }
}
