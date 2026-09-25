<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Timestamp\Client;
use Com\Tecnick\Pdf\Sign\Timestamp\Config;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\GeneratedIdentity;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;

require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readCertificate(GeneratedIdentity $identity): array
{
    $pem = Certificate::derToPem($identity->certificateDer);
    $details = openssl_x509_parse($pem);
    check(is_array($details), 'Certificate does not parse');
    check(openssl_x509_check_private_key($pem, $identity->privateKey()), 'Certificate/key mismatch');
    $key = openssl_pkey_get_details($identity->privateKey());
    check(is_array($key) && $key['type'] === OPENSSL_KEYTYPE_RSA && $key['bits'] === 3072, 'Wrong key algorithm or size');
    check(strlen($details['serialNumberHex']) >= 30, 'Certificate serial is too short');
    return $details;
}

$issuer = new CertificateIssuer();
$root = $issuer->createRoot('Test Institution');
$tsa = $issuer->createTsa('Test Institution', $root);
$uuid = $issuer->newProjectUuid();
$project = $issuer->createProject('Test Institution', $uuid, $root);
$secondProject = $issuer->createProject('Test Institution', $issuer->newProjectUuid(), $root);

$rootInfo = readCertificate($root);
$tsaInfo = readCertificate($tsa);
$projectInfo = readCertificate($project);
$secondInfo = readCertificate($secondProject);
check($rootInfo['subject']['O'] === 'Test Institution' && $rootInfo['subject']['CN'] === 'REDCap PDF Seal Root CA', 'Wrong root subject');
check($rootInfo['extensions']['basicConstraints'] === 'CA:TRUE', 'Root is not a CA');
check(str_contains($rootInfo['extensions']['keyUsage'], 'Certificate Sign'), 'Root cannot sign certificates');
check($tsaInfo['extensions']['basicConstraints'] === 'CA:FALSE', 'TSA is a CA');
check($projectInfo['extensions']['basicConstraints'] === 'CA:FALSE', 'Project identity is a CA');
check($projectInfo['subject']['CN'] === 'REDCap Project ' . $uuid, 'Project subject must use stable UUID');
check($rootInfo['serialNumberHex'] !== $tsaInfo['serialNumberHex']
    && $tsaInfo['serialNumberHex'] !== $projectInfo['serialNumberHex'], 'Certificate serial collision');
check($projectInfo['subject']['CN'] !== $secondInfo['subject']['CN'], 'Projects share a pseudonymous identity');
check($project->privateKeyPem() !== $secondProject->privateKeyPem()
    && $root->privateKeyPem() !== $tsa->privateKeyPem(), 'Distinct identities share a key');

$rootPublic = openssl_pkey_get_public(Certificate::derToPem($root->certificateDer));
check($rootPublic !== false, 'Cannot load root public key');
foreach ([$root, $tsa, $project, $secondProject] as $identity) {
    check(openssl_x509_verify(Certificate::derToPem($identity->certificateDer), $rootPublic) === 1, 'Certificate not signed by root');
}

$reader = new Certificate();
$rootExtensions = $reader->extensions($root->certificateDer);
$tsaExtensions = $reader->extensions($tsa->certificateDer);
$projectExtensions = $reader->extensions($project->certificateDer);
foreach ([$rootExtensions, $tsaExtensions, $projectExtensions] as $extensions) {
    check($extensions['2.5.29.19']['critical'] && $extensions['2.5.29.15']['critical'], 'Basic constraints or key usage is not critical');
}
[$tsaPurposes, $tsaCritical] = $reader->extendedKeyUsageWithCriticality($tsa->certificateDer);
check($tsaPurposes === ['1.3.6.1.5.5.7.3.8'] && $tsaCritical, 'Wrong TSA EKU');
[$projectPurposes, $projectCritical] = $reader->extendedKeyUsageWithCriticality($project->certificateDer);
check($projectPurposes === ['1.3.6.1.5.5.7.3.36'] && !$projectCritical, 'Wrong project EKU');
check($rootInfo['validTo_time_t'] - $rootInfo['validFrom_time_t'] >= 3649 * 86400, 'Root lifetime too short');
check($projectInfo['validTo_time_t'] - $projectInfo['validFrom_time_t'] >= 729 * 86400, 'Project lifetime too short');

$client = new Client(new Config('http://localhost.test/tsa', policyOid: '1.3.6.1.4.1.55555.3161.1'));
$request = $client->buildRequest('document-signature');
$now = time();
$response = (new InternalTsaService('1.3.6.1.4.1.55555.3161.1'))->respond(
    $request->der,
    new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]),
    $now,
);
$token = $client->parseResponse($response, $request, $now);
check(count($client->tokenCertificates($token)) === 2, 'TSA chain not embedded');

$directory = sys_get_temp_dir() . '/pdf-sealer-pki-' . bin2hex(random_bytes(8));
check(mkdir($directory, 0700), 'Cannot create PKI verification directory');
try {
    file_put_contents($directory . '/request.tsq', $request->der);
    file_put_contents($directory . '/response.tsr', $response);
    file_put_contents($directory . '/root.pem', Certificate::derToPem($root->certificateDer));
    $process = proc_open([
        'openssl', 'ts', '-verify', '-in', $directory . '/response.tsr',
        '-queryfile', $directory . '/request.tsq', '-CAfile', $directory . '/root.pem',
    ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Cannot launch OpenSSL');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'OpenSSL rejected issued TSA chain: ' . $output);
} finally {
    foreach (['request.tsq', 'response.tsr', 'root.pem'] as $file) {
        @unlink($directory . '/' . $file);
    }
    @rmdir($directory);
}

foreach (['', "Bad\nName"] as $organization) {
    try {
        $issuer->createRoot($organization);
        throw new RuntimeException('Invalid organization accepted');
    } catch (RuntimeException $e) {
        check($e->getMessage() === 'Organization must be 1-128 printable characters', 'Unexpected organization error');
    }
}
foreach ([$tsa, new GeneratedIdentity($root->certificateDer, $tsa->privateKeyPem())] as $badRoot) {
    try {
        $issuer->createTsa('Test Institution', $badRoot);
        throw new RuntimeException('Invalid root accepted');
    } catch (RuntimeException $e) {
        check($e->getMessage() === 'Root identity is not a matching self-signed CA', 'Unexpected invalid-root error');
    }
}
try {
    $issuer->createProject('Test Institution', 'not-a-uuid', $root);
    throw new RuntimeException('Invalid project UUID accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Invalid project seal UUID', 'Unexpected UUID error');
}

echo "PKI primitives: certificate profiles, distinct keys, root chain, and TSA use passed.\n";
