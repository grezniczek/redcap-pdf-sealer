<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use RuntimeException;

/** Bounded public-only PEM chain registration; no network certificate discovery. */
final class CaChainValidator
{
    public function __construct(private readonly object $framework) {}

    /** @return list<array{der_b64:string, sha256:string}> Ordered issuer to trust anchor. */
    public function validate(string $pem): array
    {
        if (strlen($pem) > 131072 || !preg_match_all('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----/', $pem, $matches)
            || count($matches[0]) > 8 || trim(str_replace($matches[0], '', $pem)) !== '') {
            throw new RuntimeException('Upload only an ordered PEM certificate chain (1–8 certificates, at most 128 KiB)');
        }
        $chain = []; $parsed = []; $seen = [];
        foreach ($matches[1] as $encoded) {
            $der = base64_decode(preg_replace('/\s+/', '', $encoded), true);
            $fields = $der === false ? false : @openssl_x509_parse(Certificate::derToPem($der));
            if (!is_array($fields) || !str_contains($fields['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
                || !str_contains($fields['extensions']['keyUsage'] ?? '', 'Certificate Sign')
                || !is_int($fields['validFrom_time_t'] ?? null) || !is_int($fields['validTo_time_t'] ?? null)
                || $fields['validFrom_time_t'] > time() || $fields['validTo_time_t'] < time()) {
                throw new RuntimeException('Each uploaded certificate must be a currently valid CA with certificate-signing usage');
            }
            $hash = hash('sha256', $der);
            if (isset($seen[$hash])) { throw new RuntimeException('Duplicate CA certificate'); }
            $seen[$hash] = true;
            $chain[] = ['der_b64' => base64_encode($der), 'sha256' => $hash];
            $parsed[] = $fields;
        }
        $pems = array_map(static fn(array $cert): string => Certificate::derToPem(base64_decode($cert['der_b64'], true)), $chain);
        foreach ($pems as $i => $certificate) {
            // Every uploaded CA will be above a future project leaf. OpenSSL's
            // checkpurpose treats the first uploaded certificate as the target,
            // so also count it when enforcing the parent CAs' path lengths.
            if (preg_match('/pathlen:(\d+)/', $parsed[$i]['extensions']['basicConstraints'], $limit)) {
                $below = 0;
                for ($j = 0; $j < $i; $j++) {
                    if ($parsed[$j]['subject'] != $parsed[$j]['issuer']) { $below++; }
                }
                if ($below > (int) $limit[1]) { throw new RuntimeException('CA path length does not permit this issuing chain'); }
            }
            $parent = min($i + 1, count($pems) - 1);
            $key = openssl_pkey_get_public($pems[$parent]);
            if ($parsed[$i]['issuer'] != $parsed[$parent]['subject'] || $key === false || openssl_x509_verify($certificate, $key) !== 1) {
                throw new RuntimeException('Chain must be ordered from issuing CA to a matching self-signed root');
            }
        }
        $paths = [];
        try {
            $paths[] = $rootFile = $this->framework->createTempFile();
            $paths[] = $intermediatesFile = $this->framework->createTempFile();
            $root = $pems[count($pems) - 1];
            $intermediates = implode('', array_slice($pems, 1, -1));
            if (file_put_contents($rootFile, $root) !== strlen($root)
                || file_put_contents($intermediatesFile, $intermediates) !== strlen($intermediates)
                || openssl_x509_checkpurpose($pems[0], X509_PURPOSE_ANY, [$rootFile], $intermediates === '' ? null : $intermediatesFile) !== true) {
                throw new RuntimeException('OpenSSL rejected the CA chain');
            }
        } finally {
            foreach ($paths as $path) { if (is_string($path)) { @unlink($path); } }
        }
        return $chain;
    }
}
