<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Throwable;

/** Read-only health assessment. It never creates or replaces identities. */
final class PkiHealthService
{
    private const TSA_EKU = '1.3.6.1.5.5.7.3.8';

    private Certificate $certificate;

    public function __construct(
        private readonly IdentityRepository $identities,
        private readonly SecretProtector $protector,
    ) {
        $this->certificate = new Certificate();
    }

    public function inspect(int $now): PkiHealthReport
    {
        try {
            $rootId = $this->identities->activeId('root');
            if ($rootId === null) {
                return $this->identities->hasRole('root')
                    ? new PkiHealthReport(PkiHealth::Broken, 'ROOT_POINTER_MISSING')
                    : new PkiHealthReport(PkiHealth::Uninitialized);
            }
            $root = $this->identities->find($rootId);
            if ($root === null || $root->role !== 'root') {
                return new PkiHealthReport(PkiHealth::Broken, 'ROOT_RECORD_MISSING', $rootId);
            }
            $this->assertRoot($root, $now);
        } catch (Throwable $e) {
            return new PkiHealthReport(PkiHealth::Broken, 'ROOT_IDENTITY_INVALID', $rootId ?? null);
        }

        try {
            $tsaId = $this->identities->activeId('tsa');
            if ($tsaId === null) {
                return new PkiHealthReport(PkiHealth::Degraded, 'TSA_POINTER_MISSING');
            }
            $tsa = $this->identities->find($tsaId);
            if ($tsa === null || $tsa->role !== 'tsa') {
                return new PkiHealthReport(PkiHealth::Degraded, 'TSA_RECORD_MISSING', $tsaId);
            }
            $this->assertTsa($tsa, $root->certificateDer, $now);
        } catch (Throwable $e) {
            return new PkiHealthReport(PkiHealth::Degraded, 'TSA_IDENTITY_INVALID', $tsaId ?? null);
        }

        return new PkiHealthReport(PkiHealth::Ready);
    }

    public function inspectIssuance(string $rootId, int $now): PkiHealthReport
    {
        try {
            $root = $this->identities->find($rootId);
            if ($root === null || $root->role !== 'root') { throw new RuntimeException('Issuer unavailable'); }
            $this->assertRoot($root, $now);
            return new PkiHealthReport(PkiHealth::Ready);
        } catch (Throwable) {
            return new PkiHealthReport(PkiHealth::Broken, 'ROOT_IDENTITY_INVALID', $rootId);
        }
    }

    public function inspectTimestamp(string $sourceId, int $now): PkiHealthReport
    {
        try {
            $source = $this->identities->providers()->source($sourceId);
            $this->captureTimestamp($source, $now);
            return new PkiHealthReport(PkiHealth::Ready);
        } catch (Throwable) {
            return new PkiHealthReport(PkiHealth::Degraded, 'TSA_IDENTITY_INVALID', $source['identity_id'] ?? null);
        }
    }

    /** Validate and return exactly the immutable identities referenced by one captured source record. */
    public function captureTimestamp(array $source, int $now): TsaIdentity
    {
        if (($source['kind'] ?? null) !== 'internal') { throw new RuntimeException('Internal timestamp source required'); }
        $rootDer = $this->identities->publicCertificate($source['issuer_identity_id'], 'root');
        $this->assertRootCertificate($rootDer, $now);
        $tsa = $this->identities->find($source['identity_id']);
        if ($tsa === null || $tsa->role !== 'tsa') { throw new RuntimeException('TSA unavailable'); }
        $key = $this->assertTsa($tsa, $rootDer, $now);
        return new TsaIdentity($tsa->certificateDer, $key, [$rootDer]);
    }

    private function assertRoot(StoredIdentity $root, int $now): void
    {
        $this->assertRootCertificate($root->certificateDer, $now);
        $key = $root->privateKey($this->protector);
        unset($key);
    }

    public function assertRootCertificate(string $der, int $now): void
    {
        $this->certificate->assertValidAt($der, $now);
        if (!$this->certificate->isCertificateAuthority($der)) {
            throw new RuntimeException('Root certificate is not a CA');
        }
        $extensions = $this->certificate->extensions($der);
        $parsed = openssl_x509_parse(Certificate::derToPem($der));
        if (!is_array($parsed) || !($extensions['2.5.29.19']['critical'] ?? false)
            || !($extensions['2.5.29.15']['critical'] ?? false)
            || !str_contains($parsed['extensions']['keyUsage'] ?? '', 'Certificate Sign')
            || !str_contains($parsed['extensions']['keyUsage'] ?? '', 'CRL Sign')) {
            throw new RuntimeException('Root certificate profile is invalid');
        }
        $publicKey = openssl_pkey_get_public(Certificate::derToPem($der));
        if (!$publicKey instanceof OpenSSLAsymmetricKey
            || !$this->isRsa3072($publicKey)
            || openssl_x509_verify(Certificate::derToPem($der), $publicKey) !== 1) {
            throw new RuntimeException('Root certificate is not self-signed');
        }
    }

    private function isRsa3072(OpenSSLAsymmetricKey $key): bool
    {
        $details = openssl_pkey_get_details($key);
        return is_array($details) && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] === 3072;
    }

    private function assertTsa(StoredIdentity $tsa, string $rootDer, int $now): OpenSSLAsymmetricKey
    {
        $der = $tsa->certificateDer;
        $this->certificate->assertValidAt($der, $now);
        if ($this->certificate->isCertificateAuthority($der)) {
            throw new RuntimeException('TSA certificate is a CA');
        }
        $extensions = $this->certificate->extensions($der);
        if (!($extensions['2.5.29.19']['critical'] ?? false)
            || !($extensions['2.5.29.15']['critical'] ?? false)) {
            throw new RuntimeException('TSA certificate profile is invalid');
        }
        [$purposes, $critical] = $this->certificate->extendedKeyUsageWithCriticality($der);
        if ($purposes !== [self::TSA_EKU] || !$critical) {
            throw new RuntimeException('TSA certificate EKU is invalid');
        }
        $this->certificate->assertUsableForSigning($der);
        $tsaFields = $this->certificate->fields($der);
        $rootFields = $this->certificate->fields($rootDer);
        $rootPublic = openssl_pkey_get_public(Certificate::derToPem($rootDer));
        $tsaPublic = openssl_pkey_get_public(Certificate::derToPem($der));
        if (!$rootPublic instanceof OpenSSLAsymmetricKey || !$tsaPublic instanceof OpenSSLAsymmetricKey
            || !$this->isRsa3072($tsaPublic) || $tsaFields['issuer'] !== $rootFields['subject']
            || openssl_x509_verify(Certificate::derToPem($der), $rootPublic) !== 1) {
            throw new RuntimeException('TSA certificate is not issued by its captured root');
        }
        return $tsa->privateKey($this->protector);
    }
}
