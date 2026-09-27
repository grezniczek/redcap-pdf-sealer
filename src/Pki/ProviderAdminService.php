<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use RuntimeException;
use Throwable;

/** CC-only mutations; dispatch checks authorization before constructing this service. */
final class ProviderAdminService
{
    public function __construct(
        private readonly object $framework,
        private readonly ProviderRepository $providers,
        private readonly ProjectBindingRepository $bindings,
        private readonly CaChainValidator $validator,
        private readonly PkiInitializationLock $configurationLock,
        private readonly ProjectIssueLock $projectLock,
    ) {}

    public function register(string $name, string $pem, ?string $source, bool $fallback): string
    {
        $chain = $this->validator->validate($pem);
        return $this->configurationLock->withLock(fn(): string => $this->transaction(function () use ($name, $chain, $source, $fallback): string {
            $id = $this->providers->registerExternal($name, $chain, $source, $fallback);
            $this->audit('register', $id, null);
            return $id;
        }));
    }

    public function saveAssignmentPolicy(bool $required): void
    {
        $this->configurationLock->withLock(fn() => $this->transaction(function () use ($required): void {
            $this->providers->saveAssignmentPolicy($required);
            $this->audit('assignment_policy', null, null, ['assignment_required' => $required ? '1' : '0']);
        }));
    }

    public function assign(int $pid, string $providerId): void
    {
        $this->providers->provider($providerId);
        if (!in_array($pid, array_map('intval', $this->framework->getProjectsWithModuleEnabled()), true)) {
            throw new RuntimeException('Select a project with PDF Sealer enabled');
        }
        $this->projectLock->withLock($pid, function () use ($pid, $providerId): void {
            $this->configurationLock->withLock(fn() => $this->transaction(function () use ($pid, $providerId): void {
                $this->providers->assertActive($providerId);
                $binding = $this->bindings->find($pid);
                if ($binding !== null) {
                    if ($binding->providerId === $providerId) { return; }
                    throw new RuntimeException('This project already has a provider binding; provider transitions are not available yet');
                }
                $uuid = CertificateIssuer::forFramework($this->framework)->newProjectUuid();
                $this->bindings->bindUuid($pid, $uuid, $providerId);
                $this->audit('assign', $providerId, $pid);
            }));
        });
    }

    public function previewRetirement(string $providerId): array
    {
        return $this->configurationLock->withLock(fn() => $this->retirementImpact($providerId));
    }

    private function retirementImpact(string $providerId): array
    {
        $impact = ['provider' => $providerId, 'retired' => $this->providers->isRetired($providerId),
            'is_default' => $this->providers->isDefault($providerId), 'assignment_required' => $this->providers->requiresAssignment(),
            'projects' => $this->bindings->providerUsage($providerId)];
        return $impact + ['review_hash' => hash('sha256', json_encode($impact, JSON_THROW_ON_ERROR))];
    }

    public function setRetired(string $providerId, bool $retired, string $reviewHash, bool $enableAssignmentGate): void
    {
        $this->configurationLock->withLock(function () use ($providerId, $retired, $reviewHash, $enableAssignmentGate): void {
            $impact = $this->retirementImpact($providerId);
            if (!hash_equals($impact['review_hash'], $reviewHash) || $impact['retired'] === $retired) {
                throw new RuntimeException('Provider usage/state changed; review again');
            }
            $needsGate = $retired && $impact['is_default'] && !$impact['assignment_required'];
            if ($needsGate !== $enableAssignmentGate) { throw new RuntimeException('Explicit assignment policy confirmation required'); }
            $this->transaction(function () use ($providerId, $retired, $enableAssignmentGate, $impact): void {
                if ($enableAssignmentGate) {
                    $this->providers->saveAssignmentPolicy(true);
                    $this->audit('assignment_policy', null, null, ['assignment_required' => '1']);
                }
                $this->providers->saveRetired($providerId, $retired);
                $this->audit($retired ? 'retire' : 'reactivate', $providerId, null,
                    ['review_hash' => $impact['review_hash'], 'affected_project_count' => (string) count($impact['projects'])]);
            });
        });
    }

    private function audit(string $action, ?string $providerId, ?int $pid, array $details = []): void
    {
        $id = $this->framework->log('ca_provider_admin', [
            'project_id' => null, 'record' => '', 'action' => $action, 'provider_id' => $providerId,
            'redcap_pid' => $pid === null ? null : (string) $pid,
            'actor' => $this->framework->getUser()->getUsername(),
        ] + $details);
        if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1) { throw new RuntimeException('Provider audit failed'); }
    }

    private function transaction(callable $work): mixed
    {
        if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('Transaction unavailable'); }
        try {
            $result = $work();
            if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Commit failed'); }
            return $result;
        } catch (Throwable $e) {
            $this->framework->query('ROLLBACK', []);
            throw $e;
        }
    }
}
