<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;
use Throwable;

/** CC-authorized transitions. The current provider/signer stays bound until replacement activation. */
final class ProviderTransitionService
{
    public function __construct(
        private readonly object $framework,
        private readonly ProviderRepository $providers,
        private readonly ProjectBindingRepository $bindings,
        private readonly ProjectEnrollmentService $enrollment,
        private readonly ProjectIdentityService $identities,
        private readonly ProjectIssueLock $projectLock,
        private readonly PkiInitializationLock $configurationLock,
    ) {}

    public function preview(int $pid): array
    {
        return $this->withLocks($pid, fn() => $this->snapshot($pid));
    }

    public function start(int $pid, string $target, string $reviewHash): string
    {
        return $this->withLocks($pid, function () use ($pid, $target, $reviewHash): string {
            $view = $this->checkedSnapshot($pid, $reviewHash);
            if ($view['pending_provider_id'] !== null || $view['enrollment_id'] !== null || $view['provider_id'] === $target) {
                throw new RuntimeException('Cancel pending enrollment/transition before selecting a different provider');
            }
            $this->providers->assertActive($target);
            $external = $this->providers->provider($target)['kind'] === 'external';
            return $this->transaction(function () use ($pid, $target, $view, $external): string {
                $transitionId = $this->bindings->startTransition($pid, $target);
                $this->audit('start', $pid, $view, $target, $transitionId);
                if ($external) { return 'pending'; }
                $identity = $this->identities->issueBuiltinReplacement($view['uuid'], $target);
                $this->bindings->replace($pid, $view['uuid'], $view['identity_id'], $identity->id, $target);
                $this->audit('activate', $pid, $view, $target, $transitionId, $identity->id);
                return 'activated';
            });
        });
    }

    public function cancel(int $pid, string $reviewHash): void
    {
        $this->withLocks($pid, function () use ($pid, $reviewHash): void {
            $view = $this->checkedSnapshot($pid, $reviewHash);
            if ($view['transition_id'] === null) { throw new RuntimeException('No pending transition'); }
            $this->transaction(function () use ($pid, $view): void {
                if ($view['enrollment_id'] !== null) { $this->enrollment->cancelWithinTransaction($pid, $view['enrollment_id']); }
                $this->bindings->cancelTransition($pid, $view['transition_id']);
                $this->audit('cancel', $pid, $view, $view['pending_provider_id'], $view['transition_id']);
            });
        });
    }

    private function snapshot(int $pid): array
    {
        if (!in_array($pid, array_map('intval', $this->framework->getProjectsWithModuleEnabled()), true)) {
            throw new RuntimeException('Project must have PDF Sealer enabled');
        }
        $binding = $this->bindings->find($pid);
        if ($binding === null) { throw new RuntimeException('Project provider assignment required'); }
        $this->providers->provider($binding->providerId);
        if ($binding->pendingProviderId !== null) { $this->providers->provider($binding->pendingProviderId); }
        $enrollment = $this->enrollment->inspect($pid);
        $view = ['pid' => $pid, 'uuid' => $binding->uuid, 'provider_id' => $binding->providerId,
            'identity_id' => $binding->identityId, 'pending_provider_id' => $binding->pendingProviderId,
            'transition_id' => $binding->transitionId, 'enrollment_id' => $enrollment['id'] ?? null];
        return $view + ['review_hash' => hash('sha256', json_encode($view, JSON_THROW_ON_ERROR))];
    }

    private function checkedSnapshot(int $pid, string $hash): array
    {
        $view = $this->snapshot($pid);
        if (!hash_equals($view['review_hash'], $hash)) { throw new RuntimeException('Project state changed; review again'); }
        return $view;
    }

    private function withLocks(int $pid, callable $work): mixed
    {
        return $this->projectLock->withLock($pid, fn() => $this->configurationLock->withLock($work));
    }

    private function audit(string $action, int $pid, array $view, string $target, string $transitionId, ?string $identityId = null): void
    {
        $id = $this->framework->log('provider_transition', ['project_id' => null, 'record' => '', 'action' => $action,
            'redcap_pid' => (string) $pid, 'project_uuid' => $view['uuid'], 'previous_provider_id' => $view['provider_id'],
            'provider_id' => $target, 'transition_id' => $transitionId, 'previous_identity_id' => $view['identity_id'],
            'identity_id' => $identityId, 'actor' => $this->framework->getUser()->getUsername()]);
        if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1) { throw new RuntimeException('Transition audit failed'); }
    }

    private function transaction(callable $work): mixed
    {
        if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('Transaction unavailable'); }
        try {
            $result = $work();
            if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Commit failed'); }
            return $result;
        } catch (Throwable $e) { $this->framework->query('ROLLBACK', []); throw $e; }
    }
}
