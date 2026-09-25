<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Closure;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/** Issues the three deliberately narrow v1 RSA certificate profiles. */
final class CertificateIssuer
{
    private const KEY_BITS = 3072;
    private const ROOT_DAYS = 3650;
    private const LEAF_DAYS = 730;

    private const OPENSSL_CONFIG = <<<'CONFIG'
[req]
distinguished_name = subject
prompt = no

[subject]
CN = REDCap PDF Seal

[root_ext]
basicConstraints = critical,CA:true
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer

[tsa_ext]
basicConstraints = critical,CA:false
keyUsage = critical,digitalSignature
extendedKeyUsage = critical,timeStamping
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer

[project_ext]
basicConstraints = critical,CA:false
keyUsage = critical,digitalSignature
extendedKeyUsage = 1.3.6.1.5.5.7.3.36
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
CONFIG;

    private Closure $createTempFile;

    /** Pass the EM Framework's createTempFile() method in REDCap. */
    public function __construct(callable $createTempFile)
    {
        $this->createTempFile = Closure::fromCallable($createTempFile);
    }

    public function createRoot(string $organization): GeneratedIdentity
    {
        $this->assertOrganization($organization);
        return $this->issue(
            ['O' => $organization, 'CN' => 'REDCap PDF Seal Root CA'],
            'root_ext',
            self::ROOT_DAYS,
        );
    }

    public function createTsa(string $organization, GeneratedIdentity $root): GeneratedIdentity
    {
        $this->assertOrganization($organization);
        $this->assertRoot($organization, $root);
        return $this->issue(
            ['O' => $organization, 'OU' => 'REDCap PDF Seal', 'CN' => 'REDCap Instance Timestamp Authority'],
            'tsa_ext',
            self::LEAF_DAYS,
            $root,
        );
    }

    public function createProject(string $organization, string $projectUuid, GeneratedIdentity $root): GeneratedIdentity
    {
        $this->assertOrganization($organization);
        $this->assertRoot($organization, $root);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $projectUuid) !== 1) {
            throw new RuntimeException('Invalid project seal UUID');
        }
        return $this->issue(
            ['O' => $organization, 'OU' => 'REDCap PDF Seal', 'CN' => 'REDCap Project ' . $projectUuid],
            'project_ext',
            self::LEAF_DAYS,
            $root,
        );
    }

    public function newProjectUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function assertOrganization(string $organization): void
    {
        if ($organization !== trim($organization) || preg_match('/^[^\x00-\x1f\x7f]{1,128}$/uD', $organization) !== 1) {
            throw new RuntimeException('Organization must be 1-128 printable characters');
        }
    }

    private function assertRoot(string $organization, GeneratedIdentity $root): void
    {
        $pem = Certificate::derToPem($root->certificateDer);
        $certificate = openssl_x509_read($pem);
        $details = $certificate === false ? false : openssl_x509_parse($certificate);
        $publicKey = $certificate === false ? false : openssl_pkey_get_public($certificate);
        $keyDetails = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        $now = time();
        if (!$certificate instanceof OpenSSLCertificate || !is_array($details)
            || !$publicKey instanceof OpenSSLAsymmetricKey
            || !is_array($keyDetails) || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA
            || $keyDetails['bits'] !== self::KEY_BITS
            || ($details['validFrom_time_t'] ?? PHP_INT_MAX) > $now
            || ($details['validTo_time_t'] ?? 0) <= $now
            || ($details['subject']['O'] ?? null) !== $organization
            || ($details['extensions']['basicConstraints'] ?? null) !== 'CA:TRUE'
            || !str_contains($details['extensions']['keyUsage'] ?? '', 'Certificate Sign')
            || !openssl_x509_check_private_key($certificate, $root->privateKey())
            || openssl_x509_verify($certificate, $publicKey) !== 1) {
            throw new RuntimeException('Root identity is not a matching self-signed CA');
        }
    }

    /** @param array<string,string> $subject */
    private function issue(array $subject, string $extension, int $days, ?GeneratedIdentity $issuer = null): GeneratedIdentity
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => self::KEY_BITS]);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to generate RSA identity key');
        }
        $configPath = ($this->createTempFile)();
        if (!is_string($configPath) || !is_file($configPath)) {
            throw new RuntimeException('Unable to create temporary OpenSSL configuration');
        }
        try {
            if (file_put_contents($configPath, self::OPENSSL_CONFIG) === false) {
                throw new RuntimeException('Unable to write temporary OpenSSL configuration');
            }
            $options = ['config' => $configPath, 'digest_alg' => 'sha256'];
            $csr = openssl_csr_new($subject, $key, $options);
            if (!$csr instanceof OpenSSLCertificateSigningRequest) {
                throw new RuntimeException('Unable to create identity certificate request');
            }
            $issuerCertificate = $issuer === null ? null : openssl_x509_read(Certificate::derToPem($issuer->certificateDer));
            if ($issuer !== null && !$issuerCertificate instanceof OpenSSLCertificate) {
                throw new RuntimeException('Unable to load root certificate');
            }
            $certificate = openssl_csr_sign(
                $csr,
                $issuerCertificate,
                $issuer?->privateKey() ?? $key,
                $days,
                $options + ['x509_extensions' => $extension],
                0,
                bin2hex(random_bytes(16)),
            );
            if (!$certificate instanceof OpenSSLCertificate || !openssl_x509_export($certificate, $certificatePem)
                || !openssl_pkey_export($key, $privateKeyPem)) {
                throw new RuntimeException('Unable to issue or export identity certificate');
            }
            return new GeneratedIdentity(Certificate::pemToDer($certificatePem), $privateKeyPem);
        } finally {
            @unlink($configPath);
        }
    }
}
