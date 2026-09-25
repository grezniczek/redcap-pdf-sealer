<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
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
            $this->assertTsa($tsa, $root, $now);
        } catch (Throwable $e) {
            return new PkiHealthReport(PkiHealth::Degraded, 'TSA_IDENTITY_INVALID', $tsaId ?? null);
        }

        return new PkiHealthReport(PkiHealth::Ready);
    }

    private function assertRoot(StoredIdentity $root, int $now): void
    {
        $der = $root->certificateDer;
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
        $key = $root->privateKey($this->protector);
        unset($key);
    }

    private function isRsa3072(OpenSSLAsymmetricKey $key): bool
    {
        $details = openssl_pkey_get_details($key);
        return is_array($details) && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] === 3072;
    }

    private function assertTsa(StoredIdentity $tsa, StoredIdentity $root, int $now): void
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
        $rootFields = $this->certificate->fields($root->certificateDer);
        $rootPublic = openssl_pkey_get_public(Certificate::derToPem($root->certificateDer));
        $tsaPublic = openssl_pkey_get_public(Certificate::derToPem($der));
        if (!$rootPublic instanceof OpenSSLAsymmetricKey || !$tsaPublic instanceof OpenSSLAsymmetricKey
            || !$this->isRsa3072($tsaPublic) || $tsaFields['issuer'] !== $rootFields['subject']
            || openssl_x509_verify(Certificate::derToPem($der), $rootPublic) !== 1) {
            throw new RuntimeException('TSA certificate is not issued by active root');
        }
        $key = $tsa->privateKey($this->protector);
        unset($key);
    }
}
