<?php

declare(strict_types=1);

namespace TAW\Hub;

use TAW\Helpers\Framework;
use TAW\Hub\Api\CommandDispatcher;
use TAW\Hub\Api\RestRequestAdapter;
use TAW\Hub\Assets\DeploymentTransaction;
use TAW\Hub\Orchestration\ActionRegistry;
use TAW\Hub\Orchestration\Actions\DeployAssetsAction;
use TAW\Hub\Orchestration\Actions\FlushCachesAction;
use TAW\Hub\Orchestration\Actions\ReportTelemetryAction;
use TAW\Hub\Orchestration\Actions\RollbackAssetsAction;
use TAW\Hub\Orchestration\Actions\SyncBlocksAction;
use TAW\Hub\Orchestration\ArrayAuditStore;
use TAW\Hub\Orchestration\AuditLog;
use TAW\Hub\Orchestration\WpdbAuditStore;
use TAW\Hub\Security\EnrolmentService;
use TAW\Hub\Security\HubAuthMiddleware;
use TAW\Hub\Security\KeyRing;
use TAW\Hub\Security\OptionEnrolmentLedger;
use TAW\Hub\Security\OptionKeyStore;
use TAW\Hub\Security\PersistentSiteKeypair;
use TAW\Hub\Security\SchemeRouter;
use TAW\Hub\Security\SignaturePreflight;
use TAW\Hub\Security\TransientNonceStore;
use TAW\Support\ViteLoader;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The composition root for the Hub integration — builds the fully-wired
 * middleware, action registry, command dispatcher, and enrolment service from
 * the real environment. Shared by the REST layer ({@see Api\HubRoutes}) and,
 * later, the `wp taw …` commands, so both go through exactly the same objects.
 */
final class HubServices
{
    private function __construct(
        public readonly RestRequestAdapter $auth,
        public readonly EnrolmentService $enrolment,
        public readonly CommandDispatcher $commands,
        public readonly ActionRegistry $registry,
        public readonly AuditLog $audit,
    ) {
    }

    public static function boot(): self
    {
        $keyStore = new OptionKeyStore();

        $preflight  = new SignaturePreflight(
            KeyRing::fromEnvironment($keyStore),
            new TransientNonceStore(),
            HubConfig::maxTimestampDrift(),
        );

        $audit = new AuditLog(self::auditStore());

        $middleware = new HubAuthMiddleware(
            SchemeRouter::standard($preflight),
            $audit,
            HubConfig::enabled(),
        );

        $registry = self::registry();

        return new self(
            new RestRequestAdapter($middleware),
            new EnrolmentService($keyStore, new PersistentSiteKeypair(), self::enrolmentToken(), new OptionEnrolmentLedger()),
            new CommandDispatcher($registry, $audit),
            $registry,
            $audit,
        );
    }

    public static function registry(): ActionRegistry
    {
        $transactionFactory = static fn (): DeploymentTransaction => new DeploymentTransaction(
            Framework::themePath(ViteLoader::distDir()),
        );

        return new ActionRegistry([
            new ReportTelemetryAction(),
            new FlushCachesAction(),
            new SyncBlocksAction(),
            new DeployAssetsAction($transactionFactory),
            new RollbackAssetsAction($transactionFactory),
        ]);
    }

    private static function auditStore(): Orchestration\Contracts\AuditStore
    {
        // Prefer the DB table; WpdbAuditStore itself degrades to a no-op read
        // if the table is missing, and AuditLog never throws either way.
        return function_exists('is_multisite') ? new WpdbAuditStore() : new ArrayAuditStore();
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
