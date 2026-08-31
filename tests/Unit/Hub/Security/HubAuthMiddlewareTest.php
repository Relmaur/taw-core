<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Hub\Security;

use TAW\Hub\Security\Contracts\AuditSink;
use TAW\Hub\Security\Contracts\RequestVerifier;
use TAW\Hub\Security\HubAuthMiddleware;
use TAW\Hub\Security\HubIdentity;
use TAW\Hub\Security\InboundRequest;
use TAW\Hub\Security\VerificationException;
use TAW\Tests\TestCase;

final class HubAuthMiddlewareTest extends TestCase
{
    /** @var list<array{event: string, capability?: string, reason?: string}> */
    private array $audit = [];

    private function auditSink(): AuditSink
    {
        return new class ($this->audit) implements AuditSink {
            /** @var list<array<string, string>> */
            private array $log;

            /** @param list<array<string, string>> $log */
            public function __construct(array &$log)
            {
                $this->log = &$log;
            }
            public function rejected(InboundRequest $request, string $reason): void
            {
                $this->log[] = ['event' => 'rejected', 'reason' => $reason];
            }
            public function denied(InboundRequest $request, HubIdentity $identity, string $capability): void
            {
                $this->log[] = ['event' => 'denied', 'capability' => $capability];
            }
            public function accepted(InboundRequest $request, HubIdentity $identity): void
            {
                $this->log[] = ['event' => 'accepted'];
            }
        };
    }

    private function verifierReturning(HubIdentity $identity): RequestVerifier
    {
        return new class ($identity) implements RequestVerifier {
            public function __construct(private HubIdentity $identity)
            {
            }
            public function verify(InboundRequest $request): HubIdentity
            {
                return $this->identity;
            }
        };
    }

    private function verifierThrowing(string $reason): RequestVerifier
    {
        return new class ($reason) implements RequestVerifier {
            public function __construct(private string $reason)
            {
            }
            public function verify(InboundRequest $request): HubIdentity
            {
                throw new VerificationException($this->reason);
            }
        };
    }

    private function request(): InboundRequest
    {
        return new InboundRequest('POST', '/taw-hub/v1/assets/deploy', '{}', []);
    }

    public function test_when_disabled_it_returns_404_without_touching_the_verifier(): void
    {
        $verifier = new class implements RequestVerifier {
            public function verify(InboundRequest $request): HubIdentity
            {
                throw new \LogicException('verifier must not be called when disabled');
            }
        };

        $mw = new HubAuthMiddleware($verifier, $this->auditSink(), enabled: false);
        $outcome = $mw->authorize($this->request(), 'hub:deploy');

        $this->assertFalse($outcome->isOk());
        $this->assertSame(404, $outcome->httpStatus());
        $this->assertSame('taw_hub_disabled', $outcome->errorCode());
        $this->assertSame([], $this->audit);
    }

    public function test_a_verification_failure_is_401_and_audited_as_rejected(): void
    {
        $mw = new HubAuthMiddleware(
            $this->verifierThrowing(VerificationException::BAD_SIGNATURE),
            $this->auditSink(),
            enabled: true,
        );

        $outcome = $mw->authorize($this->request(), 'hub:deploy');

        $this->assertFalse($outcome->isOk());
        $this->assertSame(401, $outcome->httpStatus());
        $this->assertSame('taw_hub_unauthorized', $outcome->errorCode());
        $this->assertSame([['event' => 'rejected', 'reason' => 'bad_signature']], $this->audit);
    }

    public function test_a_missing_capability_is_403_and_audited_as_denied(): void
    {
        $mw = new HubAuthMiddleware(
            $this->verifierReturning(new HubIdentity('hub-1', ['hub:read'])),
            $this->auditSink(),
            enabled: true,
        );

        $outcome = $mw->authorize($this->request(), 'hub:deploy');

        $this->assertFalse($outcome->isOk());
        $this->assertSame(403, $outcome->httpStatus());
        $this->assertSame('hub-1', $outcome->identity()?->keyId());
        $this->assertSame([['event' => 'denied', 'capability' => 'hub:deploy']], $this->audit);
    }

    public function test_an_authorized_request_is_ok_and_audited_as_accepted(): void
    {
        $identity = new HubIdentity('hub-1', ['hub:read', 'hub:deploy']);
        $mw = new HubAuthMiddleware($this->verifierReturning($identity), $this->auditSink(), enabled: true);

        $outcome = $mw->authorize($this->request(), 'hub:deploy');

        $this->assertTrue($outcome->isOk());
        $this->assertSame(200, $outcome->httpStatus());
        $this->assertSame($identity, $outcome->identity());
        $this->assertSame([['event' => 'accepted']], $this->audit);
    }

    public function test_a_wildcard_key_passes_any_capability_gate(): void
    {
        $mw = new HubAuthMiddleware(
            $this->verifierReturning(new HubIdentity('break-glass', ['*'])),
            $this->auditSink(),
            enabled: true,
        );

        $this->assertTrue($mw->authorize($this->request(), 'hub:anything')->isOk());
    }
}
