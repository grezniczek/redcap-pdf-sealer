<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateSerialAllocator;

require dirname(__DIR__) . '/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

final class FakeFramework
{
    public array $logs = [];
    public array $paths = [];
    public mixed $id = 1;
    public bool $failLog = false;

    public function log(string $message, array $parameters): mixed
    {
        if ($this->failLog) { throw new RuntimeException('Log unavailable'); }
        $this->logs[] = ['message' => $message] + $parameters;
        return $this->id;
    }

    public function createTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_serial_test_');
        check(is_string($path), 'Could not create temporary configuration');
        $this->paths[] = $path;
        return $path;
    }
}

$framework = new FakeFramework();
$allocator = new CertificateSerialAllocator($framework);
$maximum = PHP_OS_FAMILY === 'Windows' ? 2147483647 : PHP_INT_MAX;
foreach ([1, '42', $maximum, (string) $maximum] as $valid) {
    $framework->id = $valid;
    check($allocator->reserve('root', null) === (int) $valid, 'Valid reservation rejected');
}
foreach ([0, -1, false, null, 1.0, '01', '1.0', '1e3', ' 1', "1\n", '18446744073709551616', $maximum === 2147483647 ? '2147483648' : '9223372036854775808', (string) $maximum . '0'] as $invalid) {
    $framework->id = $invalid;
    try {
        $allocator->reserve('root', null);
        throw new LogicException('Invalid log ID accepted');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'Certificate serial reservation failed'), 'Unexpected allocation error');
    }
}

$framework = new FakeFramework();
$framework->id = $maximum;
$issuer = CertificateIssuer::forFramework($framework);
$root = $issuer->createRoot('Serial Test');
$info = openssl_x509_parse(Certificate::derToPem($root->certificateDer));
check(is_array($info), 'Certificate did not parse');
if (PHP_VERSION_ID < 80400) {
    check($info['serialNumber'] === (string) $maximum, 'Integer maximum truncated by OpenSSL');
    check($framework->logs === [[
        'message' => CertificateSerialAllocator::MESSAGE,
        'project_id' => null, 'record' => '', 'identity_role' => 'root',
        'issuer_sha256' => '', 'purpose' => 'issuance',
    ]], 'Reservation leaked context or failed to identify root issuance');
} else {
    check($framework->logs === [], 'PHP 8.4+ unnecessarily reserved an integer serial');
    check(strlen($info['serialNumberHex']) === 32 && hexdec($info['serialNumberHex'][0]) >= 8,
        'Random serial is not outside the integer range');
}

$framework->id = '1234';
$project = CertificateIssuer::forFramework($framework, 'diagnostic')->createProject('Serial Test', $issuer->newProjectUuid(), $root);
if (PHP_VERSION_ID < 80400) {
    $reservation = end($framework->logs);
    check($reservation['identity_role'] === 'project' && $reservation['purpose'] === 'diagnostic'
        && $reservation['issuer_sha256'] === hash('sha256', $root->certificateDer),
        'Reservation not linked to issuing root and diagnostic purpose');
    $info = openssl_x509_parse(Certificate::derToPem($project->certificateDer));
    check($info['serialNumber'] === '1234', 'Project certificate did not use reservation ID');
}

$framework->failLog = true;
$pathsBefore = $framework->paths;
if (PHP_VERSION_ID < 80400) {
    try {
        $issuer->createRoot('Serial Test');
        throw new LogicException('Issuance ignored a reservation failure');
    } catch (RuntimeException $e) {
        check($e->getMessage() === 'Log unavailable', 'Unexpected signing error');
    }
    check($framework->paths === $pathsBefore, 'Signing began before allocation succeeded');
} else {
    $issuer->createTsa('Serial Test', $root);
    check($framework->logs === [], 'Random serial issuance depended on integer allocator');
}
foreach ($framework->paths as $path) { check(!file_exists($path), 'OpenSSL configuration was not removed'); }

echo 'Certificate serials: allocation bounds, scope, runtime branch, actual OpenSSL serials and failure behavior passed on PHP ' . PHP_VERSION . ".\n";
