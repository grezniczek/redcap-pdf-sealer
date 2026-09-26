<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
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
    ) {}

    public function getOrIssue(int $pid): StoredIdentity
    {
        ProjectBindingRepository::assertPid($pid);
        return $this->lock->withLock($pid, function () use ($pid): StoredIdentity {
            $status = $this->health->inspect(time())->status;
            if ($status !== PkiHealth::Ready && $status !== PkiHealth::Degraded) {
                throw new RuntimeException('Root PKI is not usable for project issuance');
            }
            $rootId = $this->identities->activeId('root');
            $root = $rootId === null ? null : $this->identities->find($rootId);
            if ($root === null || $root->role !== 'root') {
                throw new RuntimeException('Active root identity is missing');
            }
            $rootCertificate = openssl_x509_parse(Certificate::derToPem($root->certificateDer));
            $organization = $rootCertificate['subject']['O'] ?? null;
            if (!is_string($organization) || $organization === '') {
                throw new RuntimeException('Active root organization is missing');
            }

            $binding = $this->bindings->find($pid);
            if ($binding === null) {
                $uuid = $this->issuer->newProjectUuid();
                $this->bindings->bindUuid($pid, $uuid);
                $binding = new ProjectBinding($uuid, null);
            }
            if ($binding->identityId !== null) {
                $identity = $this->identities->find($binding->identityId);
                if ($identity === null) {
                    throw new RuntimeException('Active project identity is missing');
                }
                $this->assertProjectIdentity($identity, $binding->uuid, $root);
                return $identity;
            }

            // An interrupted issuance may have written the identity before its active binding.
            $identity = $this->identities->findUnboundProject($binding->uuid);
            if ($identity === null) {
                $generated = $this->issuer->createProject(
                    $organization,
                    $binding->uuid,
                    $root->asGeneratedIdentity($this->protector),
                );
                $identityId = $this->identities->append('project', $generated, $binding->uuid);
                $identity = $this->identities->find($identityId);
                if ($identity === null) {
                    throw new RuntimeException('Issued project identity is missing');
                }
            }
            $this->assertProjectIdentity($identity, $binding->uuid, $root);
            $this->bindings->activate($pid, $binding->uuid, $identity->id);
            return $identity;
        });
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
                $status['state'] = 'not_issued';
                return $status;
            }
            $status['uuid'] = $binding->uuid;
            if ($binding->identityId === null) {
                $status['state'] = 'pending';
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
            $rootId = $this->identities->activeId('root');
            $root = $rootId === null ? null : $this->identities->find($rootId);
            if ($root !== null && $root->role === 'root') {
                $this->assertProjectIdentity($identity, $binding->uuid, $root, $now);
                $status['state'] = 'ready';
            }
        } catch (\Throwable) {
            // Do not expose internal storage or key diagnostics in project UI.
        }
        return $status;
    }

    private function assertProjectIdentity(StoredIdentity $identity, string $uuid, StoredIdentity $root, ?int $now = null): void
    {
        if ($identity->role !== 'project' || $identity->projectUuid !== $uuid) {
            throw new RuntimeException('Project identity binding mismatch');
        }
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
        $rootPublic = openssl_pkey_get_public(Certificate::derToPem($root->certificateDer));
        $projectPublic = openssl_pkey_get_public($pem);
        $keyDetails = $projectPublic instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($projectPublic) : false;
        if (!is_array($parsed) || ($parsed['subject']['CN'] ?? null) !== 'REDCap Project ' . $uuid
            || !$rootPublic instanceof OpenSSLAsymmetricKey
            || !is_array($keyDetails) || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA || $keyDetails['bits'] !== 3072
            || openssl_x509_verify($pem, $rootPublic) !== 1) {
            throw new RuntimeException('Project certificate is not issued by the active root');
        }
        $key = $identity->privateKey($this->protector);
        unset($key);
    }
}
