<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationService;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require dirname(__DIR__) . '/vendor/autoload.php';

function encrypt(string $plaintext): string|false { return base64_encode('test-encrypted:' . $plaintext); }
function decrypt(string $ciphertext): string|false
{
    $value = base64_decode($ciphertext, true);
    return is_string($value) && str_starts_with($value, 'test-encrypted:')
        ? substr($value, strlen('test-encrypted:')) : false;
}
function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
final class FakeResult
{
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_row(): ?array { return array_shift($this->rows); }
}
final class FakeFramework
{
    public array $logs = [];
    public array $settings = [];
    public bool $failTsaWrite = false;

    public function getSystemSetting(string $key): mixed { return $this->settings[$key] ?? null; }
    public function setSystemSetting(string $key, mixed $value): void { $this->settings[$key] = $value; }
    public function log(string $message, array $parameters): int
    {
        check(array_key_exists('project_id', $parameters) && $parameters['project_id'] === null,
            'PKI record was not system scoped');
        check(($parameters['record'] ?? null) === '', 'PKI record retained a clinical record');
        if ($this->failTsaWrite && ($parameters['identity_role'] ?? null) === 'tsa') {
            throw new RuntimeException('Simulated TSA write failure');
        }
        $id = count($this->logs) + 1;
        $this->logs[] = ['log_id' => $id, 'message' => $message] + array_filter(
            $parameters, static fn (mixed $value): bool => $value !== null,
        );
        return $id;
    }
    public function queryLogs(string $sql, array $parameters): FakeResult
    {
        check(str_contains($sql, 'ISNULL(project_id)'), 'PKI query was not system scoped');
        $rows = array_values(array_filter($this->logs, static function (array $row) use ($sql, $parameters): bool {
            if ($row['message'] !== $parameters[0]) { return false; }
            $index = 1;
            foreach (['identity_id', 'identity_role', 'project_uuid', 'redcap_pid'] as $field) {
                if (str_contains($sql, $field . ' = ?') && ($row[$field] ?? null) !== $parameters[$index++]) {
                    return false;
                }
            }
            return true;
        }));
        if (str_contains($sql, 'ORDER BY log_id DESC')) { $rows = array_reverse($rows); }
        return new FakeResult(array_slice($rows, 0, str_contains($sql, 'LIMIT 2') ? 2 : 1));
    }
}

function fixture(FakeFramework $framework): array
{
    $protector = new SecretProtector();
    $reader = new PrimaryLogReader($framework, [$framework, 'queryLogs']);
    $identities = new IdentityRepository($framework, $protector, $reader, new PrimarySystemSettingReader($framework, [$framework, 'getSystemSetting']));
    $bindings = new ProjectBindingRepository($framework, $reader);
    $issuer = new CertificateIssuer(static function (): string {
        $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_init_test_');
        check(is_string($path), 'Cannot create test OpenSSL config');
        return $path;
    });
    $health = new PkiHealthService($identities, $protector);
    $held = false;
    $lock = new PkiInitializationLock(static function (string $sql, array $params) use (&$held): FakeResult {
        check($params === ['pdf_sealer_initialize'], 'Wrong initialization lock name');
        if (str_contains($sql, 'GET_LOCK')) {
            if ($held) { return new FakeResult([[0]]); }
            $held = true;
            return new FakeResult([[1]]);
        }
        check($held, 'Initialization lock was not held');
        $held = false;
        return new FakeResult([[1]]);
    });
    $snapshot = null;
    $transaction = static function (string $sql) use ($framework, &$snapshot): bool {
        if ($sql === 'START TRANSACTION') {
            $snapshot = [$framework->logs, $framework->settings];
        } elseif ($sql === 'ROLLBACK') {
            [$framework->logs, $framework->settings] = $snapshot;
        } elseif ($sql !== 'COMMIT') {
            throw new RuntimeException('Unexpected transaction command');
        }
        return true;
    };
    $service = new PkiInitializationService($framework, $identities, $bindings, $issuer, $health, $lock, $transaction);
    return [$service, $health, $identities, $bindings, $issuer];
}

$framework = new FakeFramework();
[$service, $health, $identities] = fixture($framework);
check($health->inspect(time())->status === PkiHealth::Uninitialized, 'Fresh PKI is not uninitialized');
$service->initialize('Test Institution');
check($health->inspect(time())->status === PkiHealth::Ready, 'Initialized root and TSA are not ready');
check($framework->settings['organization'] === 'Test Institution', 'Organization was not persisted');
check($identities->activeId('root') !== $identities->activeId('tsa'), 'Root and TSA share an identity');
$count = count($framework->logs);
try {
    $service->initialize('Replacement Institution');
    throw new RuntimeException('Existing PKI was replaced');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Existing PKI material prevents initialization', 'Unexpected repeat failure');
}
check(count($framework->logs) === $count && $framework->settings['organization'] === 'Test Institution',
    'Repeat initialization changed PKI material');

$failedFramework = new FakeFramework();
$failedFramework->failTsaWrite = true;
[$failedService, $failedHealth] = fixture($failedFramework);
try {
    $failedService->initialize('Test Institution');
    throw new RuntimeException('TSA write failure was ignored');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Simulated TSA write failure', 'Unexpected transaction failure');
}
check($failedFramework->logs === [] && $failedFramework->settings === []
    && $failedHealth->inspect(time())->status === PkiHealth::Uninitialized,
    'Failed initialization left a partial PKI');

$orphanFramework = new FakeFramework();
[$orphanService, $orphanHealth, $orphanIdentities, , $orphanIssuer] = fixture($orphanFramework);
$orphanIdentities->append('root', $orphanIssuer->createRoot('Test Institution'));
check($orphanHealth->inspect(time())->status === PkiHealth::Broken, 'Orphan root was not broken');
try {
    $orphanService->initialize('Test Institution');
    throw new RuntimeException('Orphan root was replaced');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Existing PKI material prevents initialization', 'Unexpected orphan failure');
}

$bindingFramework = new FakeFramework();
[$bindingService, , , $bindingRepository, $bindingIssuer] = fixture($bindingFramework);
$bindingRepository->bindUuid(461, $bindingIssuer->newProjectUuid());
try {
    $bindingService->initialize('Test Institution');
    throw new RuntimeException('Orphan binding was ignored');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Existing PKI material prevents initialization', 'Unexpected binding failure');
}

echo "PKI initialization: ready chain, one-time guard, rollback, and orphan rejection passed.\n";
