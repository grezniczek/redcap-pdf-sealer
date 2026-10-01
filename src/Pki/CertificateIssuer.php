<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\{Asn1, Certificate};
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/** Issues the three deliberately narrow v1 RSA certificate profiles. */
final class CertificateIssuer
{
    private const KEY_BITS = 3072;
    private const ROOT_DAYS = 3650;
    public const LEAF_DAYS = 730;

    private const OPENSSL_CONFIG = <<<'CONFIG'
[req]
distinguished_name = subject
prompt = no

[subject]
CN = REDCap PDF Sealer

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
    private Closure $reserveSerial;

    /** @param callable(string,?string):int $reserveSerial Used only on PHP 8.2/8.3. */
    public function __construct(callable $createTempFile, callable $reserveSerial, private readonly ?string $surveyUrl = null)
    {
        $this->createTempFile = Closure::fromCallable($createTempFile);
        $this->reserveSerial = Closure::fromCallable($reserveSerial);
    }

    public static function forFramework(object $framework, string $purpose = 'issuance'): self
    {
        return new self([$framework, 'createTempFile'], [new CertificateSerialAllocator($framework, $purpose), 'reserve'],
            defined('APP_PATH_SURVEY_FULL') ? APP_PATH_SURVEY_FULL : null);
    }

    public function createRoot(string $organization): GeneratedIdentity
    {
        $this->assertOrganization($organization);
        return $this->issue(
            ['O' => $organization, 'CN' => 'REDCap PDF Sealer Root CA'],
            'root_ext',
            self::ROOT_DAYS,
        );
    }

    /** Routine renewal preserves exact DER names/profile and the existing key, including after expiry. */
    public function renewRoot(GeneratedIdentity $root): GeneratedIdentity
    {
        $certificate = new Certificate();
        $before = $certificate->fields($root->certificateDer);
        $parsed = openssl_x509_parse(Certificate::derToPem($root->certificateDer));
        $organization = $parsed['subject']['O'] ?? null;
        if (!is_string($organization) || $before['not_before'] > time()) {
            throw new RuntimeException('Root renewal requires a previously valid built-in identity');
        }
        $this->assertOrganization($organization);
        $this->assertRoot($organization, $root, $before['not_before']);
        if ($before['issuer'] !== $before['subject']) { throw new RuntimeException('Root names are not self-issued'); }

        $asn1 = new Asn1();
        $outer = $asn1->readSingleElement($root->certificateDer, 0x30, 'root certificate');
        $offset = 0;
        $tbs = $asn1->readTlv($outer['value'], $offset);
        $algorithm = $asn1->readTlv($outer['value'], $offset);
        $expectedAlgorithm = $asn1->encodeSequence($asn1->encodeObjectIdentifier('1.2.840.113549.1.1.11') . $asn1->encodeNull());
        if ($algorithm['raw'] !== $expectedAlgorithm) { throw new RuntimeException('Unsupported root signature profile'); }
        $parts = []; $offset = 0;
        while ($offset < strlen($tbs['value'])) { $parts[] = $asn1->readTlv($tbs['value'], $offset); }
        if (array_column($parts, 'tag') !== [0xA0, 0x02, 0x30, 0x30, 0x30, 0x30, 0x30, 0xA3]) {
            throw new RuntimeException('Unsupported root certificate profile');
        }
        [$serial, $serialHex] = $this->allocateSerial('root', null);
        $hex = $serialHex ?? dechex($serial);
        $parts[1]['raw'] = $asn1->encodeIntegerBytes(hex2bin(strlen($hex) % 2 === 0 ? $hex : '0' . $hex));
        $now = time();
        $expires = $now + self::ROOT_DAYS * 86400;
        if ($expires <= $before['not_after']) { throw new RuntimeException('Root renewal would not extend validity'); }
        $encodeTime = static function (int $time) use ($asn1): string {
            $generalized = (int) gmdate('Y', $time) >= 2050;
            $value = gmdate($generalized ? 'YmdHis' : 'ymdHis', $time) . 'Z';
            return chr($generalized ? 0x18 : 0x17) . $asn1->encodeLength(strlen($value)) . $value;
        };
        $parts[4]['raw'] = $asn1->encodeSequence($encodeTime($now) . $encodeTime($expires));
        $renewedTbs = $asn1->encodeSequence(implode('', array_column($parts, 'raw')));
        if (!openssl_sign($renewedTbs, $signature, $root->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign renewed root');
        }
        $der = $asn1->encodeSequence($renewedTbs . $algorithm['raw']
            . "\x03" . $asn1->encodeLength(strlen($signature) + 1) . "\x00" . $signature);
        $after = $certificate->fields($der);
        foreach (['issuer', 'subject', 'public_key'] as $field) {
            if ($before[$field] !== $after[$field]) { throw new RuntimeException('Root renewal changed its trust profile'); }
        }
        if ($certificate->extensions($root->certificateDer) !== $certificate->extensions($der)) {
            throw new RuntimeException('Root renewal changed its extensions');
        }
        $details = openssl_x509_parse(Certificate::derToPem($der));
        if (!is_array($details) || strcasecmp(ltrim($details['serialNumberHex'] ?? '', '0'), ltrim($hex, '0')) !== 0) {
            throw new RuntimeException('Renewed root serial does not match its allocation');
        }
        $renewed = new GeneratedIdentity($der, $root->privateKeyPem());
        $this->assertRoot($organization, $renewed);
        return $renewed;
    }

    public function createTsa(string $organization, GeneratedIdentity $root): GeneratedIdentity
    {
        $this->assertOrganization($organization);
        $this->assertRoot($organization, $root);
        return $this->issue(
            ['O' => $organization, 'OU' => 'REDCap PDF Sealer', 'CN' => 'REDCap PDF Sealer Timestamp Authority'],
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
            ['O' => $organization, 'OU' => 'REDCap PDF Sealer', 'CN' => 'REDCap Project ' . $projectUuid],
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

    private function assertRoot(string $organization, GeneratedIdentity $root, ?int $now = null): void
    {
        $pem = Certificate::derToPem($root->certificateDer);
        $certificate = openssl_x509_read($pem);
        $details = $certificate === false ? false : openssl_x509_parse($certificate);
        $publicKey = $certificate === false ? false : openssl_pkey_get_public($certificate);
        $keyDetails = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        $now ??= time();
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

    /** @return array{int, ?string} Shared allocation for OpenSSL issuance and exact-profile root renewal. */
    private function allocateSerial(string $role, ?GeneratedIdentity $issuer): array
    {
        $serial = 0;
        $serialHex = null;
        if (PHP_VERSION_ID >= 80400) {
            $bytes = random_bytes(16);
            // A positive 128-bit value, disjoint from all integer serials. OpenSSL adds
            // the DER sign octet. The remaining 127 bits are cryptographically random.
            $bytes[0] = chr(ord($bytes[0]) | 0x80);
            $serialHex = bin2hex($bytes);
        } else {
            $serial = ($this->reserveSerial)($role,
                $issuer === null ? null : hash('sha256', $issuer->certificateDer));
            if (!is_int($serial) || $serial <= 0 || (PHP_OS_FAMILY === 'Windows' && $serial > 2147483647)) {
                throw new RuntimeException('Invalid reserved certificate serial');
            }
        }
        return [$serial, $serialHex];
    }

    /** OpenSSL accepts whole days; recompute immediately before signing after key/CSR generation. */
    private function leafDays(int $issuerExpires): int
    {
        $days = min(self::LEAF_DAYS, intdiv($issuerExpires - time(), 86400));
        if ($days < 1) { throw new RuntimeException('Issuer has less than one full day of validity remaining'); }
        return $days;
    }

    /** @param array<string,string> $subject */
    private function issue(array $subject, string $extension, int $days, ?GeneratedIdentity $issuer = null): GeneratedIdentity
    {
        $issuerExpires = $issuer === null ? null : (new Certificate())->fields($issuer->certificateDer)['not_after'];
        if ($issuerExpires !== null) { $days = $this->leafDays($issuerExpires); }
        [$serial, $serialHex] = $this->allocateSerial(substr($extension, 0, -4), $issuer);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => self::KEY_BITS]);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to generate RSA identity key');
        }
        $configPath = ($this->createTempFile)();
        if (!is_string($configPath) || !is_file($configPath)) {
            throw new RuntimeException('Unable to create temporary OpenSSL configuration');
        }
        try {
            $config = self::OPENSSL_CONFIG;
            if ($issuer !== null && $this->surveyUrl !== null) {
                $url = CrlIssuer::url($this->surveyUrl, $issuer->certificateDer);
                // Both built-in leaf profiles carry the same complete-CRL distribution point.
                $config = str_replace(['[tsa_ext]', '[project_ext]'],
                    ["[tsa_ext]\ncrlDistributionPoints = URI:$url", "[project_ext]\ncrlDistributionPoints = URI:$url"], $config);
            }
            if (file_put_contents($configPath, $config) === false) {
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
            $arguments = [
                $csr,
                $issuerCertificate,
                $issuer?->privateKey() ?? $key,
                $issuerExpires === null ? $days : $this->leafDays($issuerExpires),
                $options + ['x509_extensions' => $extension],
                $serial,
            ];
            if ($serialHex !== null) {
                $arguments[] = $serialHex;
            }
            $certificate = openssl_csr_sign(...$arguments);
            if (!$certificate instanceof OpenSSLCertificate || !openssl_x509_export($certificate, $certificatePem)
                || !openssl_pkey_export($key, $privateKeyPem)) {
                throw new RuntimeException('Unable to issue or export identity certificate');
            }
            $details = openssl_x509_parse($certificate);
            $expectedHex = $serialHex ?? dechex($serial);
            if (!is_array($details)
                || strcasecmp(ltrim($details['serialNumberHex'] ?? '', '0'), ltrim($expectedHex, '0')) !== 0) {
                throw new RuntimeException('Issued certificate serial does not match its allocation');
            }
            if ($issuerExpires !== null && (!is_int($details['validTo_time_t'] ?? null)
                || $details['validTo_time_t'] > $issuerExpires)) {
                throw new RuntimeException('Issued certificate exceeds issuer validity');
            }
            return new GeneratedIdentity(Certificate::pemToDer($certificatePem), $privateKeyPem);
        } finally {
            @unlink($configPath);
        }
    }
}
