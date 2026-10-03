<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;
use Throwable;

/** Same-provider built-in renewal for CC review or bounded cron; no key export. */
final class ProjectRenewalService
{
    public function __construct(
        private readonly object $framework,
        private readonly ProjectBindingRepository $bindings,
        private readonly IdentityRepository $identities,
        private readonly ProjectEnrollmentService $enrollment,
        private readonly ProjectIdentityService $projects,
        private readonly PkiHealthService $health,
        private readonly ProjectIssueLock $projectLock,
        private readonly PkiInitializationLock $configurationLock,
    ) {}

    /** Public review only; neither the current key nor the issuer key is decrypted. */
    public function preview(int $pid): array
    {
        return $this->withLocks($pid, fn() => $this->snapshot($pid));
    }

    public function renew(int $pid, string $reviewHash): array
    {
        return $this->withLocks($pid, function () use ($pid, $reviewHash): array {
            $view = $this->snapshot($pid);
            if (!hash_equals($view['review_hash'], $reviewHash)) {
                throw new RuntimeException('Renewal state changed; review again');
            }
            return $this->activateReplacement($pid, $view, $this->framework->getUser()->getUsername(), 'manual');
        });
    }

    /** Cron entry: no human review/session; recheck eligibility under the same locks as CC renewal. */
    public function renewAutomatically(int $pid, int $now): string
    {
        return $this->withLocks($pid, function () use ($pid, $now): string {
            $now = max($now, time());
            $binding = $this->bindings->find($pid);
            if ($binding === null || $binding->identityId === null || $binding->providerId !== ProviderRepository::BUILTIN_CA) {
                return 'skipped';
            }
            if ($binding->pendingProviderId !== null || $this->enrollment->inspect($pid) !== null
                || $this->identities->providers()->isRetired($binding->providerId)) {
                return 'deferred';
            }
            $view = $this->snapshot($pid, false, $now);
            $identity = $this->identities->find($view['identity_id']);
            $revoked = $this->identities->revocations()->find($identity) !== null
                || $this->identities->rootRevocations()->find($this->projects->issuerCertificate($identity)) !== null;
            if (!$revoked && !LeafRenewalPolicy::due($view['certificate']['valid_until'], $identity->issuerId, $view['issuer_identity_id'], $now)) {
                return 'skipped';
            }
            LeafRenewalPolicy::assertIssuerWindow($view['issuer_valid_until'], $now);
            if (!$revoked) { $this->projects->assertReplacementProvenance($identity); }
            $this->activateReplacement($pid, $view, 'system:cron', $revoked ? 'revocation_recovery' : 'automatic');
            return 'renewed';
        });
    }

    private function activateReplacement(int $pid, array $view, string $actor, string $reason): array
    {
        if ($this->framework->query('START TRANSACTION', []) === false) {
            throw new RuntimeException('Renewal transaction unavailable');
        }
        try {
            $identity = $this->projects->issueBuiltinReplacement($view['uuid'], $view['provider_id']);
            $this->bindings->replace($pid, $view['uuid'], $view['identity_id'], $identity->id);
            $fingerprint = hash('sha256', $identity->certificateDer);
            $id = $this->framework->log('project_certificate_renewal', [
                'project_id' => null, 'record' => '', 'redcap_pid' => (string) $pid,
                'project_uuid' => $view['uuid'], 'provider_id' => $view['provider_id'],
                'previous_identity_id' => $view['identity_id'], 'identity_id' => $identity->id,
                'previous_certificate_sha256' => $view['certificate']['fingerprint'],
                'certificate_sha256' => $fingerprint, 'issuer_identity_id' => $view['issuer_identity_id'],
                'actor' => $actor, 'reason' => $reason,
            ]);
            if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id < 1) {
                throw new RuntimeException('Renewal audit failed');
            }
            if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('Renewal commit failed'); }
            return ['identity_id' => $identity->id, 'fingerprint' => $fingerprint];
        } catch (Throwable $e) {
            $this->framework->query('ROLLBACK', []);
            throw $e;
        }
    }

    private function snapshot(int $pid, bool $requireEnabled = true, ?int $now = null): array
    {
        if ($requireEnabled && !in_array($pid, array_map('intval', $this->framework->getProjectsWithModuleEnabled()), true)) {
            throw new RuntimeException('Renewal requires PDF Sealer enabled');
        }
        $binding = $this->bindings->find($pid);
        if ($binding === null || $binding->identityId === null || $binding->pendingProviderId !== null
            || $this->enrollment->inspect($pid) !== null) {
            throw new RuntimeException('Renewal requires an existing signer and no pending enrollment or transition');
        }
        $providers = $this->identities->providers();
        $providers->assertActive($binding->providerId);
        $provider = $providers->provider($binding->providerId);
        if ($provider['kind'] !== 'internal') { throw new RuntimeException('External certificates use the CSR workflow'); }
        $identity = $this->identities->find($binding->identityId);
        if ($identity === null || $identity->role !== 'project' || $identity->projectUuid !== $binding->uuid
            || $identity->providerId !== $binding->providerId || $identity->issuerChain !== [] || $identity->issuerId === null) {
            throw new RuntimeException('Renewal identity binding invalid');
        }
        $pem = Certificate::derToPem($identity->certificateDer);
        $details = openssl_x509_parse($pem);
        $oldIssuer = openssl_pkey_get_public(Certificate::derToPem($this->identities->publicCertificate($identity->issuerId, 'root')));
        if (!is_array($details) || ($details['subject']['CN'] ?? null) !== 'REDCap Project ' . $binding->uuid
            || !is_int($details['validFrom_time_t'] ?? null) || !is_int($details['validTo_time_t'] ?? null)
            || $oldIssuer === false || openssl_x509_verify($pem, $oldIssuer) !== 1) {
            throw new RuntimeException('Renewal certificate provenance invalid');
        }
        // Expiration of the old leaf does not block an explicit replacement.
        $issuerDer = $this->identities->publicCertificate($provider['issuer_identity_id'], 'root');
        $this->health->assertRootCertificate($issuerDer, $now ?? time());
        $issuerDetails = openssl_x509_parse(Certificate::derToPem($issuerDer));
        $view = ['pid' => $pid, 'uuid' => $binding->uuid, 'provider_id' => $binding->providerId,
            'identity_id' => $binding->identityId, 'issuer_identity_id' => $provider['issuer_identity_id'],
            'issuer_fingerprint' => hash('sha256', $issuerDer), 'issuer_valid_until' => $issuerDetails['validTo_time_t'],
            'certificate' => ['subject' => $details['name'], 'fingerprint' => hash('sha256', $identity->certificateDer),
                'valid_from' => $details['validFrom_time_t'], 'valid_until' => $details['validTo_time_t']]];
        return $view + ['review_hash' => hash('sha256', json_encode($view, JSON_THROW_ON_ERROR))];
    }

    private function withLocks(int $pid, callable $work): mixed
    {
        return $this->projectLock->withLock($pid, fn() => $this->configurationLock->withLock($work));
    }
}
