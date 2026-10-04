<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{ProviderTransitionService,ProviderAdminService,ProjectEnrollmentService,ProjectIdentityService,ProjectIssueLock,PkiInitializationLock,PkiHealthService,ExpiryInventory,CaProviderRetired,ProviderTransitionPending};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Diagnostics\{PkiDiagnosticService,SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
require __DIR__ . '/external_activation.php';

$held = [];
$lockQuery = static function(string $sql, array $params) use (&$held): Rows {
    if (str_contains($sql,'GET_LOCK')) {
        if (isset($held[$params[0]])) return new Rows([[0]]);
        $held[$params[0]] = true;
    } else { check(isset($held[$params[0]]), 'Release without lock'); unset($held[$params[0]]); }
    return new Rows([[1]]);
};
$projectLock = new ProjectIssueLock($lockQuery); $configLock = new PkiInitializationLock($lockQuery);
$enrollment = new ProjectEnrollmentService($f,$bindings,$providers,$protector,$projectLock,$settings,$identities,$configLock);
$projects = new ProjectIdentityService($bindings,$identities,$protector,$issuer,new PkiHealthService($identities,$protector),$projectLock,$configLock);
$transitions = new ProviderTransitionService($f,$providers,$bindings,$enrollment,$projects,$projectLock,$configLock);
$admin = new ProviderAdminService($f,$providers,$bindings,$validator,$configLock,$projectLock);
$start = static fn(int $pid,string $target): string => $transitions->start($pid,$target,$transitions->preview($pid)['review_hash']);
$cancel = static fn(int $pid) => $transitions->cancel($pid,$transitions->preview($pid)['review_hash']);
$retire = static fn(string $id,bool $retired) => $admin->setRetired($id,$retired,$admin->previewRetirement($id)['review_hash'],false);
$f->enabled = [101,102,103,104,105,106,107];
try {
    // Point built-in issuance to the independent TSA's real root fixture.
    $builtin = $providers->provider('builtin-ca'); $builtin['issuer_identity_id'] = $tsaRootId;
    $builtin['timestamp_source'] = null; $f->settings['ca_provider_builtin-ca'] = json_encode($builtin);
    $identities->activate('root',$tsaRootId); $identities->activate('tsa',$tsaId);
    $original = $bindings->find(104); $old = $identities->find($original->identityId);
    $before = [$f->settings,$f->logs,$decryptCalls];
    $view = $transitions->preview(104);
    check([$f->settings,$f->logs,$decryptCalls] === $before && $view['provider_id'] === $providerId, 'Preview mutated/decrypted material');
    rejects(fn() => $transitions->preview(999)); rejects(fn() => $transitions->preview(107));
    rejects(fn() => $start(104,$providerId));
    rejects(fn() => $transitions->start(104,'builtin-ca',str_repeat('0',64)));
    // Existing CSR must be canceled explicitly; no silent key destruction.
    $csr = $enrollment->generate(104); $pendingBefore = $f->settings['pending_enrollment_104'];
    rejects(fn() => $transitions->start(104,'builtin-ca',$view['review_hash']));
    rejects(fn() => $start(104,'builtin-ca'));
    check($f->settings['pending_enrollment_104'] === $pendingBefore, 'Rejected transition destroyed pending CSR');
    $enrollment->cancel(104,$csr['pending']['id']);
    $before = [$f->settings,$f->logs];
    // Audit failure after replacement identity and binding writes must roll back everything.
    $f->onLog = static function(string $message,array $data): void {
        if ($message === 'provider_transition' && $data['action'] === 'activate') throw new RuntimeException('Test audit failure');
    };
    rejects(fn() => $start(104,'builtin-ca')); $f->onLog = null;
    check([$f->settings,$f->logs] === $before, 'Builtin replacement audit failure partially committed');
    check($start(104,'builtin-ca') === 'activated', 'External to builtin did not activate');
    $internal = $projects->getOrIssue(104); $internalBinding = $bindings->find(104);
    check($internalBinding->uuid === $original->uuid && $internal->providerId === 'builtin-ca'
        && $internal->id !== $old->id && $identities->find($old->id) !== null && $internalBinding->pendingProviderId === null, 'Builtin transition lost UUID/history or failed');
    rejects(fn() => $transitions->start(104,$second,$view['review_hash']));
    $retirementBefore = $admin->previewRetirement($second);
    check($start(104,$second) === 'pending', 'Builtin to external did not prepare enrollment');
    $pendingBinding = $bindings->find(104);
    check($pendingBinding->providerId === 'builtin-ca' && $pendingBinding->identityId === $internal->id
        && $pendingBinding->pendingProviderId === $second, 'Preparation changed current signer/provider');
    rejects(fn() => $admin->setRetired($second,true,$retirementBefore['review_hash'],false));
    $usage = $bindings->providerUsage($second);
    $entry = array_values(array_filter($usage,fn($u) => $u['pid'] === 104))[0];
    check($entry['identity_id'] === null && $entry['transition_id'] === $pendingBinding->transitionId, 'Retirement omitted pending target');
    $pending = $enrollment->generate(104); $requestId = $pending['pending']['id'];
    $before = [$f->settings,$f->logs];
    rejects(fn() => $start(104,$providerId));
    check([$f->settings,$f->logs] === $before, 'Second transition changed current pending work');
    $targetPem = Certificate::derToPem(DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::certificateDer($providers->provider($second)['chain'][0]));
    $config = $f->createTempFile();
    file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n[leaf]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.5.5.7.3.36\n");
    $signTarget = static function(array $pending) use ($config,$targetPem,$root): string {
        static $serial=6000;
        $cert = openssl_csr_sign(base64_decode($pending['base64'],true),$targetPem,$root->privateKey(),180,['config'=>$config,'x509_extensions'=>'leaf'],++$serial);
        check($cert !== false && openssl_x509_export($cert,$pem), 'Target certificate signing failed'); return $pem;
    };
    $newLeaf = $signTarget($pending); $review = $enrollment->reviewCertificate(104,$requestId,$newLeaf);
    // Cancellation is transactional even after deleting the pending key and appending binding.
    $before = [$f->settings,$f->logs];
    $f->onLog = static function(string $message,array $data): void {
        if ($message === 'provider_transition' && $data['action'] === 'cancel') throw new RuntimeException('Test cancel audit failure');
    };
    rejects(fn() => $cancel(104)); $f->onLog = null;
    check([$f->settings,$f->logs] === $before, 'Failed cancellation lost pending key/assignment');
    $cancel(104);
    check($bindings->find(104) == $internalBinding && $enrollment->inspect(104) === null, 'Cancellation changed active signer or retained pending key');
    rejects(fn() => $enrollment->activateCertificate(104,$requestId,$newLeaf,$review['review_hash'],$internal->id));
    $start(104,$second); $pending = $enrollment->generate(104); $requestId = $pending['pending']['id']; $newLeaf = $signTarget($pending);
    $review = $enrollment->reviewCertificate(104,$requestId,$newLeaf);
    $oldCancellation = $transitions->preview(104);
    // Retired target blocks generation/activation while old signer remains usable.
    $retire($second,true);
    foreach ([fn() => $enrollment->generate(104), fn() => $enrollment->activateCertificate(104,$requestId,$newLeaf,$review['review_hash'],$internal->id)] as $work) {
        try { $work(); throw new LogicException('Retired target accepted'); } catch (CaProviderRetired) {}
    }
    check($projects->getOrIssue(104)->id === $internal->id && $enrollment->download(104,$requestId) === $pending, 'Retired target affected old signer/CSR');
    $retire($second,false);
    // Actual finalizer retains old timestamp policy until activation (B-B -> B-T).
    $sample = PkiDiagnosticService::samplePdf(); $path = $f->createTempFile();
    $seal = static function(string $signer, ?string $tsaDer) use ($f,$path,$sample): void {
        file_put_contents($path,$sample);
        $result = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['terminal_action_reserved_for_core' => false, 'document_type'=>'econsent','project_id'=>104,'record_id'=>'2','event_id'=>9]);
        check($result->isModified(), 'Transition finalizer failed');
        (new SampleSealVerifier())->verify($sample,file_get_contents($path),$signer,$tsaDer);
    };
    $seal($internal->certificateDer,null);
    $before = [$f->settings,$f->logs]; $f->failEnrollment = true;
    rejects(fn() => $enrollment->activateCertificate(104,$requestId,$newLeaf,$review['review_hash'],$internal->id));
    $f->failEnrollment = false;
    check([$f->settings,$f->logs] === $before, 'External activation audit failure lost prior provider/signer/pending key');
    $interleavings = 0;
    $f->onLog = static function(string $message) use ($admin,$second,$transitions,&$interleavings): void {
        if (!in_array($message,['pki_identity','project_identity_binding','project_enrollment'],true)) return;
        rejects(fn() => $admin->previewRetirement($second));
        rejects(fn() => $transitions->preview(104));
        $interleavings++;
    };
    $enrollment->activateCertificate(104,$requestId,$newLeaf,$review['review_hash'],$internal->id); $f->onLog = null;
    check($interleavings >= 3, 'Activation locks not exercised');
    $external = $projects->getOrIssue(104);
    check($external->providerId === $second && $bindings->find(104)->uuid === $original->uuid
        && $bindings->find(104)->pendingProviderId === null && $identities->find($internal->id) !== null, 'External activation lost UUID/history');
    $seal($external->certificateDer,$tsa->certificateDer);
    rejects(fn() => $transitions->cancel(104,$oldCancellation['review_hash']));
    $items = (new ExpiryInventory($f,$logs,$settings))->collect();
    check(isset($items[$external->id]) && !isset($items[$internal->id]) && !isset($items[$old->id]), 'Expiry selected historical signer');
    // External -> external preserves signer and supports cancellation even with retired source/target.
    $retire($second,true); $start(104,$providerId);
    check($projects->getOrIssue(104)->id === $external->id, 'Transition from retired CA stopped active signer');
    $enrollment->generate(104); $retire($providerId,true); $cancel(104);
    check($projects->getOrIssue(104)->id === $external->id && $bindings->find(104)->pendingProviderId === null, 'Canceling retired target lost current signer');
    rejects(fn() => $start(104,$providerId)); $retire($providerId,false);
    $signIntermediate = static function(array $pending) use ($config,$caPem,$caKey): string {
        static $serial=7000;
        $cert = openssl_csr_sign(base64_decode($pending['base64'],true),$caPem,$caKey,180,['config'=>$config,'x509_extensions'=>'leaf'],++$serial);
        check($cert !== false && openssl_x509_export($cert,$pem), 'Intermediate certificate signing failed'); return $pem;
    };
    $start(104,$providerId); $pending = $enrollment->generate(104); $leaf = $signIntermediate($pending);
    $review = $enrollment->reviewCertificate(104,$pending['pending']['id'],$leaf);
    $enrollment->activateCertificate(104,$pending['pending']['id'],$leaf,$review['review_hash'],$external->id);
    $returned = $projects->getOrIssue(104);
    check($returned->providerId === $providerId && $returned->id !== $original->identityId
        && $bindings->find(104)->uuid === $original->uuid && $identities->find($external->id) !== null, 'External-to-external activation reused a historical identity or lost UUID');
    $seal($returned->certificateDer,$tsa->certificateDer);
    // A project without a signer must not issue using its old builtin assignment while transitioning.
    $bindings->bindUuid(105,$issuer->newProjectUuid(),'builtin-ca'); $start(105,$providerId);
    $before = [$f->settings,$f->logs];
    try { $projects->getOrIssue(105); throw new LogicException('Issued old signer during transition'); } catch (ProviderTransitionPending) {}
    check($projects->inspect(105)['state'] === 'transition_pending' && [$f->settings,$f->logs] === $before, 'Pending first signer changed PKI/status');
    $f->projectId = 105; file_put_contents($path,$sample);
    $result = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['terminal_action_reserved_for_core' => false, 'document_type'=>'econsent','project_id'=>105,'record_id'=>'1','event_id'=>9]);
    check($result->isFailed() && $result->getErrorCode() === 'PROVIDER_TRANSITION_PENDING' && file_get_contents($path) === $sample, 'Wrong first-seal contract');
    $logged = end(REDCap::$events); check($logged[0] === 'PDF seal failed: provider transition pending' && $logged[3] === '1' && $logged[4] === 9, 'Missing failure/context logging');
    $cancel(105); check($projects->getOrIssue(105)->providerId === 'builtin-ca', 'Canceling no-signer transition did not restore original issuance');
    // Both immediate and enrollment transitions support projects that never had a signer.
    $bindings->bindUuid(106,$issuer->newProjectUuid(),$second);
    check($start(106,'builtin-ca') === 'activated' && $projects->getOrIssue(106)->providerId === 'builtin-ca', 'No-signer external-to-builtin transition failed');
    $bindings->bindUuid(107,$issuer->newProjectUuid(),'builtin-ca'); $start(107,$providerId);
    $pending = $enrollment->generate(107); $leaf = $signIntermediate($pending);
    $review = $enrollment->reviewCertificate(107,$pending['pending']['id'],$leaf);
    $enrollment->activateCertificate(107,$pending['pending']['id'],$leaf,$review['review_hash'],null);
    check($projects->getOrIssue(107)->providerId === $providerId, 'No-signer builtin-to-external activation failed');
    check($held === [], 'Transition locks leaked');
    echo "Provider transitions: all directions, UUID/history retention, CSR cancellation/rollback, retired targets, stale reviews, locks, atomic activation, and actual B-B/B-T policy switch passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
