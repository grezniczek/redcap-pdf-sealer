<?php

declare(strict_types=1);
namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;

/** Offline validation against the exact CC-registered (or identity-pinned) CA chain. */
final class ExternalCertificateValidator
{
    public function __construct(private readonly object $framework) {}

    public function parseUpload(string $pem): string
    {
        if (strlen($pem) > 65536 || preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----\s*\z/', $pem, $m) !== 1) {
            throw new RuntimeException('Upload one public PEM signing certificate');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        $certificate = $der === false ? false : @openssl_x509_read(Certificate::derToPem($der));
        if ($certificate === false || !openssl_x509_export($certificate, $canonical)
            || Certificate::pemToDer($canonical) !== $der) { throw new RuntimeException('Invalid signing certificate'); }
        return $der;
    }

    /** @param list<string> $chain Issuing CA first, root last. @return array Public certificate details. */
    public function validate(string $der, array $chain): array
    {
        if ($chain === [] || count($chain) > 8) { throw new RuntimeException('Missing external issuer chain'); }
        $pems = array_map([Certificate::class, 'derToPem'], $chain);
        (new CaChainValidator($this->framework))->validate(implode('', $pems));
        $certificate = new Certificate();
        foreach ($chain as $ca) {
            $caEku = $certificate->extendedKeyUsage($ca);
            if ($caEku !== null && !in_array('1.3.6.1.5.5.7.3.36', $caEku, true) && !in_array('2.5.29.37.0', $caEku, true)) {
                throw new RuntimeException('CA chain restricts document signing');
            }
        }
        $certificate->assertValidAt($der, time());
        $certificate->assertUsableForSigning($der);
        $eku = $certificate->extendedKeyUsage($der);
        $pem = Certificate::derToPem($der);
        $parsed = openssl_x509_parse($pem);
        $public = openssl_pkey_get_public($pem);
        $key = $public === false ? false : openssl_pkey_get_details($public);
        $issuer = openssl_x509_parse($pems[0]);
        if (!is_array($parsed) || !is_array($key) || $key['type'] !== OPENSSL_KEYTYPE_RSA || $key['bits'] !== 3072
            || $certificate->isCertificateAuthority($der)
            || ($eku !== null && !in_array('1.3.6.1.5.5.7.3.36', $eku, true))
            || ($parsed['issuer'] ?? null) != ($issuer['subject'] ?? null)
            || openssl_x509_verify($pem, openssl_pkey_get_public($pems[0])) !== 1) {
            throw new RuntimeException('External signing certificate profile or issuer mismatch');
        }
        $paths = [];
        try {
            $paths[] = $rootFile = $this->framework->createTempFile();
            $paths[] = $intermediateFile = $this->framework->createTempFile();
            $root = $pems[count($pems)-1];
            $intermediates = implode('', array_slice($pems,0,-1));
            if (file_put_contents($rootFile,$root) !== strlen($root)
                || file_put_contents($intermediateFile,$intermediates) !== strlen($intermediates)
                || openssl_x509_checkpurpose($pem, X509_PURPOSE_ANY, [$rootFile], $intermediates === '' ? null : $intermediateFile) !== true) {
                throw new RuntimeException('External signing certificate path validation failed');
            }
        } finally { foreach ($paths as $path) { if (is_string($path)) @unlink($path); } }
        return ['subject' => $parsed['name'], 'issuer' => $issuer['name'], 'fingerprint' => hash('sha256',$der),
            'valid_from' => $parsed['validFrom_time_t'], 'valid_until' => $parsed['validTo_time_t'],
            'serial' => strtoupper($parsed['serialNumberHex']), 'chain_length' => count($chain)];
    }
}
