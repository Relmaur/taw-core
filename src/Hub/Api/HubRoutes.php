<?php

declare(strict_types=1);

namespace TAW\Hub\Api;

use TAW\Hub\HubConfig;
use TAW\Hub\Security\EnrolmentException;
use TAW\Hub\Security\EnrolmentService;
use TAW\Hub\Security\ErrorLogAuditSink;
use TAW\Hub\Security\HubAuthMiddleware;
use TAW\Hub\Security\KeyRing;
use TAW\Hub\Security\OptionEnrolmentLedger;
use TAW\Hub\Security\OptionKeyStore;
use TAW\Hub\Security\PersistentSiteKeypair;
use TAW\Hub\Security\SchemeRouter;
use TAW\Hub\Security\SignaturePreflight;
use TAW\Hub\Security\TransientNonceStore;
use TAW\Hub\Telemetry\AssetInventory;
use TAW\Hub\Telemetry\BlockInventory;
use TAW\Hub\Telemetry\EnvironmentReport;
use TAW\Hub\Telemetry\TelemetrySnapshot;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the `wp-json/taw-hub/v1/` routes. Phase 3b covers the read-only +
 * enrolment surface:
 *
 *   GET  /health                                  hub:read
 *   GET  /telemetry/(environment|blocks|assets|full)   hub:read
 *   POST /handshake                               (enrolment token in body)
 *
 * A no-op unless {@see HubConfig::enabled()} — the routes aren't even
 * registered, so an un-enrolled site gives a plain 404 with nothing to probe.
 */
final class HubRoutes
{
    public const NAMESPACE = 'taw-hub/v1';

    public function __construct(
        private RestRequestAdapter $auth,
        private EnrolmentService $enrolment,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $store = new OptionKeyStore();

        $preflight  = new SignaturePreflight(
            KeyRing::fromEnvironment($store),
            new TransientNonceStore(),
            HubConfig::maxTimestampDrift(),
        );
        $middleware = new HubAuthMiddleware(
            SchemeRouter::standard($preflight),
            new ErrorLogAuditSink(),
            HubConfig::enabled(),
        );

        $enrolment = new EnrolmentService(
            $store,
            new PersistentSiteKeypair(),
            self::enrolmentToken(),
            new OptionEnrolmentLedger(),
        );

        return new self(new RestRequestAdapter($middleware), $enrolment);
    }

    public function register(): void
    {
        if (!HubConfig::enabled()) {
            return;
        }

        add_action('rest_api_init', function (): void {
            register_rest_route(self::NAMESPACE, '/health', [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'health'],
                'permission_callback' => $this->auth->permission('hub:read'),
            ]);

            register_rest_route(self::NAMESPACE, '/telemetry/(?P<scope>environment|blocks|assets|full)', [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'telemetry'],
                'permission_callback' => $this->auth->permission('hub:read'),
                'args'                => [
                    'scope' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/handshake', [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'handshake'],
                // Pre-enrolment: the enrolment token in the body IS the auth.
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function health(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'status'      => 'ok',
            'environment' => EnvironmentReport::collect(),
        ]);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function telemetry(\WP_REST_Request $request): \WP_REST_Response
    {
        $scope = (string) $request->get_param('scope');

        $body = match ($scope) {
            'environment' => EnvironmentReport::collect(),
            'blocks'      => BlockInventory::collect(),
            'assets'      => AssetInventory::collect(),
            default       => TelemetrySnapshot::collect(),
        };

        return new \WP_REST_Response($body);
    }

    /**
     * @param \WP_REST_Request<array<string, mixed>> $request
     */
    public function handshake(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        // get_json_params() is array-typed in the stubs but null in reality on
        // a bodyless / non-JSON request; ?: normalises both to an empty payload.
        $payload = $request->get_json_params() ?: [];

        try {
            $result = $this->enrolment->enrol($payload);
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

        return new \WP_REST_Response($result, 201);
    }

    private static function enrolmentToken(): ?string
    {
        if (defined('TAW_HUB_ENROLMENT_TOKEN')) {
            $value = constant('TAW_HUB_ENROLMENT_TOKEN');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
