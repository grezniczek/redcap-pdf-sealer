<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\SignedDataVerifier;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Signer;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config};
use DE\RUB\PDFSealerExternalModule\Timestamp\{ExternalTimestampProvider, HttpsTimestampTransport, InternalTsaService, PolicyOidAsn1, TsaIdentity, TsaPolicy};
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;

require_once __DIR__ . '/support/pdf_timestamp_checks.php';
require_once __DIR__ . '/support/CertificateSerials.php';

function rejectTimestamp(callable $work, string $label): Throwable
{
    try { $work(); } catch (Throwable $e) { return $e; }
    throw new RuntimeException('Accepted ' . $label);
}

$framework = new class {
    public array $paths = [];
    public function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_external_tsa_');
        if (!is_string($path)) { throw new RuntimeException('Temporary file failed'); }
        $this->paths[] = $path;
        return $path;
    }
};
$issuer = new CertificateIssuer([$framework, 'createTempFile'], [\PDFSealerTests\CertificateSerials::class, 'reserve']);
$root = $issuer->createRoot('Timestamp Trust Test');
$tsa = $issuer->createTsa('Timestamp Trust Test', $root);
$otherRoot = $issuer->createRoot('Independent Document Trust Test');
$project = $issuer->createProject('Independent Document Trust Test', $issuer->newProjectUuid(), $otherRoot);
$rootPem = Certificate::derToPem($root->certificateDer);
$identity = new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]);
$service = new InternalTsaService(TsaPolicy::DEFAULT_OID);
$now = time();
$transport = static fn(string $request): string => $service->respond($request, $identity, $now);
$client = new Client(new Config('https://timestamp.invalid/'), new PolicyOidAsn1(''));
$request = $client->buildRequest('synthetic signature');
$make = static fn(callable $send, string $policy = '', ?string $trust = null): ExternalTimestampProvider =>
    new ExternalTimestampProvider($framework, $trust ?? $rootPem, $send, $policy);

try {
    // A blank external policy accepts the TSA's UUID policy; it never imposes the built-in policy.
    $provider = $make($transport);
    $response = $provider->respond($request->der, $now);
    $client->parseResponse($response, $request, $now);
    $otherPolicy = '2.25.340282366920938463463374607431768211455';
    $otherService = new InternalTsaService($otherPolicy);
    $otherTransport = static fn(string $query): string => $otherService->respond($query, $identity, $now);
    $make($otherTransport)->respond($request->der, $now);
    $make($otherTransport, $otherPolicy)->respond($request->der, $now);
    rejectTimestamp(fn() => $make($transport, '1.2.3.4')->respond($request->der, $now), 'unsupported policy');
    // A responder that ignores reqPolicy and returns a valid token under another policy is rejected too.
    rejectTimestamp(fn() => $make(static fn() => $response, '1.2.3.4')->respond($request->der, $now), 'wrong response policy');
    rejectTimestamp(fn() => $make($transport, '', Certificate::derToPem($otherRoot->certificateDer))->respond($request->der, $now), 'untrusted signer');
    // The trusted root in the token cannot elevate an unrelated actual signer.
    $otherTsa = $issuer->createTsa('Independent Document Trust Test', $otherRoot);
    $untrusted = new TsaIdentity($otherTsa->certificateDer, $otherTsa->privateKey(), [$root->certificateDer, $otherRoot->certificateDer]);
    $trustError = rejectTimestamp(fn() => $make(static fn($q) => $service->respond($q, $untrusted, time()))->respond($request->der, time()), 'root injection');
    checkSeal(str_contains($trustError->getMessage(), 'configured issuing CA'), 'Root-injection test failed for another reason');
    rejectTimestamp(fn() => $provider->respond($request->der, $now + Client::CLOCK_SKEW + 1), 'stale timestamp');
    $another = $client->buildRequest('another signature');
    rejectTimestamp(fn() => $make(static fn() => $transport($another->der))->respond($request->der, $now), 'wrong imprint');
    $sameImprint = $client->buildRequest('synthetic signature');
    rejectTimestamp(fn() => $make(static fn() => $transport($sameImprint->der))->respond($request->der, $now), 'wrong nonce');
    foreach (['', '<html>error</html>', $response . 'extra', str_repeat('x', HttpsTimestampTransport::MAX_RESPONSE_BYTES + 1)] as $bad) {
        rejectTimestamp(fn() => $make(static fn() => $bad)->respond($request->der, $now), 'malformed/oversized response');
    }
    $tampered = substr($response, 0, -1) . chr(ord(substr($response, -1)) ^ 1);
    rejectTimestamp(fn() => $make(static fn() => $tampered)->respond($request->der, $now), 'tampered signature');
    rejectTimestamp(fn() => $make(static fn() => throw new RuntimeException('Transport timeout'))->respond($request->der, $now), 'timeout');

    // Accept a complete intermediate chain, including when the token carries only its leaf.
    $caConfig = $framework->createTempFile();
    file_put_contents($caConfig, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\n"
        . "basicConstraints=critical,CA:true,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n"
        . "[tsa]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\n"
        . "extendedKeyUsage=critical,timeStamping\n");
    $intermediateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $caCsr = openssl_csr_new(['commonName' => 'Intermediate Timestamp CA'], $intermediateKey, ['config' => $caConfig]);
    $intermediateCert = openssl_csr_sign($caCsr, $rootPem, $root->privateKey(), 2,
        ['config' => $caConfig, 'x509_extensions' => 'ca', 'digest_alg' => 'sha256'], 501);
    checkSeal($intermediateCert !== false && openssl_x509_export($intermediateCert, $intermediatePem), 'Intermediate fixture failed');
    $tsaKey = $tsa->privateKey();
    $tsaCsr = openssl_csr_new(['commonName' => 'External Timestamp Leaf'], $tsaKey, ['config' => $caConfig]);
    $intermediateLeaf = openssl_csr_sign($tsaCsr, $intermediatePem, $intermediateKey, 1,
        ['config' => $caConfig, 'x509_extensions' => 'tsa', 'digest_alg' => 'sha256'], 502);
    checkSeal($intermediateLeaf !== false && openssl_x509_export($intermediateLeaf, $intermediateLeafPem), 'TSA fixture failed');
    $intermediateIdentity = new TsaIdentity(Certificate::pemToDer($intermediateLeafPem), $tsaKey);
    $intermediateTransport = static fn($q) => $service->respond($q, $intermediateIdentity, time());
    $make($intermediateTransport, '', $intermediatePem . $rootPem)->respond($request->der, time());
    rejectTimestamp(fn() => $make($intermediateTransport)->respond($request->der, time()), 'missing configured intermediate');

    // Independent OpenSSL TSA produces fractional genTime, exclusive TSA EKU and ESS attributes.
    $files = [];
    foreach (['cert' => Certificate::derToPem($tsa->certificateDer), 'key' => $tsa->privateKeyPem(),
        'root' => $rootPem, 'serial' => '01', 'query' => '', 'reply' => '', 'config' => ''] as $name => $bytes) {
        $files[$name] = $framework->createTempFile();
        file_put_contents($files[$name], $bytes);
    }
    file_put_contents($files['config'], "[tsa]\ndefault_tsa=tsa_config\n[tsa_config]\n"
        . "serial={$files['serial']}\nsigner_cert={$files['cert']}\nsigner_key={$files['key']}\ncerts={$files['root']}\n"
        . "signer_digest=sha256\ndefault_policy=1.2.3.4\ndigests=sha256\naccuracy=secs:1\nclock_precision_digits=6\n"
        . "ordering=no\ntsa_name=no\ness_cert_id_chain=no\ness_cert_id_alg=sha256\n");
    $capturedRequest = $capturedResponse = '';
    $openssl = static function (string $query) use ($files, &$capturedRequest, &$capturedResponse): string {
        $capturedRequest = $query;
        file_put_contents($files['query'], $query);
        [$status, $output] = runSealCommand(['openssl', 'ts', '-reply', '-config', $files['config'],
            '-queryfile', $files['query'], '-out', $files['reply']]);
        checkSeal($status === 0, 'OpenSSL responder failed: ' . $output);
        return $capturedResponse = file_get_contents($files['reply']);
    };
    // Different document/TSA roots, full builder path, optional and explicit remote policy.
    foreach (['', '1.2.3.4'] as $policy) {
        $external = $make($openssl, $policy);
        $result = (new PdfSealBuilder())->sealTimestamped(testPdf(), $project->certificateDer,
            $project->privateKey(), [$otherRoot->certificateDer], time(), $external);
        checkSeal($result->profile === 'pades-b-t' && abs($result->timestampTime - time()) < 10, 'B-T metadata failed');
        $cms = verifySeal(testPdf(), $result->pdf, Certificate::derToPem($otherRoot->certificateDer));
        $token = (new Signer())->signatureTimestampTokens($cms)[0];
        $asn1 = new PolicyOidAsn1('');
        $reader = new Certificate($asn1);
        $offset = 0;
        [, $info] = $reader->encapsulatedContent($reader->signedDataContent($token), $offset);
        checkSeal(preg_match('/[0-9]{14}\.[0-9]+Z/', $info) === 1, 'Fixture did not exercise fractional genTime');
        file_put_contents($files['reply'], $token);
        file_put_contents($files['query'], cmsSignatureBytes($cms, $asn1, $reader));
        [$status, $output] = runSealCommand(['openssl', 'ts', '-verify', '-token_in', '-in', $files['reply'],
            '-data', $files['query'], '-CAfile', $files['root']]);
        checkSeal($status === 0, 'OpenSSL rejected embedded external timestamp: ' . $output);
    }
    // Legacy ESSCertID hashes only the certificate with SHA-1. The timestamp
    // digest and CMS signature must still use a modern algorithm.
    $lastStrongRequest = $capturedRequest;
    file_put_contents($files['config'], str_replace('ess_cert_id_alg=sha256', 'ess_cert_id_alg=sha1',
        file_get_contents($files['config'])));
    $legacy = (new PdfSealBuilder())->sealTimestamped(testPdf(), $project->certificateDer,
        $project->privateKey(), [$otherRoot->certificateDer], time(),
        new \DE\RUB\PDFSealerExternalModule\Timestamp\OrderedTimestampProvider(
            ['remote-tsa-1111111111111111'], static fn() => $make($openssl)));
    checkSeal($legacy->profile === 'pades-b-t', 'Legacy ESS certificate identifier blocked external B-T');
    $legacyCms = verifySeal(testPdf(), $legacy->pdf, Certificate::derToPem($otherRoot->certificateDer));
    $legacyToken = (new Signer())->signatureTimestampTokens($legacyCms)[0];
    rejectTimestamp(fn() => (new SignedDataVerifier(requireSigningCertificate: true))->verify($legacyToken),
        'legacy ESS with strict verification');
    checkSeal((new SignedDataVerifier(requireSigningCertificate: true, allowLegacyEssSha1: true))->verify($legacyToken)
        === $tsa->certificateDer, 'Legacy ESS exception did not bind the expected signer');
    file_put_contents($files['config'], str_replace('signer_digest=sha256', 'signer_digest=sha1',
        file_get_contents($files['config'])));
    $weakSignature = rejectTimestamp(fn() => $make($openssl)->respond($request->der, time()), 'SHA-1 CMS signature');
    checkSeal(str_contains($weakSignature->getMessage(), 'SHA-1'), 'Weak-signature test failed before digest policy');
    $capturedRequest = $lastStrongRequest;
    // CMS signed with an otherwise trusted document-signing certificate must not pass as a TSA.
    $wrongUsage = $issuer->createProject('Timestamp Trust Test', $issuer->newProjectUuid(), $root);
    file_put_contents($files['cert'], Certificate::derToPem($wrongUsage->certificateDer));
    file_put_contents($files['key'], $wrongUsage->privateKeyPem());
    file_put_contents($files['query'], $info);
    [$status, $output] = runSealCommand(['openssl', 'cms', '-sign', '-binary', '-nodetach', '-cades', '-md', 'sha256',
        '-in', $files['query'], '-signer', $files['cert'], '-inkey', $files['key'], '-certfile', $files['root'],
        '-econtent_type', '1.2.840.113549.1.9.16.1.4', '-outform', 'DER', '-out', $files['reply']]);
    checkSeal($status === 0, 'Wrong-usage CMS fixture failed: ' . $output);
    $wrongUsageResponse = $asn1->encodeSequence($asn1->encodeSequence($asn1->encodeInteger(0)) . file_get_contents($files['reply']));
    $usageError = rejectTimestamp(fn() => $make(static fn() => $wrongUsageResponse, '1.2.3.4')->respond($capturedRequest, time()), 'non-TSA signer');
    checkSeal(str_contains($usageError->getMessage(), 'not reserved for timestamping'), 'Wrong-usage test failed for another reason');
    echo "External TSA trust, rejection, independent fractional timestamp and B-T PDF checks passed.\n";
} finally {
    foreach ($framework->paths as $path) { if (is_file($path)) { unlink($path); } }
}
