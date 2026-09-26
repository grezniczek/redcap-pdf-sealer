<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

require dirname(__DIR__) . '/vendor/autoload.php';

function encrypt(string $plaintext): string|false
{
    return base64_encode('test-encrypted:' . $plaintext);
}
function decrypt(string $ciphertext): string|false
{
    $value = base64_decode($ciphertext, true);
    return is_string($value) && str_starts_with($value, 'test-encrypted:')
        ? substr($value, strlen('test-encrypted:')) : false;
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
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_row(): ?array { return array_shift($this->rows); }
}

final class FakeFramework
{
    public array $logs = [];
    public array $settings = [];

    public function log(string $message, array $parameters): int
    {
        check(array_key_exists('project_id', $parameters) && $parameters['project_id'] === null,
            'Project PKI log was not system scoped');
        check(($parameters['record'] ?? null) === '', 'Project PKI log retained a record ID');
        $id = count($this->logs) + 1;
        $this->logs[] = ['log_id' => $id, 'message' => $message] + array_filter(
            $parameters, static fn (mixed $value): bool => $value !== null,
        );
        return $id;
    }

    public function queryLogs(string $sql, array $parameters): FakeResult
    {
        check(str_contains($sql, 'ISNULL(project_id)'), 'Project lookup was not system scoped');
        $rows = array_values(array_filter($this->logs, static function (array $row) use ($sql, $parameters): bool {
            if ($row['message'] !== $parameters[0]) {
                return false;
            }
            $index = 1;
            foreach (['identity_id', 'identity_role', 'project_uuid', 'redcap_pid'] as $field) {
                if (str_contains($sql, $field . ' = ?') && ($row[$field] ?? null) !== $parameters[$index++]) {
                    return false;
                }
            }
            return true;
        }));
        if (str_contains($sql, 'ORDER BY log_id DESC')) {
            $rows = array_reverse($rows);
        }
        return new FakeResult(array_slice($rows, 0, str_contains($sql, 'LIMIT 2') ? 2 : 1));
    }

    public function getSystemSetting(string $key): mixed { return $this->settings[$key] ?? null; }
    public function setSystemSetting(string $key, mixed $value): void { $this->settings[$key] = $value; }
}

$framework = new FakeFramework();
$protector = new SecretProtector();
$reader = new PrimaryLogReader($framework, [$framework, 'queryLogs']);
$identities = new IdentityRepository($framework, $protector, $reader, new PrimarySystemSettingReader($framework, [$framework, 'getSystemSetting']));
$bindings = new ProjectBindingRepository($framework, $reader);
$issuer = new CertificateIssuer(static function (): string {
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_project_test_');
    check(is_string($path), 'Could not create test OpenSSL config');
    return $path;
});
$held = false;
$lockCalls = 0;
$lock = new ProjectIssueLock(static function (string $sql, array $params) use (&$held, &$lockCalls): FakeResult {
    $lockCalls++;
    if (str_contains($sql, 'GET_LOCK')) {
        if ($held) { return new FakeResult([[0]]); }
        $held = true;
        return new FakeResult([[1]]);
    }
    check($held, 'Lock was released without acquisition');
    $held = false;
    return new FakeResult([[1]]);
});
$health = new PkiHealthService($identities, $protector);
$service = new ProjectIdentityService($bindings, $identities, $protector, $issuer, $health, $lock);
$snapshot = [$framework->logs, $framework->settings, $lockCalls];
check($service->inspect(461) === ['state' => 'not_issued', 'uuid' => null, 'certificate' => null],
    'Unissued project status is unclear');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status issued an identity or acquired a lock');
$root = $issuer->createRoot('Test Institution');
$rootId = $identities->append('root', $root);
$identities->activate('root', $rootId);

$first = $service->getOrIssue(461);
$snapshot = [$framework->logs, $framework->settings, $lockCalls];
$status = $service->inspect(461);
check($status['state'] === 'ready' && $status['uuid'] === $first->projectUuid
    && $status['certificate']['fingerprint'] === hash('sha256', $first->certificateDer), 'Wrong active project status');
check(array_keys($status['certificate']) === ['subject', 'fingerprint', 'valid_from', 'valid_until'],
    'Status exposed non-public identity fields');
check(!str_contains(json_encode($status), 'PRIVATE KEY') && !str_contains(json_encode($status), 'ciphertext'),
    'Status exposed key material');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status mutated active identity');
// Inspect validity boundaries without changing or renewing the stored identity.
$snapshot = [$framework->logs, $framework->settings, $lockCalls];
$expired = $service->inspect(461, $status['certificate']['valid_until'] + 1);
check($expired['state'] === 'expired' && $expired['certificate'] === $status['certificate'],
    'Expired identity status lost its public metadata');
check($service->inspect(461, $status['certificate']['valid_from'] - 1)['state'] === 'not_yet_valid',
    'Future certificate reported usable');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status renewed expired identity');
$again = $service->getOrIssue(461);
check($first->id === $again->id && $first->projectUuid === $again->projectUuid,
    'Repeated issuance changed the project identity');
check($bindings->find(461)?->identityId === $first->id, 'Active binding was not persisted');
check(!$held, 'Project lock remained held');
$other = $service->getOrIssue(462);
check($other->id !== $first->id && $other->projectUuid !== $first->projectUuid,
    'Different projects share an identity');

$uuid = $issuer->newProjectUuid();
$bindings->bindUuid(463, $uuid);
$snapshot = [$framework->logs, $framework->settings, $lockCalls];
check($service->inspect(463) === ['state' => 'pending', 'uuid' => $uuid, 'certificate' => null],
    'Incomplete issuance reported as usable');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status completed pending issuance');
$unbound = $issuer->createProject('Test Institution', $uuid, $root);
$unboundId = $identities->append('project', $unbound, $uuid);
check($service->getOrIssue(463)->id === $unboundId,
    'Interrupted issuance did not reuse its identity');
check($bindings->find(463)?->identityId === $unboundId,
    'Recovered identity was not activated');

$before = count($framework->logs);
foreach ($framework->logs as &$log) {
    if (($log['identity_id'] ?? null) === $first->id) {
        $log['private_key_ciphertext'] = 'redcap-v1:corrupt';
        break;
    }
}
unset($log);
try {
    $service->getOrIssue(461);
    throw new RuntimeException('Corrupt active project identity was replaced');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'REDCap decryption failed', 'Unexpected project-key failure');
}
check(count($framework->logs) === $before, 'Corrupt active identity caused reissuance');
$snapshot = [$framework->logs, $framework->settings, $lockCalls];
$status = $service->inspect(461);
check($status['state'] === 'unusable' && $status['certificate'] !== null, 'Bad key status lost public certificate details');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status repaired corrupt identity');

$framework->settings['active_root_identity_id'] = str_repeat('0', 32);
try {
    $service->getOrIssue(464);
    throw new RuntimeException('Broken root was accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Root PKI is not usable for project issuance', 'Unexpected root failure');
}
check(count($framework->logs) === $before, 'Broken root created project PKI material');
check($service->inspect(462)['state'] === 'unusable', 'Missing active root reported usable');
$framework->settings['active_root_identity_id'] = $rootId;

$unavailable = new ProjectIssueLock(static fn (): FakeResult => new FakeResult([[0]]));
try {
    $unavailable->withLock(465, static fn (): bool => true);
    throw new RuntimeException('Timed-out lock was accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Project identity lock unavailable', 'Unexpected lock failure');
}

$framework->log('project_identity_binding', [
    'project_id' => null, 'record' => '', 'redcap_pid' => '462',
    'project_uuid' => $issuer->newProjectUuid(), 'identity_id' => $other->id,
]);
try {
    $bindings->find(462);
    throw new RuntimeException('Conflicting project UUID was accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Conflicting project identity binding', 'Unexpected binding failure');
}

$snapshot = [$framework->logs, $framework->settings, $lockCalls];
check($service->inspect(462)['state'] === 'unavailable', 'Conflicting binding was not reported');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status changed conflicting binding');
echo "Project identity: issuance, recovery, corruption, locking, and read-only status passed.\n";
