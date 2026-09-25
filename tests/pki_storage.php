<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require dirname(__DIR__) . '/vendor/autoload.php';

// This shim tests repository behavior; a live REDCap encryption check remains separate.
function encrypt(string $plaintext): string|false
{
    return base64_encode('test-encrypted:' . $plaintext);
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
    public array $settings = [];
    public array $logs = [];

    public function getSystemSetting(string $key): mixed
    {
        return $this->settings[$key] ?? null;
    }

    public function setSystemSetting(string $key, mixed $value): void
    {
        $this->settings[$key] = $value;
    }

    public function log(string $message, array $parameters): int
    {
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
$repository = new IdentityRepository($framework, $protector);
$health = new PkiHealthService($repository, $protector);
$now = time();
check($health->inspect($now)->status === PkiHealth::Uninitialized, 'Fresh PKI is not uninitialized');

$issuer = new CertificateIssuer(static function (): string {
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_storage_test_');
    check(is_string($path), 'Cannot create test OpenSSL config');
    return $path;
});
$root = $issuer->createRoot('Test Institution');
$rootId = $repository->append('root', $root);
check($health->inspect($now)->status === PkiHealth::Broken, 'Orphan root was treated as uninitialized');
$repository->activate('root', $rootId);
$now = time();
check($health->inspect($now)->status === PkiHealth::Degraded, 'Missing TSA was not degraded');

$tsa = $issuer->createTsa('Test Institution', $root);
$tsaId = $repository->append('tsa', $tsa);
$repository->activate('tsa', $tsaId);
$now = time();
check($health->inspect($now)->status === PkiHealth::Ready, 'Healthy root/TSA were not ready');
check($repository->find($rootId)?->privateKey($protector) instanceof OpenSSLAsymmetricKey,
    'Stored root key does not decrypt');
check(!str_contains(json_encode($framework->logs, JSON_THROW_ON_ERROR), 'BEGIN PRIVATE KEY'),
    'Plaintext private key was persisted');

$uuid = $issuer->newProjectUuid();
$project = $issuer->createProject('Test Institution', $uuid, $root);
$projectId = $repository->append('project', $project, $uuid);
check($repository->find($projectId)?->projectUuid === $uuid, 'Project UUID was not stored');
check($repository->find($projectId)?->privateKey($protector) instanceof OpenSSLAsymmetricKey,
    'Stored project key does not decrypt');

$framework->settings['active_root_identity_id'] = str_repeat('0', 32);
check($health->inspect($now)->status === PkiHealth::Broken, 'Missing active root was not broken');
$framework->settings['active_root_identity_id'] = $rootId;
$framework->logs[0]['private_key_ciphertext'] = 'redcap-v1:corrupt';
check($health->inspect($now)->status === PkiHealth::Broken, 'Undecryptable root key was not broken');
$framework->logs[0]['private_key_ciphertext'] = $protector->encrypt($root->privateKeyPem());
$framework->logs[0]['private_key_ciphertext'] = $protector->encrypt($tsa->privateKeyPem());
check($health->inspect($now)->status === PkiHealth::Broken, 'Mismatched root key was not broken');
$framework->logs[0]['private_key_ciphertext'] = $protector->encrypt($root->privateKeyPem());
$framework->logs[1]['private_key_ciphertext'] = 'redcap-v1:corrupt';
check($health->inspect($now)->status === PkiHealth::Degraded, 'Undecryptable TSA key was not degraded');

try {
    $repository->activate('root', $tsaId);
    throw new RuntimeException('Wrong-role activation succeeded');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Active identity must reference a stored identity of the same role',
        'Unexpected wrong-role activation failure');
}

echo "PKI storage: system scope, encrypted records, and fail-closed health states passed.\n";
