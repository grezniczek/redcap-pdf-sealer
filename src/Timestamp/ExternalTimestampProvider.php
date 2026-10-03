<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Timestamp;

use Closure;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\Client;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\Config;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\Request;
use DE\RUB\PDFSealerExternalModule\Pki\CaChainValidator;
use DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository;
use RuntimeException;

/** External response validation; transport and CA trust are explicitly supplied by the host. */
final class ExternalTimestampProvider implements TimestampProvider
{
    private PolicyOidAsn1 $asn1;
    private Closure $transport;

    /**
     * @param string $trustedCaPem Complete ordered issuing CA to self-signed root, public PEM only.
     * @param callable(string):string $transport Normally HttpsTimestampTransport; injectable for offline tests.
     * @param string $policyOid Empty lets this trusted TSA select its policy; otherwise request and enforce it.
     */
    public function __construct(
        private readonly object $framework,
        private readonly string $trustedCaPem,
        callable $transport,
        private readonly string $policyOid = '',
        private readonly ?Closure $revocationCheck = null,
    ) {
        if (strlen($policyOid) > 256) { throw new RuntimeException('Timestamp policy OID is too long'); }
        $this->asn1 = new PolicyOidAsn1($policyOid);
        $this->transport = Closure::fromCallable($transport);
    }

    public function policyOid(): string { return $this->policyOid; }

    public function respond(string $requestDer, int $now): string
    {
        if ($this->revocationCheck !== null) { ($this->revocationCheck)(); }
        $request = $this->request($requestDer);
        // Revalidate current CA validity/path before contacting a remote service.
        $chain = (new CaChainValidator($this->framework))->validate($this->trustedCaPem);
        $response = ($this->transport)($request->der);
        if (!is_string($response) || $response === '' || strlen($response) > HttpsTimestampTransport::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Invalid timestamp response size');
        }
        $verifier = new SignedDataVerifier($this->asn1, requireSigningCertificate: true, allowLegacyEssSha1: true);
        $client = new Client(new Config('https://timestamp.invalid/'), $this->asn1, verifier: $verifier);
        // Includes status, imprint, nonce, requested policy, freshness, CMS/ESS,
        // signer KU/critical exclusive timestamp EKU and validity at genTime.
        $token = $client->parseResponse($response, $request, $now);
        $signer = $verifier->verify($token);
        $this->assertTrustedSigner($signer, $chain);
        if ($this->revocationCheck !== null) { ($this->revocationCheck)(); }
        return $response;
    }

    /** Only the SHA-256, nonce-bearing requests used by this module are supported. */
    private function request(string $der): Request
    {
        if (strlen($der) > 4096) { throw new RuntimeException('Timestamp request is too large'); }
        $asn1 = $this->asn1;
        $body = $asn1->readSingleElement($der, 0x30, 'TimeStampReq')['value'];
        $offset = 0;
        $version = $asn1->readTlv($body, $offset);
        $imprint = $asn1->readTlv($body, $offset);
        if ($version['raw'] !== $asn1->encodeInteger(1) || $imprint['tag'] !== 0x30) {
            throw new RuntimeException('Unsupported timestamp request');
        }
        $inner = 0;
        $algorithm = $asn1->readTlv($imprint['value'], $inner);
        $hash = $asn1->readTlv($imprint['value'], $inner);
        $oid = $asn1->decodeAlgorithmIdentifier($algorithm['raw'], 'timestamp imprint');
        if ($oid !== '2.16.840.1.101.3.4.2.1' || $hash['tag'] !== 0x04 || strlen($hash['value']) !== 32
            || $inner !== strlen($imprint['value'])) { throw new RuntimeException('Unsupported timestamp imprint'); }
        $field = $asn1->readTlv($body, $offset);
        $requestedPolicy = '';
        if ($field['tag'] === 0x06) {
            $requestedPolicy = $asn1->decodeObjectIdentifier($field['value']);
            if ($this->policyOid !== '' && $requestedPolicy !== $this->policyOid) {
                throw new RuntimeException('Conflicting timestamp policy');
            }
            $field = $asn1->readTlv($body, $offset);
        }
        if ($field['tag'] !== 0x02 || strlen($field['value']) < 1 || strlen($field['value']) > 20
            || (ord($field['value'][0]) & 0x80) !== 0) { throw new RuntimeException('Timestamp request requires a nonce'); }
        $asn1->assertMinimalInteger($field['value']);
        $nonce = $field['raw'];
        $certReq = $asn1->readTlv($body, $offset);
        if ($certReq['raw'] !== $asn1->encodeBoolean(true) || $offset !== strlen($body)) {
            throw new RuntimeException('Unsupported timestamp request options');
        }
        $policy = $this->policyOid !== '' ? $this->policyOid : $requestedPolicy;
        // Add reqPolicy without rebuilding the imprint/nonce. The caller's codec
        // still verifies these same bytes; this provider enforces the extra policy.
        $der = $asn1->encodeSequence($version['raw'] . $imprint['raw']
            . ($policy === '' ? '' : $asn1->encodeObjectIdentifier($policy)) . $nonce . $certReq['raw']);
        return new Request($der, $hash['value'], $oid, $nonce, $policy);
    }

    private function assertTrustedSigner(string $signer, array $chain): void
    {
        $pems = array_map(static fn(array $entry): string => Certificate::derToPem(ProviderRepository::certificateDer($entry)), $chain);
        $leaf = Certificate::derToPem($signer);
        $issuer = openssl_pkey_get_public($pems[0]);
        $fields = openssl_x509_parse($leaf);
        $issuerFields = openssl_x509_parse($pems[0]);
        if (!is_array($fields) || !is_array($issuerFields) || $fields['issuer'] != $issuerFields['subject']
            || $issuer === false || openssl_x509_verify($leaf, $issuer) !== 1) {
            throw new RuntimeException('Timestamp signer does not match the configured issuing CA');
        }
        $paths = [];
        try {
            $paths[] = $rootFile = $this->framework->createTempFile();
            $paths[] = $chainFile = $this->framework->createTempFile();
            $root = $pems[count($pems) - 1];
            $intermediates = implode('', array_slice($pems, 0, -1));
            // OpenSSL purpose 9 = X509_PURPOSE_TIMESTAMP_SIGN. PHP 8.2/8.3
            // accept its numeric value but do not expose the named constant.
            if (file_put_contents($rootFile, $root) !== strlen($root)
                || file_put_contents($chainFile, $intermediates) !== strlen($intermediates)
                || openssl_x509_checkpurpose($leaf, 9, [$rootFile], $intermediates === '' ? null : $chainFile) !== true) {
                throw new RuntimeException('Timestamp signer chain is not trusted for timestamping');
            }
        } finally {
            foreach ($paths as $path) { if (is_string($path)) { @unlink($path); } }
        }
    }
}
