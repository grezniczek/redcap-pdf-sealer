<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService;
use DE\RUB\PDFSealerExternalModule\Diagnostics\SampleSealVerifier;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require dirname(__DIR__) . '/vendor/autoload.php';

// Disposable encryption shim; the browser diagnostic exercises actual REDCap encryption.
function encrypt(string $plaintext): string|false
{
    return ($GLOBALS['failEncryption'] ?? false) ? false : base64_encode('test-encrypted:' . $plaintext);
}
function decrypt(string $ciphertext): string|false
{
    $decoded = base64_decode($ciphertext, true);
    return is_string($decoded) && str_starts_with($decoded, 'test-encrypted:')
        ? substr($decoded, strlen('test-encrypted:'))
        : false;
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FakeResult
{
    public function __construct(private array $rows) {}

    public function fetch_assoc(): ?array
    {
        return array_shift($this->rows);
    }
}

final class FakeFramework
{
    public bool $readOnly = false;
    public array $settings = [];
    public array $logs = [];

    public function getSystemSetting(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }

    public function setSystemSetting(string $key, mixed $value): void
    {
        check(!$this->readOnly, 'Diagnostic attempted a settings write');
        $this->settings[$key] = $value;
    }

    public function log(string $message, array $parameters): int
    {
        check(!$this->readOnly, 'Diagnostic attempted a log write');
        check(array_key_exists('project_id', $parameters) && $parameters['project_id'] === null,
            'PKI record was not explicitly system-scoped');
        check(($parameters['record'] ?? null) === '', 'PKI record retained a clinical record ID');
        $id = count($this->logs) + 1;
        $this->logs[] = ['log_id' => $id, 'message' => $message] + $parameters;
        return $id;
    }

    public function queryLogs(string $sql, array $parameters): FakeResult
    {
        check(str_contains($sql, 'ISNULL(project_id)'), 'PKI lookup did not restrict system scope');
        $rows = array_values(array_filter($this->logs, static function (array $row) use ($sql, $parameters): bool {
            if ($row['message'] !== $parameters[0] || $row['project_id'] !== null) {
                return false;
            }
            if (str_contains($sql, 'identity_id = ?')) {
                return $row['identity_id'] === $parameters[1];
            }
            return $row['identity_role'] === $parameters[1];
        }));
        if (str_contains($sql, 'ORDER BY log_id DESC')) {
            $rows = array_reverse($rows);
        }
        return new FakeResult(array_slice($rows, 0, str_contains($sql, 'LIMIT 2') ? 2 : 1));
    }
}

$framework = new FakeFramework();
$protector = new SecretProtector();
$settings = new PrimarySystemSettingReader($framework, [$framework, 'getSystemSetting']);
$repository = new IdentityRepository($framework, $protector, new PrimaryLogReader($framework, [$framework, 'queryLogs']), $settings);
$tempPaths = [];
$issuer = new CertificateIssuer(static function () use (&$tempPaths): string {
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_diagnostic_test_');
    check(is_string($path), 'Cannot create test OpenSSL config');
    $tempPaths[] = $path;
    return $path;
});
$service = new PkiDiagnosticService($repository, $protector, $issuer, $settings);
$run = static function () use ($framework, $service, &$tempPaths): array {
    $before = [$framework->settings, $framework->logs];
    $framework->readOnly = true;
    try { $result = $service->run(); } finally { $framework->readOnly = false; }
    check([$framework->settings, $framework->logs] === $before, 'Diagnostic changed storage');
    check(array_keys($result) === ['passed', 'checks'], 'Unexpected diagnostic response fields');
    check(array_keys($result['checks']) === ['encryption', 'root', 'tsa', 'signer', 'bb', 'timestamp', 'bt'], 'Unexpected check identifiers');
    foreach ($result['checks'] as $status) {
        check(in_array($status, ['passed', 'failed', 'skipped'], true), 'Diagnostic exposed unexpected details');
    }
    foreach ($tempPaths as $path) { check(!file_exists($path), 'Temporary configuration left behind'); }
    return $result;
};
$result = $run();
check(!$result['passed'] && $result['checks']['root'] === 'failed'
    && $result['checks']['bb'] === 'skipped', 'Uninitialized PKI passed');

$framework->settings['organization'] = 'Test Institution';
$root = $issuer->createRoot('Test Institution');
$rootId = $repository->append('root', $root);
$repository->activate('root', $rootId);
$result = $run();
check(!$result['passed'] && $result['checks']['root'] === 'passed' && $result['checks']['tsa'] === 'failed'
    && $result['checks']['bb'] === 'passed' && $result['checks']['bt'] === 'skipped', 'Missing TSA not isolated from B-B');

$tsa = $issuer->createTsa('Test Institution', $root);
$tsaId = $repository->append('tsa', $tsa);
$repository->activate('tsa', $tsaId);
// Diagnostics test both capabilities even when production selects B-B only.
$framework->settings['timestamp_mode'] = 'none';
$framework->settings['bb_fallback'] = '1';
check($run()['passed'], 'Healthy default-policy diagnostic failed');
$framework->settings['tsa_policy_oid'] = '1.3.6.1.4.1.55555.1';
check($run()['passed'], 'Explicit-policy diagnostic failed');
$framework->settings['tsa_policy_oid'] = 'invalid-policy';
$result = $run();
check(!$result['passed'] && $result['checks']['timestamp'] === 'failed'
    && $result['checks']['bt'] === 'skipped' && $result['checks']['bb'] === 'passed', 'Bad policy was hidden by fallback');
unset($framework->settings['tsa_policy_oid']);

$framework->logs[1]['private_key_ciphertext'] = 'redcap-v1:corrupt';
$result = $run();
check(!$result['passed'] && $result['checks']['tsa'] === 'failed' && $result['checks']['bb'] === 'passed', 'Corrupt TSA passed');
$framework->logs[0]['private_key_ciphertext'] = 'redcap-v1:corrupt';
$result = $run();
check(!$result['passed'] && $result['checks']['root'] === 'failed' && $result['checks']['signer'] === 'skipped', 'Corrupt root passed');
$framework->logs[0]['private_key_ciphertext'] = $protector->encrypt($root->privateKeyPem());
$framework->logs[1]['private_key_ciphertext'] = $protector->encrypt($tsa->privateKeyPem());
$framework->settings['organization'] = 'Wrong Institution';
$result = $run();
check(!$result['passed'] && $result['checks']['signer'] === 'failed'
    && $result['checks']['timestamp'] === 'passed' && $result['checks']['bt'] === 'skipped', 'Signer failure was not isolated');
$framework->settings['organization'] = 'Test Institution';
$GLOBALS['failEncryption'] = true;
check($run()['checks']['encryption'] === 'failed', 'Encryption failure passed');
$GLOBALS['failEncryption'] = false;

// Verification must actually reject a modified signed region and wrong profile expectations.
$signer = $issuer->createProject('Test Institution', $issuer->newProjectUuid(), $root);
$sample = PkiDiagnosticService::samplePdf();
$sealed = (new PdfSealBuilder())->seal($sample, $signer->certificateDer, $signer->privateKey(), [$root->certificateDer], time());
$verifier = new SampleSealVerifier();
$verifier->verify($sample, $sealed, $signer->certificateDer);
foreach ([
    [$sample, str_replace('/P 1 ', '/P 2 ', $sealed), $signer->certificateDer, null],
    [$sample, $sealed, $root->certificateDer, null],
    [$sample, $sealed, $signer->certificateDer, $tsa->certificateDer],
    [$sample, $sealed . "\n", $signer->certificateDer, null],
] as $case) {
    $rejected = false;
    try { $verifier->verify(...$case); } catch (Throwable) { $rejected = true; }
    check($rejected, 'Invalid sample seal passed verification');
}
// Alter the random field name without disturbing structure or byte counts.
$tampered = preg_replace_callback('/REDCapSeal([0-9a-f])/', static fn(array $m): string => 'REDCapSeal' . ($m[1] === '0' ? '1' : '0'), $sealed);
$rejected = false;
try { $verifier->verify($sample, $tampered, $signer->certificateDer); } catch (Throwable) { $rejected = true; }
check($rejected, 'CMS digest verification accepted tampering');

echo "PKI diagnostic: B-B/B-T, policies, failed/skipped checks, read-only storage, cleanup, and tamper rejection passed.\n";
