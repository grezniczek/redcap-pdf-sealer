<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Cms\Oid;
use Com\Tecnick\Pdf\Sign\Timestamp\Client;
use Com\Tecnick\Pdf\Sign\Timestamp\Config;
use Com\Tecnick\Pdf\Sign\Timestamp\Request;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\PolicyOidAsn1;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

const POLICY_OID = TsaPolicy::DEFAULT_OID;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function statusCode(string $response, Asn1 $asn1): int
{
    $root = $asn1->readSingleElement($response, 0x30, 'response');
    $offset = 0;
    $status = $asn1->readTlv($root['value'], $offset);
    $inner = 0;
    $code = $asn1->readTlv($status['value'], $inner);
    return $asn1->decodeInteger($code['value']);
}

function tstInfo(string $token, Asn1 $asn1, Certificate $certificate): array
{
    $offset = 0;
    [$contentType, $content] = $certificate->encapsulatedContent($certificate->signedDataContent($token), $offset);
    check($contentType === $asn1->encodeObjectIdentifier(Oid::TST_INFO), 'Wrong token content type');
    $info = $asn1->readSingleElement($content, 0x30, 'TSTInfo');
    $fields = [];
    $offset = 0;
    while ($offset < strlen($info['value'])) {
        $fields[] = $asn1->readTlv($info['value'], $offset);
    }
    return $fields;
}

function runOpenSslVerification(string $request, string $response, string $certificatePem): void
{
    $directory = sys_get_temp_dir() . '/pdf-sealer-ts-' . bin2hex(random_bytes(8));
    check(mkdir($directory, 0700), 'Cannot create OpenSSL test directory');
    try {
        file_put_contents($directory . '/request.tsq', $request);
        file_put_contents($directory . '/response.tsr', $response);
        file_put_contents($directory . '/tsa.pem', $certificatePem);
        $command = [
            'openssl', 'ts', '-verify', '-in', $directory . '/response.tsr',
            '-queryfile', $directory . '/request.tsq', '-CAfile', $directory . '/tsa.pem',
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Cannot launch OpenSSL');
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0, 'OpenSSL timestamp verification failed: ' . $output);
    } finally {
        foreach (['request.tsq', 'response.tsr', 'tsa.pem'] as $file) {
            @unlink($directory . '/' . $file);
        }
        @rmdir($directory);
    }
}

$configFile = tempnam(sys_get_temp_dir(), 'pdf_sealer_openssl_');
check($configFile !== false, 'Cannot create OpenSSL config');
file_put_contents($configFile, <<<'CONFIG'
[req]
distinguished_name = subject
prompt = no

[subject]
O = Example Test Organization
CN = PDF Sealer Test TSA

[tsa_ext]
basicConstraints = critical,CA:false
keyUsage = critical,digitalSignature
extendedKeyUsage = critical,timeStamping
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
CONFIG);

try {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    check($key !== false, 'Cannot generate TSA key');
    $options = ['config' => $configFile, 'digest_alg' => 'sha256'];
    $csr = openssl_csr_new(['O' => 'Example Test Organization', 'CN' => 'PDF Sealer Test TSA'], $key, $options);
    check($csr !== false, 'Cannot create TSA CSR');
    $certificate = openssl_csr_sign($csr, null, $key, 2, $options + ['x509_extensions' => 'tsa_ext']);
    check($certificate !== false, 'Cannot create TSA certificate');
    check(openssl_x509_export($certificate, $certificatePem), 'Cannot export TSA certificate');
} finally {
    unlink($configFile);
}

$asn1 = new PolicyOidAsn1(POLICY_OID);
$certificateReader = new Certificate($asn1);
$identity = new TsaIdentity(Certificate::pemToDer($certificatePem), $key);
$service = new InternalTsaService(POLICY_OID);
$now = time();
$client = new Client(new Config('http://localhost.test/tsa'), $asn1);
$request = $client->buildRequest('PDF signature bytes');
$response = $service->respond($request->der, $identity, $now);
check(statusCode($response, $asn1) === 0, 'Valid request was rejected');
$token = $client->parseResponse($response, $request, $now);
check(count($client->tokenCertificates($token)) === 1, 'TSA certificate is missing');
runOpenSslVerification($request->der, $response, $certificatePem);

$fields = tstInfo($token, $asn1, $certificateReader);
check($fields[2]['tag'] === 0x30, 'Missing message imprint');
check($fields[4]['tag'] === 0x18 && $fields[4]['value'] === gmdate('YmdHis', $now) . 'Z', 'Wrong genTime');
check($fields[5]['raw'] === $request->nonce, 'Nonce was not copied');
$serials = [];
for ($i = 0; $i < 24; ++$i) {
    $other = $service->respond($request->der, $identity, $now);
    $otherToken = $client->parseResponse($other, $request, $now);
    $serial = tstInfo($otherToken, $asn1, $certificateReader)[3];
    check($serial['tag'] === 0x02 && (ord($serial['value'][0]) & 0x80) === 0, 'Serial is not positive');
    $serials[$serial['raw']] = true;
}
check(count($serials) === 24, 'Timestamp serial collision');

$noNonceClient = new Client(new Config('http://localhost.test/tsa', nonceEnabled: false), $asn1);
$noNonceRequest = $noNonceClient->buildRequest('without nonce');
$noNonceToken = $noNonceClient->parseResponse($service->respond($noNonceRequest->der, $identity, $now), $noNonceRequest, $now);
check(count(tstInfo($noNonceToken, $asn1, $certificateReader)) === 5, 'Unexpected nonce');

$defaultPolicyClient = new Client(new Config('http://localhost.test/tsa', nonceEnabled: false), $asn1);
$defaultPolicyRequest = $defaultPolicyClient->buildRequest('default policy');
$defaultPolicyToken = $defaultPolicyClient->parseResponse($service->respond($defaultPolicyRequest->der, $identity, $now), $defaultPolicyRequest, $now);
check($asn1->decodeObjectIdentifier(tstInfo($defaultPolicyToken, $asn1, $certificateReader)[1]['value']) === POLICY_OID, 'Wrong default policy');

// A caller may explicitly request the built-in policy even though Tecnick's Config cannot encode a 128-bit arc.
$requestBody = $asn1->readSingleElement($request->der, 0x30, 'TimeStampReq')['value'];
$requestOffset = 0;
$version = $asn1->readTlv($requestBody, $requestOffset);
$imprint = $asn1->readTlv($requestBody, $requestOffset);
$withPolicy = new Request(
    $asn1->encodeSequence($version['raw'] . $imprint['raw']
        . $asn1->encodeObjectIdentifier(POLICY_OID) . substr($requestBody, $requestOffset)),
    $request->imprint, $request->hashOid, $request->nonce, POLICY_OID,
);
$withPolicyResponse = $service->respond($withPolicy->der, $identity, $now);
check(statusCode($withPolicyResponse, $asn1) === 0, 'Matching reqPolicy was rejected');
$client->parseResponse($withPolicyResponse, $withPolicy, $now);

$sha1Imprint = $asn1->encodeSequence(
    $asn1->encodeSequence($asn1->encodeObjectIdentifier('1.3.14.3.2.26')) . $asn1->encodeOctetString(random_bytes(20)),
);
$sha1Request = $asn1->encodeSequence($asn1->encodeInteger(1) . $sha1Imprint);
check(statusCode($service->respond($sha1Request, $identity, $now), $asn1) === 2, 'SHA-1 request was accepted');
$wrongPolicyRequest = $asn1->encodeSequence(
    $asn1->encodeInteger(1) . $asn1->encodeSequence(
        $asn1->encodeSequence($asn1->encodeObjectIdentifier('2.16.840.1.101.3.4.2.1'))
        . $asn1->encodeOctetString(random_bytes(32)),
    ) . $asn1->encodeObjectIdentifier('1.2.3.4'),
);
$wrongPolicyResponse = $service->respond($wrongPolicyRequest, $identity, $now);
check(statusCode($wrongPolicyResponse, $asn1) === 2, 'Unknown policy was accepted');
$statusBody = $asn1->readSingleElement($wrongPolicyResponse, 0x30, 'TimeStampResp')['value'];
$status = $asn1->readSingleElement($statusBody, 0x30, 'PKIStatusInfo')['value'];
$statusOffset = 0;
$asn1->readTlv($status, $statusOffset); // rejected status code
$failure = $asn1->readTlv($status, $statusOffset);
check($failure['tag'] === 0x03 && bin2hex($failure['value']) === '000001',
    'Unsupported reqPolicy did not return unacceptedPolicy');
$otherUuidPolicy = '2.25.186172099785128831488612506224552954431';
$otherPolicyDer = (new PolicyOidAsn1($otherUuidPolicy))->encodeObjectIdentifier($otherUuidPolicy);
$otherPolicyRequest = $asn1->encodeSequence(
    $version['raw'] . $imprint['raw'] . $otherPolicyDer . substr($requestBody, $requestOffset),
);
$otherPolicyResponse = $service->respond($otherPolicyRequest, $identity, $now);
check(statusCode($otherPolicyResponse, $asn1) === 2, 'Another UUID policy was accepted');
$otherStatus = $asn1->readSingleElement(
    $asn1->readSingleElement($otherPolicyResponse, 0x30, 'TimeStampResp')['value'],
    0x30, 'PKIStatusInfo',
)['value'];
$otherOffset = 0;
$asn1->readTlv($otherStatus, $otherOffset);
$otherFailure = $asn1->readTlv($otherStatus, $otherOffset);
check($otherFailure['tag'] === 0x03 && bin2hex($otherFailure['value']) === '000001',
    'Unsupported UUID reqPolicy did not return unacceptedPolicy');
check(statusCode($service->respond("\x30\x02\x02", $identity, $now), $asn1) === 2, 'Malformed request was accepted');
$extensionsRequest = $asn1->encodeSequence($asn1->encodeInteger(1) . $asn1->encodeSequence(
    $asn1->encodeSequence($asn1->encodeObjectIdentifier('2.16.840.1.101.3.4.2.1'))
    . $asn1->encodeOctetString(random_bytes(32)),
) . $asn1->encodeContext(0, ''));
check(statusCode($service->respond($extensionsRequest, $identity, $now), $asn1) === 2, 'Extensions were accepted');

echo "Timestamp spike: Tecnick round trip, OpenSSL verification, and request cases passed.\n";
