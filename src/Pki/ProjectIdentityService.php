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
            if ($binding !== null) { return $this->issueOrReuse($pid, $binding); }
            // Lock order: project, then configuration. Policy saves only take the
            // configuration lock. Hold it through issuance so a successful save
            // cannot leave an automatic first issuance running in the background.
            return $this->configurationLock->withLock(function () use ($pid): StoredIdentity {
                if ($this->identities->providers()->requiresAssignment()) {
                    throw new CaAssignmentRequired('CA assignment required');
                }
                return $this->issueOrReuse($pid, null);
            });
        });
    }

    private function issueOrReuse(int $pid, ?ProjectBinding $binding): StoredIdentity
    {
        $providers = $this->identities->providers();
        $providerId = $binding?->providerId ?? $providers->defaultId();
        $provider = $providers->provider($providerId);
        if ($provider['kind'] === 'external') {
            throw new ProjectCertificateRequired('The assigned external CA requires a project signing certificate');
        }
        if ($binding?->identityId !== null) {
            $identity = $this->identities->find($binding->identityId);
            if ($identity === null) {
                throw new RuntimeException('Active project identity is missing');
            }
            $this->assertProjectIdentity($identity, $binding->uuid, $providerId);
            return $identity;
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
        if ($identity->issuerId === null) { throw new RuntimeException('Project issuer is missing'); }
        return $this->identities->publicCertificate($identity->issuerId, 'root');
    }

    private function assertProjectIdentity(StoredIdentity $identity, string $uuid, string $providerId, ?int $now = null): void
    {
        if ($identity->role !== 'project' || $identity->projectUuid !== $uuid || $identity->providerId !== $providerId) {
            throw new RuntimeException('Project identity binding mismatch');
        }
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
