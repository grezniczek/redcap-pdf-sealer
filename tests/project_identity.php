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

require dirname(__DIR__) . '/autoload.php';

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
    public ?Closure $onLog = null;

    public function log(string $message, array $parameters): int
    {
        if ($this->onLog !== null) { ($this->onLog)($message, $parameters); }
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
require_once __DIR__ . '/support/CertificateSerials.php';
$issuer = new CertificateIssuer(static function (): string {
    $path = tempnam(sys_get_temp_dir(), 'pdf_sealer_project_test_');
    check(is_string($path), 'Could not create test OpenSSL config');
    return $path;
}, [\PDFSealerTests\CertificateSerials::class, 'reserve']);
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
$configurationHeld = false;
$beforeConfigurationAcquire = null;
$configurationLock = new \DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock(
    static function (string $sql, array $params) use (&$configurationHeld, &$beforeConfigurationAcquire): FakeResult {
        if (str_contains($sql, 'GET_LOCK')) {
            if ($configurationHeld) return new FakeResult([[0]]);
            if ($beforeConfigurationAcquire !== null) {
                $callback = $beforeConfigurationAcquire; $beforeConfigurationAcquire = null; $callback();
            }
            $configurationHeld = true;
        } else {
            check($configurationHeld, 'Configuration lock released without acquisition');
            $configurationHeld = false;
        }
        return new FakeResult([[1]]);
    },
);
$service = new ProjectIdentityService($bindings, $identities, $protector, $issuer, $health, $lock, $configurationLock);
$snapshot = [$framework->logs, $framework->settings, $lockCalls];
check($service->inspect(461) === ['state' => 'not_issued', 'uuid' => null, 'certificate' => null],
    'Unissued project status is unclear');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status issued an identity or acquired a lock');
$root = $issuer->createRoot('Test Institution');
$rootId = $identities->append('root', $root);
$identities->activate('root', $rootId);
$tsa = $issuer->createTsa('Test Institution', $root);
$tsaId = $identities->append('tsa', $tsa);
$identities->activate('tsa', $tsaId);
$identities->providers()->initialize($rootId, $tsaId);

// Gate is checked before binding, serial reservation, or certificate creation.
$providers = $identities->providers();
$providers->saveAssignmentPolicy(true);
$beforeGate = [$framework->logs, $framework->settings];
check($service->inspect(470)['state'] === 'assignment_required', 'Unassigned status did not show gate');
try { $service->getOrIssue(470); throw new RuntimeException('Gate bypassed'); }
catch (\DE\RUB\PDFSealerExternalModule\Pki\CaAssignmentRequired) {}
check([$framework->logs, $framework->settings] === $beforeGate && !$configurationHeld, 'Gate wrote PKI or retained lock');
// A concrete administrator binding permits issuance with the gate on.
$bindings->bindUuid(470, $issuer->newProjectUuid(), 'builtin-ca');
$explicit = $service->getOrIssue(470);
check($service->getOrIssue(470)->id === $explicit->id, 'Gate blocked existing identity');
$providers->saveAssignmentPolicy(false);
// Emulate a policy save winning the race while issuance waits to acquire the lock.
$beforeConfigurationAcquire = fn() => $providers->saveAssignmentPolicy(true);
$beforeGate = count($framework->logs);
try { $service->getOrIssue(471); throw new RuntimeException('Stale gate read'); }
catch (\DE\RUB\PDFSealerExternalModule\Pki\CaAssignmentRequired) {}
check(count($framework->logs) === $beforeGate && $bindings->find(471) === null, 'Waiter created automatic binding after save');
$providers->saveAssignmentPolicy(false);
// Emulate an administrative save during automatic first issuance: it cannot enter
// the configuration section until binding AND identity activation have finished.
$blockedSaves = 0;
$framework->onLog = static function ($message, $params) use ($configurationLock, $providers, &$blockedSaves): void {
    if (($params['redcap_pid'] ?? null) !== '461' && ($params['identity_role'] ?? null) !== 'project') return;
    try {
        $configurationLock->withLock(fn() => $providers->saveAssignmentPolicy(true));
        throw new RuntimeException('Policy save passed in-flight issuance');
    } catch (RuntimeException $e) {
        check($e->getMessage() === 'PKI initialization lock unavailable', 'Unexpected competing-save result');
        $blockedSaves++;
    }
};
$first = $service->getOrIssue(461);
$framework->onLog = null;
check($blockedSaves === 3 && !$configurationHeld, 'Configuration lock did not cover full automatic issuance');
$configurationLock->withLock(fn() => $providers->saveAssignmentPolicy(true));
check($service->getOrIssue(461)->id === $first->id, 'Saved gate affected an existing signer');
$providers->saveAssignmentPolicy(false);
// Invalid policy fails closed for an unbound project, but does not disturb bound projects.
$framework->settings['require_ca_assignment'] = 'invalid';
check($service->inspect(471)['state'] === 'unavailable', 'Invalid policy masqueraded as off');
try { $service->getOrIssue(471); throw new RuntimeException('Invalid policy allowed issuance'); }
catch (RuntimeException $e) { check($e->getMessage() === 'Invalid CA assignment policy', 'Unexpected policy error'); }
check($bindings->find(471) === null && $service->getOrIssue(461)->id === $first->id, 'Invalid policy changed bindings');
$providers->saveAssignmentPolicy(false);

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

// Changing the default only affects new projects; an assigned provider remains pinned.
$alternate = $identities->providers()->provider('builtin-ca');
$alternate['id'] = 'alternate-ca';
$framework->settings['ca_provider_alternate-ca'] = json_encode($alternate);
$framework->settings['default_ca_provider'] = 'alternate-ca';
check($service->getOrIssue(462)->providerId === 'builtin-ca', 'Default change moved an existing project');
$alternateIdentity = $service->getOrIssue(467);
check($alternateIdentity->providerId === 'alternate-ca' && $bindings->find(467)->providerId === 'alternate-ca',
    'New project did not bind to the chosen default');
$framework->settings['default_ca_provider'] = 'builtin-ca';

$uuid = $issuer->newProjectUuid();
$bindings->bindUuid(463, $uuid, 'builtin-ca');
$snapshot = [$framework->logs, $framework->settings, $lockCalls];
check($service->inspect(463) === ['state' => 'pending', 'uuid' => $uuid, 'certificate' => null],
    'Incomplete issuance reported as usable');
check([$framework->logs, $framework->settings, $lockCalls] === $snapshot, 'Status completed pending issuance');
$unbound = $issuer->createProject('Test Institution', $uuid, $root);
$unboundId = $identities->append('project', $unbound, $uuid, 'builtin-ca', $rootId);
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

$savedProvider = $framework->settings['ca_provider_builtin-ca'];
$provider = json_decode($savedProvider, true);
$provider['issuer_identity_id'] = str_repeat('0', 32);
$framework->settings['ca_provider_builtin-ca'] = json_encode($provider);
try {
    $service->getOrIssue(464);
    throw new RuntimeException('Broken root was accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Root PKI is not usable for project issuance', 'Unexpected root failure');
}
check(count($framework->logs) === $before, 'Broken root created project PKI material');
check($service->inspect(462)['state'] === 'ready', 'Existing signer depended on current issuance pointer');
check($service->getOrIssue(462)->id === $other->id, 'Current issuer change replaced existing signer');
$framework->settings['ca_provider_builtin-ca'] = $savedProvider;
// A root private-key failure blocks issuance, not reuse or timestamping with existing keys.
foreach ($framework->logs as &$row) {
    if (($row['identity_id'] ?? null) === $rootId) { $row['private_key_ciphertext'] = 'redcap-v1:corrupt'; }
}
unset($row);
check($service->getOrIssue(462)->id === $other->id && $service->inspect(462)['state'] === 'ready',
    'Existing signer depended on root private key');
check($health->inspectTimestamp('builtin-tsa', time())->status === \DE\RUB\PDFSealerExternalModule\Pki\PkiHealth::Ready,
    'Timestamping depended on root private key');
try {
    $service->getOrIssue(466);
    throw new RuntimeException('Issuance ignored unavailable root key');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Root PKI is not usable for project issuance', 'Unexpected issuance failure');
}
$builder = new \DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder();
$sample = \DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService::samplePdf();
$verifier = new \DE\RUB\PDFSealerExternalModule\Diagnostics\SampleSealVerifier();
$bb = $builder->seal($sample, $other->certificateDer, $other->privateKey($protector), [$service->issuerCertificate($other)], time());
$verifier->verify($sample, $bb, $other->certificateDer);
$timestamp = new \DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider(
    new \DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService(\DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy::DEFAULT_OID),
    new \DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$root->certificateDer]),
);
$bt = $builder->sealTimestamped($sample, $other->certificateDer, $other->privateKey($protector), [$service->issuerCertificate($other)], time(), $timestamp, time());
$verifier->verify($sample, $bt->pdf, $other->certificateDer, $tsa->certificateDer);
check(count($framework->logs) === $before, 'Reuse or failed issuance changed identity storage');

$unavailable = new ProjectIssueLock(static fn (): FakeResult => new FakeResult([[0]]));
try {
    $unavailable->withLock(465, static fn (): bool => true);
    throw new RuntimeException('Timed-out lock was accepted');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Project identity lock unavailable', 'Unexpected lock failure');
}

$framework->log('project_identity_binding', [
    'project_id' => null, 'record' => '', 'redcap_pid' => '462',
    'project_uuid' => $issuer->newProjectUuid(), 'identity_id' => $other->id, 'provider_id' => 'builtin-ca',
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
