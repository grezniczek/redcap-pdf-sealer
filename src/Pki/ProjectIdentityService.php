<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use OpenSSLAsymmetricKey;
use RuntimeException;

/** Issues one active sealing identity per project on first use. */
final class ProjectIdentityService
{
    private const DOCUMENT_SIGNING_EKU = '1.3.6.1.5.5.7.3.36';

    public function __construct(
        private readonly ProjectBindingRepository $bindings,
        private readonly IdentityRepository $identities,
        private readonly SecretProtector $protector,
        private readonly CertificateIssuer $issuer,
        private readonly PkiHealthService $health,
        private readonly ProjectIssueLock $lock,
        private readonly PkiInitializationLock $configurationLock = new PkiInitializationLock(),
    ) {}

    public function getOrIssue(int $pid): StoredIdentity
    {
        ProjectBindingRepository::assertPid($pid);
        return $this->lock->withLock($pid, function () use ($pid): StoredIdentity {
            $binding = $this->bindings->find($pid);
            if ($binding?->identityId !== null) { return $this->issueOrReuse($pid, $binding); }
            // Lock order: project, then configuration. Retirement and policy saves
            // wait for all new issuance/activation, including already bound projects.
            return $this->configurationLock->withLock(function () use ($pid, $binding): StoredIdentity {
                if ($binding === null && $this->identities->providers()->requiresAssignment()) {
                    throw new CaAssignmentRequired('CA assignment required');
                }
                return $this->issueOrReuse($pid, $binding);
            });
        });
    }

    private function issueOrReuse(int $pid, ?ProjectBinding $binding): StoredIdentity
    {
        $providers = $this->identities->providers();
        $providerId = $binding?->providerId ?? $providers->defaultId();
        $provider = $providers->provider($providerId);
        if ($binding?->identityId !== null) {
            $identity = $this->identities->find($binding->identityId);
            if ($identity === null) {
                throw new RuntimeException('Active project identity is missing');
            }
            $this->assertProjectIdentity($identity, $binding->uuid, $providerId);
            return $identity;
        }

        if ($binding?->pendingProviderId !== null) { throw new ProviderTransitionPending('Provider transition awaits certificate activation'); }
        $providers->assertActive($providerId);
        if ($provider['kind'] === 'external') {
            throw new ProjectCertificateRequired('The assigned external CA requires a project signing certificate');
        }

        if ($this->health->inspectIssuance($provider['issuer_identity_id'], time())->status !== PkiHealth::Ready) {
            throw new RuntimeException('Root PKI is not usable for project issuance');
        }
        $root = $this->identities->find($provider['issuer_identity_id']);
        $rootCertificate = openssl_x509_parse(Certificate::derToPem($root->certificateDer));
        $organization = $rootCertificate['subject']['O'] ?? null;
        if (!is_string($organization) || $organization === '') { throw new RuntimeException('Issuer organization missing'); }
        if ($binding === null) {
            $uuid = $this->issuer->newProjectUuid();
            $this->bindings->bindUuid($pid, $uuid, $providerId);
            $binding = new ProjectBinding($uuid, null, $providerId);
        }

        // An interrupted issuance may have written the identity before its active binding.
        $identity = $this->identities->findUnboundProject($binding->uuid);
        if ($identity === null) {
            $generated = $this->issuer->createProject(
                $organization,
                $binding->uuid,
                $root->asGeneratedIdentity($this->protector),
            );
            $identityId = $this->identities->append('project', $generated, $binding->uuid, $providerId, $root->id);
            $identity = $this->identities->find($identityId);
            if ($identity === null) {
                throw new RuntimeException('Issued project identity is missing');
            }
        }
        $this->assertProjectIdentity($identity, $binding->uuid, $providerId);
        $this->bindings->activate($pid, $binding->uuid, $identity->id);
        return $identity;
    }

    /** Internal service entry: caller holds project/configuration locks and transaction. Always creates a new key. */
    public function issueBuiltinReplacement(string $uuid, string $providerId): StoredIdentity
    {
        $providers = $this->identities->providers();
        $providers->assertActive($providerId);
        $provider = $providers->provider($providerId);
        if ($provider['kind'] !== 'internal'
            || $this->health->inspectIssuance($provider['issuer_identity_id'], time())->status !== PkiHealth::Ready) {
            throw new RuntimeException('Target CA is not usable for local issuance');
        }
        $root = $this->identities->find($provider['issuer_identity_id']);
        $details = openssl_x509_parse(Certificate::derToPem($root->certificateDer));
        $organization = $details['subject']['O'] ?? null;
        if (!is_string($organization) || $organization === '') { throw new RuntimeException('Issuer organization missing'); }
        $generated = $this->issuer->createProject($organization, $uuid, $root->asGeneratedIdentity($this->protector));
        $id = $this->identities->append('project', $generated, $uuid, $providerId, $root->id);
        $identity = $this->identities->find($id);
        if ($identity === null) { throw new RuntimeException('Replacement identity missing'); }
        $this->assertProjectIdentity($identity, $uuid, $providerId);
        return $identity;
    }

    /** Check historical identity integrity before maintenance, allowing an expired leaf/issuer. */
    public function assertReplacementProvenance(StoredIdentity $identity): void
    {
        $certificate = new Certificate();
        $leaf = $certificate->fields($identity->certificateDer);
        $issuer = $certificate->fields($this->issuerCertificate($identity));
        $this->assertProjectIdentity($identity, $identity->projectUuid, $identity->providerId,
            max($leaf['not_before'], $issuer['not_before']));
    }

    /**
     * Read-only status; never issues, activates, repairs, or logs an identity.
     * Only public metadata leaves this method, even when validation fails.
     * @return array{state: string, uuid: ?string, certificate: ?array}
     */
    public function inspect(int $pid, ?int $now = null): array
    {
        ProjectBindingRepository::assertPid($pid);
        $now ??= time();
        $status = ['state' => 'unavailable', 'uuid' => null, 'certificate' => null];
        try {
            $binding = $this->bindings->find($pid);
            if ($binding === null) {
                $status['state'] = $this->identities->providers()->requiresAssignment() ? 'assignment_required' : 'not_issued';
                return $status;
            }
            $status['uuid'] = $binding->uuid;
            if ($binding->identityId === null) {
                if ($binding->pendingProviderId !== null) { $status['state'] = 'transition_pending'; return $status; }
                if ($this->identities->providers()->isRetired($binding->providerId)) {
                    $status['state'] = 'ca_retired';
                    return $status;
                }
                $status['state'] = $this->identities->providers()->provider($binding->providerId)['kind'] === 'external'
                    ? 'awaiting_certificate' : 'pending';
                return $status;
            }
            $identity = $this->identities->find($binding->identityId);
            if ($identity === null || $identity->role !== 'project' || $identity->projectUuid !== $binding->uuid) {
                return $status;
            }
            $details = openssl_x509_parse(Certificate::derToPem($identity->certificateDer));
            if (!is_array($details) || !is_string($details['name'] ?? null)
                || !is_int($details['validFrom_time_t'] ?? null) || !is_int($details['validTo_time_t'] ?? null)) {
                return $status;
            }
            $status['certificate'] = [
                'subject' => $details['name'],
                'fingerprint' => hash('sha256', $identity->certificateDer),
                'valid_from' => $details['validFrom_time_t'],
                'valid_until' => $details['validTo_time_t'],
            ];
            if ($details['validTo_time_t'] < $now) {
                $status['state'] = 'expired';
                return $status;
            }
            if ($details['validFrom_time_t'] > $now) {
                $status['state'] = 'not_yet_valid';
                return $status;
            }
            $status['state'] = 'unusable';
            $this->identities->providers()->provider($binding->providerId);
            $this->assertProjectIdentity($identity, $binding->uuid, $binding->providerId, $now);
            $status['state'] = 'ready';
        } catch (\Throwable) {
            // Do not expose internal storage or key diagnostics in project UI.
        }
        return $status;
    }

    public function issuerCertificate(StoredIdentity $identity): string
    {
        if ($identity->issuerChain !== []) { return $identity->issuerChain[0]; }
        if ($identity->issuerId === null) { throw new RuntimeException('Project issuer is missing'); }
        return $this->identities->publicCertificate($identity->issuerId, 'root');
    }

    public function issuerChain(StoredIdentity $identity): array
    {
        return $identity->issuerChain !== [] ? $identity->issuerChain : [$this->issuerCertificate($identity)];
    }

    private function assertProjectIdentity(StoredIdentity $identity, string $uuid, string $providerId, ?int $now = null): void
    {
        if ($identity->role !== 'project' || $identity->projectUuid !== $uuid || $identity->providerId !== $providerId) {
            throw new RuntimeException('Project identity binding mismatch');
        }
        $provider = $this->identities->providers()->provider($providerId);
        if ($provider['kind'] === 'external') {
            $this->identities->externalValidator()->validate($identity->certificateDer, $identity->issuerChain);
            $identity->privateKey($this->protector);
            return;
        }
        if ($identity->issuerChain !== []) { throw new RuntimeException('Internal identity has external provenance'); }
        $rootDer = $this->issuerCertificate($identity);
        $this->health->assertRootCertificate($rootDer, $now ?? time());
        $der = $identity->certificateDer;
        $certificate = new Certificate();
        $certificate->assertValidAt($der, $now ?? time());
        $certificate->assertUsableForSigning($der);
        if ($certificate->isCertificateAuthority($der)
            || $certificate->extendedKeyUsageWithCriticality($der)[0] !== [self::DOCUMENT_SIGNING_EKU]) {
            throw new RuntimeException('Project certificate profile is invalid');
        }
        $pem = Certificate::derToPem($der);
        $parsed = openssl_x509_parse($pem);
        $rootPublic = openssl_pkey_get_public(Certificate::derToPem($rootDer));
        $projectPublic = openssl_pkey_get_public($pem);
        $keyDetails = $projectPublic instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($projectPublic) : false;
        if (!is_array($parsed) || ($parsed['subject']['CN'] ?? null) !== 'REDCap Project ' . $uuid
            || !$rootPublic instanceof OpenSSLAsymmetricKey
            || !is_array($keyDetails) || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA || $keyDetails['bits'] !== 3072
            || openssl_x509_verify($pem, $rootPublic) !== 1) {
            throw new RuntimeException('Project certificate does not match its recorded issuer');
        }
        $key = $identity->privateKey($this->protector);
        unset($key);
    }
}
