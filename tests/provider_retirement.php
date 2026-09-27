<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{ProviderAdminService,ProviderRepository,ProjectIssueLock,PkiInitializationLock,ProjectEnrollmentService,ProjectIdentityService,PkiHealthService,ExpiryInventory,CaProviderRetired};
use DE\RUB\PDFSealerExternalModule\Diagnostics\{PkiDiagnosticService,SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
// Real certificates/crypto; isolated fake database and no live project changes.
require __DIR__ . '/external_activation.php';

$held = [];
$lockQuery = static function(string $sql, array $params) use (&$held): Rows {
    $name = $params[0];
    if (str_contains($sql,'GET_LOCK')) {
        if (isset($held[$name])) return new Rows([[0]]);
        $held[$name] = true;
    } else { check(isset($held[$name]), 'Release without lock'); unset($held[$name]); }
    return new Rows([[1]]);
};
$configurationLock = new PkiInitializationLock($lockQuery);
$projectLock = new ProjectIssueLock($lockQuery);
$admin = new ProviderAdminService($f,$providers,$bindings,$validator,$configurationLock,$projectLock);
$enrollment = new ProjectEnrollmentService($f,$bindings,$providers,$protector,$projectLock,$settings,$identities,$configurationLock);
$projects = new ProjectIdentityService($bindings,$identities,$protector,$issuer,new PkiHealthService($identities,$protector),$projectLock,$configurationLock);
$retire = static function(string $id, bool $value, bool $gate=false) use ($admin): void {
    $admin->setRetired($id,$value,$admin->previewRetirement($id)['review_hash'],$gate);
};
$assertRetired = static function(callable $work): void {
    try { $work(); } catch (CaProviderRetired) { return; }
    throw new RuntimeException('Expected typed retired-CA failure');
};
try {
    $activeId = $bindings->find(104)->identityId;
    $active = $identities->find($activeId);
    $f->allowIdentityReads = false;
    $before = [$f->settings,$f->logs,$decryptCalls];
    $preview = $admin->previewRetirement($providerId);
    check($preview['projects'] === [['pid'=>104,'identity_id'=>$activeId,'enrollment_id'=>null]], 'Wrong initial impact');
    check([$f->settings,$f->logs,$decryptCalls] === $before, 'Preview wrote or decrypted private material');
    $f->allowIdentityReads = true;
    // Preparing an enrollment after review invalidates that review.
    $pending = $enrollment->generate(104);
    $pendingId = $pending['pending']['id'];
    rejects(fn() => $admin->setRetired($providerId,true,$preview['review_hash'],false));
    $preview = $admin->previewRetirement($providerId);
    check($preview['projects'][0]['enrollment_id'] === $pendingId, 'Pending replacement missing from impact');
    $before = [$f->settings,$f->logs];
    $f->failAudit = true;
    rejects(fn() => $retire($providerId,true));
    $f->failAudit = false;
    check([$f->settings,$f->logs] === $before, 'Failed retirement did not roll back');
    // Reuse the earlier valid leaf: retirement must be checked before parsing/key validation.
    $retire($providerId,true);
    $before = [$f->settings,$f->logs];
    $assertRetired(fn() => $enrollment->generate(104));
    $assertRetired(fn() => $enrollment->reviewCertificate(104,$pendingId,$replacementPem));
    $assertRetired(fn() => $enrollment->activateCertificate(104,$pendingId,$replacementPem,str_repeat('0',64),$activeId));
    $f->enabled[] = 105;
    $assertRetired(fn() => $admin->assign(105,$providerId));
    check($enrollment->download(104,$pendingId) === $pending, 'Retirement lost pending CSR/key');
    check($projects->getOrIssue(104)->id === $activeId && $projects->inspect(104)['state'] === 'ready', 'Retirement blocked existing signer');
    check([$f->settings,$f->logs] === $before, 'Retired requests wrote data');
    $public = array_values(array_filter($providers->publicCertificates(),fn($c) => $c['provider_id'] === $providerId));
    check(count($public) === 2 && $public[0]['retired'] && $public[0]['der'] === $active->issuerChain[0], 'Retired public chain lost/changed');
    $inventory = (new ExpiryInventory($f,$logs,$settings))->collect();
    foreach ($active->issuerChain as $der) check(isset($inventory[hash('sha256',$der)]), 'Active retired issuer not monitored');
    // Cancellation remains available; subsequent stale reactivation review must be rejected.
    $preview = $admin->previewRetirement($providerId);
    $enrollment->cancel(104,$pendingId);
    rejects(fn() => $admin->setRetired($providerId,false,$preview['review_hash'],false));
    check($enrollment->inspect(104) === null && $projects->getOrIssue(104)->id === $activeId, 'Cancellation affected active signer');
    $retire($providerId,false);
    check(!$providers->isRetired($providerId), 'Reactivation failed');

    // Retiring an unused CA removes its unique intermediate from expiry alarms, not public downloads.
    $unused = $providers->externalIds()[0];
    $unusedHash = $providers->provider($unused)['chain'][0]['sha256'];
    $retire($unused,true);
    check(!isset((new ExpiryInventory($f,$logs,$settings))->collect()[$unusedHash]), 'Unused retired CA still monitored');
    check(count(array_filter($providers->publicCertificates(),fn($c) => $c['provider_id'] === $unused)) === 2, 'Unused retired public chain removed');

    // Deterministic interleavings: admin retirement cannot complete inside a protected mutation.
    $interleavings = 0;
    $f->onLog = static function(string $message) use ($retire,$providerId,&$interleavings): void {
        if (!in_array($message,['project_enrollment','project_identity_binding','pki_identity'],true)) return;
        rejects(fn() => $retire($providerId,true));
        $interleavings++;
    };
    $newPending = $enrollment->generate(104);
    // external_activation.php removes temp configs on exit; recreate the leaf-only test config.
    $cfg2 = $f->createTempFile();
    file_put_contents($cfg2,"[req]\ndistinguished_name=dn\n[dn]\n[leaf]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.5.5.7.3.36\n");
    $cert = openssl_csr_sign(base64_decode($newPending['base64'],true),$caPem,$caKey,180,['config'=>$cfg2,'x509_extensions'=>'leaf'],5555);
    check($cert !== false && openssl_x509_export($cert,$newLeaf), 'Replacement test signing failed');
    $review = $enrollment->reviewCertificate(104,$newPending['pending']['id'],$newLeaf);
    $enrollment->activateCertificate(104,$newPending['pending']['id'],$newLeaf,$review['review_hash'],$activeId);
    $admin->assign(105,$providerId);
    check($interleavings >= 5 && !$providers->isRetired($providerId), 'Retirement raced a mutation');

    // Prepare built-in active and pending bindings. The built-in TSA stays independent of retirement.
    $builtin = $providers->provider('builtin-ca'); $builtin['issuer_identity_id'] = $tsaRootId;
    $f->settings['ca_provider_builtin-ca'] = json_encode($builtin);
    $identities->activate('root',$tsaRootId); $identities->activate('tsa',$tsaId);
    $f->settings['organization'] = 'Independent TSA';
    $providers->saveAssignmentPolicy(false);
    $beforeIssuance = $interleavings;
    $internal = $projects->getOrIssue(106);
    check($interleavings > $beforeIssuance, 'Internal issuance lock not exercised');
    $f->onLog = null;
    $bindings->bindUuid(107,$issuer->newProjectUuid(),'builtin-ca');
    $preview = $admin->previewRetirement('builtin-ca');
    $before = [$f->settings,$f->logs];
    rejects(fn() => $admin->setRetired('builtin-ca',true,$preview['review_hash'],false));
    $f->failAudit = true;
    rejects(fn() => $admin->setRetired('builtin-ca',true,$preview['review_hash'],true));
    $f->failAudit = false;
    check([$f->settings,$f->logs] === $before, 'Default retirement partially saved gate/state');
    $admin->setRetired('builtin-ca',true,$preview['review_hash'],true);
    check($providers->requiresAssignment() && $providers->defaultId() === 'builtin-ca', 'Retirement silently replaced default or missed gate');
    rejects(fn() => $admin->saveAssignmentPolicy(false));
    $assertRetired(fn() => $projects->getOrIssue(107));
    check($projects->inspect(107)['state'] === 'ca_retired', 'Bound project lacks retirement status');
    check($projects->getOrIssue(106)->id === $internal->id, 'Built-in active signer blocked');
    $diagnostic = new PkiDiagnosticService($identities,$protector,$issuer,$settings,$configurationLock);
    $before = [$f->settings,$f->logs];
    $checks = $diagnostic->run()['checks'];
    check($checks['signer'] === 'failed' && $checks['bb'] === 'skipped' && $checks['bt'] === 'skipped'
        && $checks['tsa'] === 'passed' && $checks['timestamp'] === 'passed', 'Diagnostic issued under retired CA or stopped TSA');
    check([$f->settings,$f->logs] === $before, 'Retired diagnostic issued/stored material');
    $inventory = (new ExpiryInventory($f,$logs,$settings))->collect();
    check(isset($inventory[$tsaRootId],$inventory[$tsaId],$inventory[$internal->id]), 'Retirement lost existing TSA/signer dependencies');
    $retire($providerId,true);
    // Actual B-T finalization works with BOTH project CA and built-in TSA's CA retired.
    $current = $projects->getOrIssue(104);
    $path = $f->createTempFile(); $sample = PkiDiagnosticService::samplePdf(); file_put_contents($path,$sample);
    $result = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['project_id'=>104,'document_type'=>'econsent','record_id'=>'2','event_id'=>9]);
    check($result->isModified(), 'Retired CA blocked existing B-T sealing');
    (new SampleSealVerifier())->verify($sample,file_get_contents($path),$current->certificateDer,$tsa->certificateDer);
    // Failed first issuance keeps PDF bytes and gives minimal project Logging with context.
    $f->projectId = 107; file_put_contents($path,$sample);
    $result = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['project_id'=>107,'document_type'=>'econsent','record_id'=>'3','event_id'=>9]);
    check($result->isFailed() && $result->getErrorCode() === 'CA_PROVIDER_RETIRED' && file_get_contents($path) === $sample, 'Wrong retired first-seal result');
    $logged = end(REDCap::$events);
    check($logged[0] === 'PDF seal failed: CA provider retired' && $logged[3] === '3' && $logged[4] === 9, 'Missing retirement log context');
    $retire('builtin-ca',false);
    check($providers->requiresAssignment(), 'Reactivation disabled the gate');
    check($projects->getOrIssue(107)->role === 'project', 'Reactivation did not allow bound issuance');
    $f->settings['ca_provider_retired_builtin-ca'] = 'invalid';
    rejects(fn() => $providers->isRetired('builtin-ca'));
    check($held === [], 'Lifecycle locks leaked');
    echo "CA retirement: public impact/stale reviews, rollback/default gate, pending preservation/cancellation, lock interleavings, active B-T sealing, diagnostic policy, expiry, and reactivation passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
