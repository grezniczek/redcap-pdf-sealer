<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{RootRenewalService, BuiltinMaintenanceService, CrlIssuer, CrlRepository, CrlPublicationService, GeneratedIdentity, CertificateIssuer, ProviderRepository, PublicTrustRepository};
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService, AlarmRepository, AlarmLock};
use DE\RUB\PDFSealerExternalModule\Diagnostics\{DiagnosticSnapshot, PkiDiagnosticService, SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pdf\{PdfSealBuilder, PdfFinalizeService};

require __DIR__ . '/project_renewal.php';
require __DIR__ . '/support/certificate_validity.php';
require __DIR__ . '/support/pdf_seal_checks.php';

final class RootCronFramework
{
    public ?string $failQuery = null;
    public function __construct(private object $inner) {}
    public function __call(string $name, array $args): mixed { return $this->inner->$name(...$args); }
    public function getUser(): never { throw new LogicException('Root cron tried to use a human session'); }
    public function query(string $sql, array $params): bool {
        return $this->failQuery === $sql ? false : $this->inner->query($sql,$params);
    }
}
$cron = new RootCronFramework($framework);
$crls = new CrlRepository($cron,$settings);
$maintenanceIssuer = CertificateIssuer::forFramework($cron,'maintenance');
$rootRenewal = new RootRenewalService($cron,$identities,$protector,$maintenanceIssuer,$health,$crls,$configLock);
$automaticRenewal = new DE\RUB\PDFSealerExternalModule\Pki\ProjectRenewalService(
    $cron,$bindings,$identities,$enrollment,$projects,$health,$projectLock,$configLock);
$alarmLock = new AlarmLock($lockQuery);
$alarms = new AdminAlarmService($cron,new AlarmRepository($cron),$alarmLock,static fn(): bool => true);
$worker = new BuiltinMaintenanceService($cron,$identities,$bindings,$protector,$maintenanceIssuer,$health,
    $automaticRenewal,$configLock,$alarmLock,$alarms,$settings);
$now = time();
$base = $issuer->createRoot('Root Renewal Test');
$nearDer = maintenanceCertificate($base->certificateDer,$base->privateKey(),$now-86400,$now+RootRenewalService::WINDOW-86400);
$near = new GeneratedIdentity($nearDer,$base->privateKeyPem());
$oldTsa = $issuer->createTsa('Root Renewal Test',$near);
$entries = [['serial_hex'=>'123456789abcdef','revoked_at'=>$now-60,'reason'=>5]];
$previousCrl = (new CrlIssuer())->issue($near,17,$now,$entries);
$originalUrl = CrlIssuer::url('https://redcap.example/surveys/',$nearDer);
$reset = static function() use ($f,$identities,$near,$oldTsa,$providers,$crls,$previousCrl): array {
    $f->settings = []; $f->logs = []; $f->enabled = [];
    $rootId = $identities->append('root',$near); $tsaId = $identities->append('tsa',$oldTsa);
    $identities->activate('root',$rootId); $identities->activate('tsa',$tsaId);
    $providers->initialize($rootId,$tsaId);
    $f->settings['organization'] = 'Root Renewal Test';
    $providers->saveAssignmentPolicy(true);
    $providers->saveTimestampPolicy('builtin-ca','builtin-tsa',false);
    $source = $providers->source('builtin-tsa'); $source['policy_oid'] = '2.25.123456789012345678901234567890';
    $f->settings['tsa_source_builtin-tsa'] = json_encode($source);
    $crls->save($near->certificateDer,$previousCrl);
    return [$rootId,$tsaId];
};
try {
    check(!RootRenewalService::due($now+RootRenewalService::WINDOW+1,$now)
        && RootRenewalService::due($now+RootRenewalService::WINDOW,$now)
        && RootRenewalService::due($now-1,$now), 'Root renewal window boundary incorrect');
    // Each durable write and transaction failure must leave the original pair/configuration/CRL intact.
    foreach (['START TRANSACTION','COMMIT','root_identity','tsa_identity','pki_crl_publication','root_certificate_renewal'] as $failure) {
        [$rootId,$tsaId] = $reset();
        $before = $f->settings;
        $f->onLog = static function(string $message,array $values) use ($failure): void {
            if (($failure === 'root_identity' && $message === 'pki_identity' && $values['identity_role'] === 'root')
                || ($failure === 'tsa_identity' && $message === 'pki_identity' && $values['identity_role'] === 'tsa')
                || $message === $failure) { throw new RuntimeException('Injected root write failure'); }
        };
        $cron->failQuery = in_array($failure,['START TRANSACTION','COMMIT'],true) ? $failure : null;
        rejects(fn() => $rootRenewal->renewIfDue(time()));
        $f->onLog = null; $cron->failQuery = null;
        check($f->settings === $before && $f->snapshot === null && $identities->activeId('root') === $rootId
            && $identities->activeId('tsa') === $tsaId && count((new PublicTrustRepository($logs,$settings))->roots()) === 1,
            'Root renewal partially committed at '.$failure);
    }
    foreach (['active_root_identity_id','active_tsa_identity_id','ca_provider_builtin-ca','tsa_source_builtin-tsa',
        CrlRepository::settingKey(CrlIssuer::keyId($nearDer))] as $setting) {
        [$rootId,$tsaId] = $reset(); $before = $f->settings;
        $f->onSettingWrite = static function(string $key) use ($setting): void {
            if ($key === $setting) throw new RuntimeException('Injected setting write failure');
        };
        rejects(fn() => $rootRenewal->renewIfDue(time()));
        $f->onSettingWrite = null;
        check($f->settings === $before && $f->snapshot === null && count((new PublicTrustRepository($logs,$settings))->roots()) === 1,
            'Root renewal partially committed after setting write '.$setting);
    }
    [$rootId,$tsaId] = $reset();
    $before = $f->settings;
    $encryptionFails = true;
    rejects(fn() => $rootRenewal->renewIfDue(time()));
    $encryptionFails = false;
    check($f->settings === $before && $f->snapshot === null,'Failed encryption partially renewed root');
    $key = CrlRepository::settingKey(CrlIssuer::keyId($nearDer));
    foreach ([null,'invalid-json'] as $badCrl) {
        if ($badCrl === null) unset($f->settings[$key]); else $f->settings[$key] = $badCrl;
        $before = $f->settings; rejects(fn() => $rootRenewal->renewIfDue(time()));
        check($f->settings === $before,'Missing/corrupt CRL silently restarted its counter or ledger');
    }
    $crls->save($nearDer,$previousCrl);
    $futureCrl = (new CrlIssuer())->issue($near,18,time()+3600,$entries);
    $crls->save($nearDer,$futureCrl);
    $before = $f->settings; rejects(fn() => $rootRenewal->renewIfDue(time()));
    check($f->settings === $before,'Root renewal moved the CRL publication clock backward');
    $badCrl = (new CrlIssuer())->issue($near,PHP_INT_MAX,time(),$entries);
    $crls->save($nearDer,$badCrl);
    rejects(fn() => $rootRenewal->renewIfDue(time()));
    $crls->save($nearDer,$previousCrl);
    $source = $providers->source('builtin-tsa');
    $source['issuer_identity_id'] = str_repeat('f',32); $f->settings['tsa_source_builtin-tsa'] = json_encode($source);
    rejects(fn() => $rootRenewal->renewIfDue(time()));
    [$rootId,$tsaId] = $reset();
    foreach ($f->logs as $index=>$row) {
        if ($row['message'] === 'pki_identity' && $row['identity_id'] === $rootId) { $rootIndex = $index; }
    }
    $cipher = $f->logs[$rootIndex]['private_key_ciphertext'];
    $f->logs[$rootIndex]['private_key_ciphertext'] = 'redcap-v1:invalid';
    $before = $f->settings; rejects(fn() => $rootRenewal->renewIfDue(time()));
    check($f->settings === $before,'Corrupt root private key was silently replaced');
    $f->logs[$rootIndex]['private_key_ciphertext'] = $cipher;

    // Successful activation, lock interleavings, exact trust fields, fresh TSA and CRL continuity.
    $versions = DiagnosticSnapshot::currentVersions($identities);
    $snapshot = new DiagnosticSnapshot($cron);
    $snapshot->save(['passed'=>true,'checks'=>array_fill_keys(DiagnosticSnapshot::CHECKS,'passed'),'versions'=>$versions],$now-60);
    $cached = $f->settings['last-diagnostic-result'];
    $f->onLog = static function(string $message,array $values) use ($rootRenewal,&$held): void {
        if ($message === 'pki_identity' || in_array($message,['pki_crl_publication','root_certificate_renewal'],true)) {
            check(isset($held['pdf_sealer_initialize']) && !isset($held['pdf_sealer_issue_401']),'Root mutation used wrong locks');
            rejects(fn() => $rootRenewal->renewIfDue(time()));
        }
    };
    check($rootRenewal->renewIfDue(time()) === 'renewed','Root was not renewed');
    $f->onLog = null;
    $newRootId = $identities->activeId('root'); $newTsaId = $identities->activeId('tsa');
    $newRoot = $identities->find($newRootId); $newTsa = $identities->find($newTsaId);
    $a = (new Certificate())->fields($nearDer); $b = (new Certificate())->fields($newRoot->certificateDer);
    foreach (['subject','issuer','public_key'] as $field) check($a[$field] === $b[$field],'Root trust profile changed: '.$field);
    check((new Certificate())->extensions($nearDer) === (new Certificate())->extensions($newRoot->certificateDer), 'Root extensions changed');
    check($a['serial'] !== $b['serial'] && $b['not_after'] > $a['not_after']
        && $b['not_after']-$b['not_before'] === 3650*86400, 'Root serial/lifetime did not renew');
    check($newRootId !== $rootId && $newTsaId !== $tsaId && $identities->find($rootId) !== null
        && $identities->find($tsaId) !== null, 'Root/TSA history lost');
    check((new Certificate())->fields($newTsa->certificateDer)['public_key']
        !== (new Certificate())->fields($oldTsa->certificateDer)['public_key'], 'Root renewal reused TSA key');
    $newProvider = $providers->provider('builtin-ca'); $newSource = $providers->source('builtin-tsa');
    check($newProvider['issuer_identity_id'] === $newRootId && $newProvider['timestamp_source'] === 'builtin-tsa'
        && $newProvider['bb_fallback'] === false && $providers->requiresAssignment()
        && $newSource['identity_id'] === $newTsaId && $newSource['issuer_identity_id'] === $newRootId
        && $newSource['policy_oid'] === '2.25.123456789012345678901234567890', 'Renewal changed configuration policy');
    $currentCrl = $crls->load($newRoot->certificateDer);
    check($currentCrl['number'] === 18 && $currentCrl['entries'] === $entries
        && CrlIssuer::url('https://redcap.example/surveys/',$newRoot->certificateDer) === $originalUrl
        && $crls->load($nearDer) === $currentCrl, 'Same-key CRL URL/counter/ledger continuity lost');
    $public = new PublicTrustRepository($logs,$settings);
    check(count($public->roots()) === 2 && $public->activeRootId() === $newRootId, 'Renewed/public historical roots not available');
    check($f->settings['last-diagnostic-result'] === $cached
        && DiagnosticSnapshot::versionsChanged($snapshot->load(),DiagnosticSnapshot::currentVersions($identities)), 'Renewal rewrote diagnostic evidence or missed changed versions');
    $audit = end($f->logs);
    check($audit['message'] === 'root_certificate_renewal' && $audit['actor'] === 'system:cron'
        && $audit['previous_identity_id'] === $rootId && !str_contains(json_encode($audit),'PRIVATE KEY'), 'Unsafe root audit');
    $before = [$f->settings,$f->logs];
    check($rootRenewal->renewIfDue(time()) === 'skipped' && [$f->settings,$f->logs] === $before, 'Root renewal was not idempotent');

    // Independent OpenSSL accepts new leaves and refreshed CRL with only the original root as trust anchor.
    $rootPath = $f->createTempFile(); $tsaPath = $f->createTempFile(); $crlPath = $f->createTempFile();
    file_put_contents($rootPath,Certificate::derToPem($nearDer));
    file_put_contents($tsaPath,Certificate::derToPem($newTsa->certificateDer));
    file_put_contents($crlPath,base64_decode($currentCrl['der_b64'],true));
    check(runSealCommand(['openssl','verify','-CAfile',$rootPath,$tsaPath])[0] === 0, 'Original anchor did not accept new TSA');
    check(runSealCommand(['openssl','crl','-inform','DER','-in',$crlPath,'-noout','-verify','-CAfile',$rootPath])[0] === 0,
        'Original anchor did not verify refreshed CRL');
    check(runSealCommand(['openssl','verify','-crl_check','-CRLfile',$crlPath,'-CAfile',$rootPath,$tsaPath])[0] === 0,
        'Independent CRL validation failed after renewal');
    $publication = new CrlPublicationService($cron,$public,$identities,$protector,$crls,$configLock);
    check($publication->run(time())['published'] === 0, 'Daily CRL publication duplicated same-key generations');

    // Retirement and timestamp choices survive root maintenance; an external CA can keep selecting the internal TSA.
    [$rootId,$tsaId] = $reset();
    $external = $admin->register('External dependency',Certificate::derToPem($base->certificateDer),'builtin-tsa',true);
    $providers->saveTimestampPolicy('builtin-ca',null,false);
    $providers->saveRetired('builtin-ca',true);
    $externalBefore = $providers->provider($external);
    check($rootRenewal->renewIfDue(time()) === 'renewed' && $providers->isRetired('builtin-ca')
        && $providers->provider('builtin-ca')['timestamp_source'] === null && $providers->provider($external) === $externalBefore,
        'Root renewal changed retirement or external timestamp policy');

    // Root failures persist a retry instead of repeated generation on every hourly invocation.
    [$rootId,$tsaId] = $reset();
    $f->onLog = static function(string $message,array $values): void {
        if ($message === 'root_certificate_renewal') throw new RuntimeException('Root audit unavailable');
    };
    $retryTime = time(); $failed = $worker->run($retryTime);
    $f->onLog = null;
    check($failed['failed'] === 1 && $failed['retries']['root']['identity_id'] === $rootId
        && $failed['retries']['root']['retry_at'] === $retryTime+3600 && $identities->activeId('root') === $rootId,
        'Root failure lost prior activation or retry metadata');
    $before = count($f->logs);
    $waiting = $worker->run($retryTime+1);
    check($waiting['remaining'] === 1 && $waiting['renewed'] === 0 && count($f->logs) === $before,
        'Root retry backoff did not suppress repeated generation');
    $recovered = $worker->run($retryTime+3601);
    check($recovered['renewed'] === 2 && $recovered['failed'] === 0 && $recovered['retries'] === []
        && $identities->activeId('root') !== $rootId,'Root retry did not recover with an atomic pair');

    // Worker resumes dependent projects in bounded batches; old/new issuers can coexist in B-T seals.
    [$rootId,$tsaId] = $reset();
    $original = [];
    for ($pid=401;$pid<=406;++$pid) {
        $uuid = $issuer->newProjectUuid(); $leaf = $issuer->createProject('Root Renewal Test',$uuid,$near);
        $id = $identities->append('project',$leaf,$uuid,'builtin-ca',$rootId);
        $bindings->bindUuid($pid,$uuid,'builtin-ca'); $bindings->activate($pid,$uuid,$id);
        $original[$pid] = [$id,$uuid,$leaf];
    }
    $f->enabled = [401,402,403,404,405,406];
    $sample = PkiDiagnosticService::samplePdf();
    $oldPdf = (new PdfSealBuilder())->seal($sample,$original[406][2]->certificateDer,$original[406][2]->privateKey(),[$nearDer],time());
    $result = $worker->run(time());
    check($result['renewed'] === 7 && $result['remaining'] === 1 && $result['failed'] === 0,'Root pair/project batch counts incorrect');
    $newRootId = $identities->activeId('root'); $newTsa = $identities->find($identities->activeId('tsa'));
    check($bindings->find(406)->identityId === $original[406][0],'Unprocessed project was prematurely rebound');
    $path = $f->createTempFile(); file_put_contents($path,$sample); $f->projectId = 406;
    $sealed = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['terminal_action_reserved_for_core' => false, 'document_type'=>'econsent','project_id'=>406,'record_id'=>'1','event_id'=>1]);
    check($sealed->isModified(),'Old project/new TSA coexistence failed');
    (new SampleSealVerifier())->verify($sample,file_get_contents($path),$original[406][2]->certificateDer,$newTsa->certificateDer);
    check($worker->run(time())['renewed'] === 1,'Dependent project catch-up did not resume');
    $current = $identities->find($bindings->find(406)->identityId);
    check($current->issuerId === $newRootId && $bindings->find(406)->uuid === $original[406][1],'Project UUID/issuer lost');
    file_put_contents($path,$sample);
    $sealed = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['terminal_action_reserved_for_core' => false, 'document_type'=>'econsent','project_id'=>406,'record_id'=>'2','event_id'=>1]);
    check($sealed->isModified(),'New project/new TSA finalizer failed');
    (new SampleSealVerifier())->verify($sample,file_get_contents($path),$current->certificateDer,$newTsa->certificateDer);
    (new SampleSealVerifier())->verify($sample,$oldPdf,$original[406][2]->certificateDer);

    // Actual expired root/TSA recover after downtime without a clock change or new anchor key.
    [$rootId,$tsaId] = $reset();
    $expiredRootDer = maintenanceCertificate($base->certificateDer,$base->privateKey(),$now-1000*86400,$now-86400);
    $expiredRoot = new GeneratedIdentity($expiredRootDer,$base->privateKeyPem());
    $expiredTsaDer = maintenanceCertificate($oldTsa->certificateDer,$base->privateKey(),$now-800*86400,$now-100*86400);
    $expiredRootId = $identities->append('root',$expiredRoot);
    $expiredTsaId = $identities->append('tsa',new GeneratedIdentity($expiredTsaDer,$oldTsa->privateKeyPem()));
    $identities->activate('root',$expiredRootId); $identities->activate('tsa',$expiredTsaId);
    $provider = $providers->provider('builtin-ca'); $provider['issuer_identity_id']=$expiredRootId;
    $f->settings['ca_provider_builtin-ca']=json_encode($provider);
    $source = $providers->source('builtin-tsa'); $source['identity_id']=$expiredTsaId; $source['issuer_identity_id']=$expiredRootId;
    $f->settings['tsa_source_builtin-tsa']=json_encode($source);
    $expiredCrl = (new CrlIssuer())->issue($expiredRoot,23,$now-2*86400,[['serial_hex'=>'abcdef','revoked_at'=>$now-3*86400,'reason'=>4]]);
    $crls->save($expiredRootDer,$expiredCrl);
    check($rootRenewal->renewIfDue(time()) === 'renewed' && $health->inspect(time())->status === DE\RUB\PDFSealerExternalModule\Pki\PkiHealth::Ready,
        'Expired root/TSA did not recover');
    $recoveredRoot = $identities->find($identities->activeId('root'));
    check(CrlIssuer::keyId($recoveredRoot->certificateDer) === CrlIssuer::keyId($expiredRootDer)
        && $crls->load($recoveredRoot->certificateDer)['number'] === 24,'Expired issuer changed key or restarted CRL counter');
    check($held === [] && $f->snapshot === null,'Root renewal leaked a lock/transaction');
    echo 'Root renewal: exact names/key/profile, fresh TSA, atomic rollback, CRL URL/counter/entries, original-anchor OpenSSL trust, public history, policy/retirement, bounded project catch-up, B-T coexistence and expired-root recovery passed on PHP '.PHP_VERSION.".\n";
} finally {
    $f->onLog = null; $f->onSettingWrite = null; $cron->failQuery = null;
    foreach ($f->paths as $path) { @unlink($path); }
}
