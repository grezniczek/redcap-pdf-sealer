<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Cms\Oid;
use Com\Tecnick\Pdf\Sign\Signer;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;

require __DIR__ . '/pdf_seal_bb.php';

$tsa = $issuer->createTsa('PDF Seal Test', $root);
$internal = new InternalTimestampProvider(
    new InternalTsaService(TsaPolicy::DEFAULT_OID),
    new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]),
);
$provider = new class($internal) implements TimestampProvider {
    public string $request = '';
    public string $response = '';

    public function __construct(private readonly InternalTimestampProvider $internal) {}

    public function policyOid(): string
    {
        return $this->internal->policyOid();
    }

    public function respond(string $requestDer, int $now): string
    {
        $this->request = $requestDer;
        return $this->response = $this->internal->respond($requestDer, $now);
    }
};

function cmsSignatureBytes(string $cms, Asn1 $asn1, Certificate $certificate): string
{
    $signedData = $certificate->signedDataContent($cms);
    $offset = 0;
    for ($i = 0; $i < 3; ++$i) {
        $asn1->readTlv($signedData, $offset); // version, digest algorithms, content info
    }
    $certificates = $asn1->readTlv($signedData, $offset);
    checkSeal($certificates['tag'] === 0xA0, 'CMS certificate set is missing');
    $signerInfos = $asn1->readTlv($signedData, $offset);
    checkSeal($signerInfos['tag'] === 0x31, 'CMS signer set is missing');
    $inner = 0;
    $signerInfo = $asn1->readTlv($signerInfos['value'], $inner);
    checkSeal($signerInfo['tag'] === 0x30 && $inner === strlen($signerInfos['value']),
        'Expected one CMS signer');
    $inner = 0;
    for ($i = 0; $i < 5; ++$i) {
        $asn1->readTlv($signerInfo['value'], $inner); // version through signature algorithm
    }
    $signature = $asn1->readTlv($signerInfo['value'], $inner);
    checkSeal($signature['tag'] === 0x04, 'CMS signature bytes are missing');
    return $signature['value'];
}

function requestedImprint(string $request, Asn1 $asn1): string
{
    $root = $asn1->readSingleElement($request, 0x30, 'TimeStampReq');
    $offset = 0;
    $asn1->readTlv($root['value'], $offset); // version
    $imprint = $asn1->readTlv($root['value'], $offset);
    checkSeal($imprint['tag'] === 0x30, 'Timestamp request imprint is missing');
    $offset = 0;
    $asn1->readTlv($imprint['value'], $offset); // hash algorithm
    $digest = $asn1->readTlv($imprint['value'], $offset);
    checkSeal($digest['tag'] === 0x04, 'Timestamp request digest is missing');
    return $digest['value'];
}

$asn1 = new Asn1();
$certificate = new Certificate($asn1);
foreach ($cases as $source) {
    $now = time();
    $result = $builder->sealTimestamped(
        $source, $project->certificateDer, $project->privateKey(), [$root->certificateDer], $now, $provider,
    );
    checkSeal($result->profile === 'pades-b-t', 'Timestamped seal reported the wrong profile');
    $cms = verifySeal($source, $result->pdf, $rootPem);
    $tokens = (new Signer())->signatureTimestampTokens($cms);
    checkSeal(count($tokens) === 1, 'B-T CMS is missing its signature timestamp');
    $offset = 0;
    [$type, $content] = $certificate->encapsulatedContent($certificate->signedDataContent($tokens[0]), $offset);
    checkSeal($type === $asn1->encodeObjectIdentifier(Oid::TST_INFO), 'Timestamp token content type is wrong');
    $info = $asn1->readSingleElement($content, 0x30, 'TSTInfo');
    $fields = [];
    $offset = 0;
    while ($offset < strlen($info['value'])) {
        $fields[] = $asn1->readTlv($info['value'], $offset);
    }
    checkSeal($fields[3]['tag'] === 0x02 && $result->timestampSerialHex === strtoupper(bin2hex($fields[3]['value'])),
        'Timestamp serial was not reported');
    checkSeal($fields[4]['tag'] === 0x18 && $result->timestampTime !== null
        && abs($result->timestampTime - $now) <= 2
        && $fields[4]['value'] === gmdate('YmdHis', $result->timestampTime) . 'Z',
        'Timestamp time was not reported');
    checkSeal($provider->request !== '' && $provider->response !== '', 'Internal TSA was not called');
    checkSeal(requestedImprint($provider->request, $asn1) === hash('sha256', cmsSignatureBytes($cms, $asn1, $certificate), true),
        'Timestamp request was not over the CMS signature bytes');

    $files = [];
    try {
        foreach (['request' => $provider->request, 'response' => $provider->response, 'root' => $rootPem] as $name => $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_bt_' . $name . '_');
            checkSeal(is_string($path) && file_put_contents($path, $bytes) === strlen($bytes), 'Could not write timestamp test file');
            $files[$name] = $path;
        }
        [$status, $output] = runSealCommand([
            'openssl', 'ts', '-verify', '-in', $files['response'], '-queryfile', $files['request'],
            '-CAfile', $files['root'],
        ]);
        checkSeal($status === 0, 'OpenSSL rejected the timestamp response: ' . $output);
    } finally {
        foreach ($files as $path) {
            unlink($path);
        }
    }
}

$failingProvider = new class implements TimestampProvider {
    public function policyOid(): string
    {
        return TsaPolicy::DEFAULT_OID;
    }

    public function respond(string $requestDer, int $now): string
    {
        throw new RuntimeException('Simulated TSA failure');
    }
};
try {
    $builder->sealTimestamped($cases[0], $project->certificateDer, $project->privateKey(),
        [$root->certificateDer], time(), $failingProvider);
    throw new RuntimeException('B-T sealing silently succeeded after TSA failure');
} catch (RuntimeException $expected) {
    checkSeal($expected->getMessage() === 'Simulated TSA failure', 'Unexpected B-T failure: ' . $expected->getMessage());
}
$fallback = $builder->seal($cases[0], $project->certificateDer, $project->privateKey(), [$root->certificateDer], time());
$fallbackCms = verifySeal($cases[0], $fallback, $rootPem);
checkSeal((new Signer())->signatureTimestampTokens($fallbackCms) === [], 'B-B fallback was mislabeled with a timestamp');

echo 'PAdES B-T signature timestamps and independent RFC 3161 checks passed (', count($cases), " PDFs).\n";
