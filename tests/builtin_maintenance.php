<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{BuiltinMaintenanceService, LeafRenewalPolicy, ProjectRenewalService, GeneratedIdentity};
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService, AlarmRepository, AlarmLock};
use DE\RUB\PDFSealerExternalModule\Diagnostics\{DiagnosticSnapshot, PkiDiagnosticService, SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1;

// Real crypto, primary-reader doubles, transactional storage and lock interleavings; no live REDCap data.
require __DIR__ . '/project_renewal.php';

final class CronFramework
{
    public ?string $failQuery = null;
    public function __construct(private object $inner) {}
    public function __call(string $name, array $args): mixed { return $this->inner->$name(...$args); }
    public function getUser(): never { throw new LogicException('Cron tried to use a human session'); }
    public function query(string $sql, array $params): bool {
        if ($this->failQuery === $sql) { return false; }
        return $this->inner->query($sql, $params);
    }
}

/** Disposable clone signed by the known issuing key; preserves leaf subject, key and extensions. */
function maintenanceCertificate(string $der, OpenSSLAsymmetricKey $issuerKey, int $start, int $end): string
{
    $asn1 = new Asn1(); $outer = $asn1->readSingleElement($der, 0x30, 'certificate'); $offset = 0;
    $tbs = $asn1->readTlv($outer['value'], $offset); $algorithm = $asn1->readTlv($outer['value'], $offset);
    $fields = []; $offset = 0;
    while ($offset < strlen($tbs['value'])) { $fields[] = $asn1->readTlv($tbs['value'], $offset); }
    $encodeTime = static fn(int $time): string => "\x17\x0d" . gmdate('ymdHis', $time) . 'Z';
    $fields[4]['raw'] = $asn1->encodeSequence($encodeTime($start) . $encodeTime($end));
    $newTbs = $asn1->encodeSequence(implode('', array_column($fields, 'raw')));
    check(openssl_sign($newTbs, $signature, $issuerKey, OPENSSL_ALGO_SHA256), 'Fixture signing failed');
    return $asn1->encodeSequence($newTbs . $algorithm['raw'] . "\x03" . $asn1->encodeLength(strlen($signature) + 1) . "\x00" . $signature);
}

$cronFramework = new CronFramework($framework);
$automaticRenewal = new ProjectRenewalService($cronFramework, $bindings, $identities, $enrollment, $projects, $health, $projectLock, $configLock);
$runLock = new AlarmLock($lockQuery);
$alarms = new AdminAlarmService($cronFramework, new AlarmRepository($cronFramework), new AlarmLock($lockQuery), static fn(): bool => true);
$worker = new BuiltinMaintenanceService($cronFramework, $identities, $bindings, $protector, $issuer, $health,
    $automaticRenewal, $configLock, $runLock, $alarms, $settings);
$now = time();
$originalRoot = $identities->find($identities->activeId('root'));
// Backdate the disposable issuing certificate so expired leaf fixtures have genuine historical provenance.
$historicalDer = maintenanceCertificate($originalRoot->certificateDer, $originalRoot->privateKey($protector),
    $now - 10 * 86400, (new Certificate())->fields($originalRoot->certificateDer)['not_after']);
$rootGenerated = new GeneratedIdentity($historicalDer, $originalRoot->asGeneratedIdentity($protector)->privateKeyPem());
$rootId = $identities->append('root', $rootGenerated);
$identities->activate('root', $rootId);
$provider = $identities->providers()->provider('builtin-ca'); $provider['issuer_identity_id'] = $rootId;
$f->settings['ca_provider_builtin-ca'] = json_encode($provider);
$source = $identities->providers()->source('builtin-tsa'); $source['issuer_identity_id'] = $rootId;
$f->settings['tsa_source_builtin-tsa'] = json_encode($source);
$automaticRenewal->renewAutomatically(103, $now);

$installProject = static function(int $pid, int $expires) use ($bindings, $identities, $issuer, $rootGenerated, $rootId, $now): string {
    $uuid = $issuer->newProjectUuid(); $generated = $issuer->createProject('External CA Test', $uuid, $rootGenerated);
    $der = maintenanceCertificate($generated->certificateDer, $rootGenerated->privateKey(), $now - 3 * 86400, $expires);
    $id = $identities->append('project', new GeneratedIdentity($der, $generated->privateKeyPem()), $uuid, 'builtin-ca', $rootId);
    $bindings->bindUuid($pid, $uuid, 'builtin-ca'); $bindings->activate($pid, $uuid, $id);
    return $id;
};
$installTsa = static function(int $expires) use ($identities, $issuer, $rootGenerated, $rootId, $f, $now): string {
    $generated = $issuer->createTsa('External CA Test', $rootGenerated);
    $der = maintenanceCertificate($generated->certificateDer, $rootGenerated->privateKey(), $now - 3 * 86400, $expires);
    $id = $identities->append('tsa', new GeneratedIdentity($der, $generated->privateKeyPem()));
    $identities->activate('tsa', $id);
    $source = $identities->providers()->source('builtin-tsa'); $source['identity_id'] = $id; $source['issuer_identity_id'] = $rootId;
    $f->settings['tsa_source_builtin-tsa'] = json_encode($source);
    return $id;
};
try {
    check(!LeafRenewalPolicy::due($now + LeafRenewalPolicy::WINDOW + 1, $rootId, $rootId, $now)
        && LeafRenewalPolicy::due($now + LeafRenewalPolicy::WINDOW, $rootId, $rootId, $now)
        && LeafRenewalPolicy::due($now + 730 * 86400, $rootId, str_repeat('a',32), $now), 'Renewal window boundary incorrect');
    rejects(fn() => LeafRenewalPolicy::assertIssuerWindow($now + LeafRenewalPolicy::WINDOW + 86399, $now));
    LeafRenewalPolicy::assertIssuerWindow($now + LeafRenewalPolicy::WINDOW + 86400, $now);
    $sourcePolicy = $identities->providers()->source('builtin-tsa')['policy_oid'];
    $oldTsa = $installTsa($now - 86400);
    $old = []; $uuids = [];
    for ($pid = 301; $pid <= 306; ++$pid) {
        $old[$pid] = $installProject($pid, $pid === 301 ? $now - 86400 : $now + 20 * 86400);
        $uuids[$pid] = $bindings->find($pid)->uuid;
    }
    // An expired externally supplied signer is reported elsewhere, never replaced by local maintenance.
    $externalUuid = $issuer->newProjectUuid();
    $externalGenerated = $issuer->createProject('External CA Test', $externalUuid, $rootGenerated);
    $externalDer = maintenanceCertificate($externalGenerated->certificateDer, $rootGenerated->privateKey(), $now - 3 * 86400, $now - 86400);
    $externalRoot = DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::certificateDer($providers->provider($second)['chain'][0]);
    $externalIdentity = $identities->append('project', new GeneratedIdentity($externalDer, $externalGenerated->privateKeyPem()),
        $externalUuid, $second, hash('sha256', $externalRoot), [$externalRoot]);
    $bindings->bindUuid(310, $externalUuid, $second); $bindings->activate(310, $externalUuid, $externalIdentity);
    $f->enabled = [103,301,302,303,304,305,310]; // 306 remains disabled; its existing binding is maintained later.
    $versions = DiagnosticSnapshot::currentVersions($identities);
    $cache = new DiagnosticSnapshot($cronFramework);
    $cache->save(['passed'=>true,'checks'=>array_fill_keys(DiagnosticSnapshot::CHECKS,'passed'),'versions'=>$versions], $now - 100);
    $cached = $f->settings['last-diagnostic-result'];
    $policySettings = array_intersect_key($f->settings, array_flip(['ca_provider_builtin-ca','default_ca_provider','require_ca_assignment']));
    $f->onLog = static function(string $message, array $values) use ($worker, &$held): void {
        if (in_array($message,['pki_identity','project_identity_binding','tsa_certificate_renewal','project_certificate_renewal'],true)) {
            check(isset($held['pdf_sealer_initialize']), 'Maintenance write without configuration lock');
            if (isset($values['redcap_pid'])) check(isset($held['pdf_sealer_issue_'.$values['redcap_pid']]), 'Maintenance write without project lock');
            rejects(fn() => $worker->run()); // Another cron run cannot enter while mutation is in progress.
        }
    };
    $result = $worker->run($now); $f->onLog = null;
    check($result['renewed'] === 6 && $result['remaining'] === 1 && $result['status'] === 'pending', 'First bounded run incorrect: '.json_encode($result));
    check($bindings->find(306)->identityId === $old[306], 'Disabled project displaced enabled projects');
    check($identities->activeId('root') === $rootId && $identities->activeId('tsa') !== $oldTsa, 'Leaf maintenance changed root or failed TSA renewal');
    $currentTsa = $identities->find($identities->activeId('tsa'));
    $source = $identities->providers()->source('builtin-tsa');
    check($source['identity_id'] === $currentTsa->id && $source['policy_oid'] === $sourcePolicy, 'TSA references/policy were not preserved');
    check(openssl_pkey_get_details($currentTsa->privateKey($protector))['key']
        !== openssl_pkey_get_details($identities->find($oldTsa)->privateKey($protector))['key'], 'TSA reused its old key');
    foreach ([301,302,303,304,305] as $pid) {
        $binding = $bindings->find($pid);
        check($binding->identityId !== $old[$pid] && $binding->uuid === $uuids[$pid] && $binding->providerId === 'builtin-ca'
            && $identities->find($old[$pid]) !== null, 'Renewal lost UUID/provider/history');
        check(openssl_pkey_get_details($identities->find($binding->identityId)->privateKey($protector))['key']
            !== openssl_pkey_get_details($identities->find($old[$pid])->privateKey($protector))['key'], 'Project reused old key');
    }
    check(array_intersect_key($f->settings, $policySettings) === $policySettings, 'Maintenance changed assignment/default/timestamp policy');
    check($f->settings['last-diagnostic-result'] === $cached
        && DiagnosticSnapshot::versionsChanged($cache->load(), DiagnosticSnapshot::currentVersions($identities)), 'Diagnostic time/results changed or new TSA was not detected');
    $result = $worker->run($now + 1);
    check($result['renewed'] === 1 && $bindings->find(306)->identityId !== $old[306] && !in_array(306, $f->enabled), 'Disabled retained signer was not renewed');
    $before = count($f->logs); $result = $worker->run($now + 2);
    check($result['renewed'] === 0 && count($f->logs) === $before, 'Idempotent run wrote new identities/audit');
    foreach ($f->logs as $row) {
        if (($row['reason'] ?? null) === 'automatic') check($row['actor'] === 'system:cron', 'Maintenance fabricated a human actor');
    }
    // Real finalizer uses the renewed project/TSA pair; the stored history remains unchanged.
    $sample = PkiDiagnosticService::samplePdf(); $path = $f->createTempFile(); file_put_contents($path, $sample);
    $f->projectId = 301;
    $result = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['document_type'=>'econsent','project_id'=>301,'record_id'=>'1','event_id'=>1]);
    check($result->isModified(), 'Renewed pair failed actual finalizer');
    (new SampleSealVerifier())->verify($sample,file_get_contents($path),$identities->find($bindings->find(301)->identityId)->certificateDer,$currentTsa->certificateDer);

    // Atomic TSA activation rollback and exponential retry; old references stay paired.
    $failingTsa = $installTsa($now + 10 * 86400);
    $beforeSource = $f->settings['tsa_source_builtin-tsa'];
    $f->onLog = static function(string $message): void { if ($message === 'tsa_certificate_renewal') throw new RuntimeException('Injected audit failure'); };
    $result = $worker->run($now + 3); $f->onLog = null;
    check($result['failed'] === 1 && $identities->activeId('tsa') === $failingTsa && $f->settings['tsa_source_builtin-tsa'] === $beforeSource
        && $f->snapshot === null, 'TSA rollback partially deployed a replacement');
    $result = $worker->run($now + 4);
    check($result['renewed'] === 0 && $result['remaining'] === 1, 'TSA failure ignored retry backoff');
    $result = $worker->run($now + 3604);
    check($result['renewed'] === 1 && $result['retries'] === [], 'TSA retry did not recover');

    // Project binding/audit rollback and retained identity; repeated failure doubles the retry interval.
    $failingProject = $installProject(307, $now - 86400);
    $f->enabled[] = 307;
    $cronFramework->failQuery = 'COMMIT';
    $result = $worker->run($now + 3605);
    check($result['failed'] === 1 && $bindings->find(307)->identityId === $failingProject && $f->snapshot === null, 'Commit failure partially activated project');
    $result = $worker->run($now + 7206);
    check($result['failed'] === 1 && $result['retries']['project-307']['attempts'] === 2
        && $result['retries']['project-307']['retry_at'] === $now + 7206 + 7200, 'Failure backoff did not increase');
    $cronFramework->failQuery = null;
    $result = $worker->run($now + 14407);
    check($result['renewed'] === 1 && $bindings->find(307)->identityId !== $failingProject, 'Expired project did not recover after downtime/retry');

    // Pending transitions and CSRs remain untouched; retirement defers projects, not the independent TSA.
    $pending = $installProject(308, $now + 10 * 86400);
    $transition = $bindings->startTransition(308, $second);
    $csr = $enrollment->generate(308); $pendingSetting = $f->settings['pending_enrollment_308'];
    $result = $worker->run($now + 14408);
    check($result['deferred'] === 1 && $bindings->find(308)->identityId === $pending
        && $f->settings['pending_enrollment_308'] === $pendingSetting && $bindings->find(308)->transitionId === $transition, 'Maintenance destroyed pending enrollment/transition');
    $enrollment->cancel(308,$csr['pending']['id']); $bindings->cancelTransition(308,$transition);
    $f->settings['ca_provider_retired_builtin-ca'] = 'true';
    $installTsa($now + 10 * 86400);
    $result = $worker->run($now + 18009);
    check($result['renewed'] === 1 && $result['deferred'] === 1 && $bindings->find(308)->identityId === $pending, 'Retirement semantics incorrect');
    unset($f->settings['ca_provider_retired_builtin-ca']);
    $result = $worker->run($now + 21610);
    check($result['renewed'] === 1, 'Deferred project did not recover after retirement/transition clearance');
    check($bindings->find(101)->identityId === null && $bindings->find(104) === null && $bindings->find(310)->identityId === $externalIdentity, 'Worker initialized unissued/external assignments');

    // Budget is checked before starting each project, even when count limit is not exhausted.
    $budgetProject = $installProject(309, $now + 10 * 86400);
    $ticks = 0;
    $budgetWorker = new BuiltinMaintenanceService($cronFramework,$identities,$bindings,$protector,$issuer,$health,$automaticRenewal,
        $configLock,$runLock,$alarms,$settings,static function() use (&$ticks): float { return $ticks++ === 0 ? 0.0 : 61.0; });
    $result = $budgetWorker->run($now + 21611);
    check($result['renewed'] === 0 && $result['remaining'] === 1 && $bindings->find(309)->identityId === $budgetProject, 'Work budget started a late project renewal');

    // Near-expiry root is never reset; capped leaves already inside the renewal window are not deployed.
    $nearRootDer = maintenanceCertificate($rootGenerated->certificateDer,$rootGenerated->privateKey(),$now - 86400,$now + 20 * 86400);
    $nearRootId = $identities->append('root',new GeneratedIdentity($nearRootDer,$rootGenerated->privateKeyPem()));
    $identities->activate('root',$nearRootId);
    $provider = $identities->providers()->provider('builtin-ca'); $provider['issuer_identity_id'] = $nearRootId;
    $f->settings['ca_provider_builtin-ca'] = json_encode($provider);
    $beforeTsa = $identities->activeId('tsa');
    $result = $worker->run($now + 21612);
    check($result['failed'] > 0 && $identities->activeId('root') === $nearRootId && $identities->activeId('tsa') === $beforeTsa
        && $bindings->find(309)->identityId === $budgetProject, 'Insufficient root window deployed useless replacements or reset PKI');
    check($held === [] && $f->snapshot === null, 'Maintenance leaked locks/transaction');
    check(BuiltinMaintenanceService::load($settings) === $result, 'Maintenance snapshot did not round-trip');
    // A root-generation change clears earlier issuance backoff even when project identity IDs have not changed.
    $identities->activate('root',$rootId);
    $provider['issuer_identity_id'] = $rootId; $f->settings['ca_provider_builtin-ca'] = json_encode($provider);
    $result = $worker->run($now + 21613);
    check($result['renewed'] === 1 && $bindings->find(309)->identityId !== $budgetProject,
        'Root generation change retained an obsolete project retry delay');

    // A root committed after request start must be validated against current time once locks are acquired.
    $freshRootDer = maintenanceCertificate($rootGenerated->certificateDer,$rootGenerated->privateKey(),time(),
        (new Certificate())->fields($rootGenerated->certificateDer)['not_after']);
    $freshRootId = $identities->append('root',new GeneratedIdentity($freshRootDer,$rootGenerated->privateKeyPem()));
    $identities->activate('root',$freshRootId);
    $provider['issuer_identity_id'] = $freshRootId; $f->settings['ca_provider_builtin-ca'] = json_encode($provider);
    $result = $worker->run($now - 3600);
    check($result['failed'] === 0 && $result['renewed'] > 0 && $identities->providers()->source('builtin-tsa')['issuer_identity_id'] === $freshRootId,
        'Maintenance used request-start time for a later root generation');
    // The public cron entry does not initialize an empty installation or require a human session.
    $savedSettings = $f->settings; $savedLogs = $f->logs;
    $f->settings = []; $f->logs = [];
    $module->framework = $cronFramework;
    $summary = $module->maintainBuiltinCertificates([]);
    check(str_contains($summary,'uninitialized') && $f->logs === []
        && array_keys($f->settings) === [BuiltinMaintenanceService::SETTING], 'Cron initialized empty PKI or accessed a human session');
    $f->settings = $savedSettings; $f->logs = $savedLogs;
    unset($f->settings['active_root_identity_id'], $f->settings[BuiltinMaintenanceService::SETTING]);
    $identityCount = count(array_filter($f->logs,static fn(array $r): bool => $r['message'] === 'pki_identity'));
    $result = $worker->run($now + 21613);
    check($result['status'] === 'failed' && !isset($f->settings['active_root_identity_id'])
        && count(array_filter($f->logs,static fn(array $r): bool => $r['message'] === 'pki_identity')) === $identityCount,
        'Missing existing root pointer silently reset PKI');
    echo 'Built-in maintenance: bounded runs, current/expired leaves, fresh keys, history, atomic TSA/project rollback, backoff, pending work, retirement, disabled/external isolation, diagnostics, finalizer and root-window refusal passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    $f->onLog = null;
    foreach ($f->paths as $path) { @unlink($path); }
}
