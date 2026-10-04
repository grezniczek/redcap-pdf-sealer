<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{ProjectRenewalService,ProjectIdentityService,ProjectEnrollmentService,ProjectIssueLock,PkiInitializationLock,PkiHealthService,ProviderAdminService,GeneratedIdentity,ExpiryInventory};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Diagnostics\{PkiDiagnosticService,SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;

// Disposable real crypto and fake transactional persistence; no REDCap bootstrap or live writes.
require __DIR__ . '/project_enrollment.php';
require_once (getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase') . '/Classes/PdfFinalization/PdfFinalizeResult.php';
if (!class_exists('ExternalModules\\AbstractExternalModule')) {
    eval('namespace ExternalModules; class AbstractExternalModule { public object $framework; }');
}
require dirname(__DIR__) . '/PDFSealerExternalModule.php';
final class RenewalFramework {
    public bool $superuser = true;
    public ?int $projectId = null;
    public ?string $failQuery = null;
    public function __construct(private object $inner) {}
    public function __call(string $method, array $args): mixed { return $this->inner->$method(...$args); }
    public function isSuperUser(): bool { return $this->superuser; }
    public function getProjectId(): ?int { return $this->projectId; }
    public function tt(string $key): string { return $key; }
    public function query(string $sql, array $params): bool {
        if ($this->failQuery === $sql) return false;
        return $this->inner->query($sql,$params);
    }
}
final class REDCap {
    public static array $events = [];
    public static function logEvent(...$args): void { self::$events[] = $args; }
}
$held = []; $lockCalls = [];
$lockQuery = static function(string $sql, array $params) use (&$held,&$lockCalls): Rows {
    $lockCalls[] = [$sql,$params[0]];
    if (str_contains($sql,'GET_LOCK')) {
        if (isset($held[$params[0]])) return new Rows([[0]]);
        $held[$params[0]] = true;
    } else {
        check(isset($held[$params[0]]),'Release without lock'); unset($held[$params[0]]);
    }
    return new Rows([[1]]);
};
function db_query(string $sql, array $params, mixed ...$rest): Rows {
    global $f,$lockQuery;
    check(($rest[2] ?? null) === true, 'Renewal read/lock did not use primary connection');
    if (str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK')) return $lockQuery($sql,$params);
    if (str_contains($sql,'SELECT s.value')) {
        $value = $f->settings[$params[1]] ?? null;
        return new Rows($value === null ? [] : [['value'=>$value,'type'=>'string']]);
    }
    return $f->queryLogs($sql,$params);
}
$f->allowIdentityReads = true;
// The reused fixture intentionally ends with a corrupt external chain; restore it.
foreach ($providers->externalIds() as $providerId) {
    $record = json_decode($f->settings['ca_provider_'.$providerId],true);
    if ($record['name'] === 'Test provider') {
        $record['chain'] = $validator->validate($fullPem);
        $f->settings['ca_provider_'.$providerId] = json_encode($record);
    }
}
$f->enabled = [101,102,103,104,105];
$framework = new RenewalFramework($f);
$projectLock = new ProjectIssueLock($lockQuery); $configLock = new PkiInitializationLock($lockQuery);
$health = new PkiHealthService($identities,$protector);
$projects = new ProjectIdentityService($bindings,$identities,$protector,$issuer,$health,$projectLock,$configLock);
$enrollment = new ProjectEnrollmentService($f,$bindings,$providers,$protector,$projectLock,$settings,$identities,$configLock);
$renewal = new ProjectRenewalService($framework,$bindings,$identities,$enrollment,$projects,$health,$projectLock,$configLock);
try {
    $rootId = $identities->append('root',$root);
    $tsa = $issuer->createTsa('External CA Test',$root); $tsaId = $identities->append('tsa',$tsa);
    $identities->activate('root',$rootId); $identities->activate('tsa',$tsaId);
    $builtin = $providers->provider('builtin-ca'); $builtin['issuer_identity_id'] = $rootId;
    $f->settings['ca_provider_builtin-ca'] = json_encode($builtin);
    $source = $providers->source('builtin-tsa'); $source['identity_id'] = $tsaId; $source['issuer_identity_id'] = $rootId;
    $f->settings['tsa_source_builtin-tsa'] = json_encode($source);
    $old = $projects->getOrIssue(103); $original = $bindings->find(103);
    $sample = PkiDiagnosticService::samplePdf();
    $oldPdf = (new DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder())->seal(
        $sample,$old->certificateDer,$old->privateKey($protector),[$root->certificateDer],time());
    $before = [$f->settings,$f->logs,$decryptCalls];
    $lockCalls = [];
    $view = $renewal->preview(103);
    check([$f->settings,$f->logs,$decryptCalls] === $before, 'Review wrote state or decrypted a key');
    check($view['certificate']['fingerprint'] === hash('sha256',$old->certificateDer)
        && $view['uuid'] === $original->uuid && !str_contains(json_encode($view),'ciphertext'), 'Bad public review');
    check(array_column($lockCalls,1) === ['pdf_sealer_issue_103','pdf_sealer_initialize','pdf_sealer_initialize','pdf_sealer_issue_103'], 'Wrong lock order/release');
    foreach ([101,102,104,999] as $pid) rejects(fn() => $renewal->preview($pid));
    rejects(fn() => $renewal->renew(103,str_repeat('0',64)));
    check([$f->settings,$f->logs] === array_slice($before,0,2), 'Rejected review changed state');

    // Failed transaction entry/commit, identity, binding and audit writes roll back.
    foreach (['START TRANSACTION','COMMIT'] as $sql) {
        $framework->failQuery = $sql; $before = [$f->settings,$f->logs];
        rejects(fn() => $renewal->renew(103,$view['review_hash'])); $framework->failQuery = null;
        check([$f->settings,$f->logs] === $before && $f->snapshot === null, 'Failed transaction changed identity');
    }
    foreach (['pki_identity','project_identity_binding','project_certificate_renewal'] as $failure) {
        $before = [$f->settings,$f->logs];
        $f->onLog = static function(string $message) use ($failure): void { if ($message === $failure) throw new RuntimeException('Test write failure'); };
        rejects(fn() => $renewal->renew(103,$view['review_hash'])); $f->onLog = null;
        check([$f->settings,$f->logs] === $before, 'Failed renewal write partially committed');
    }
    $before = [$f->settings,$f->logs]; $encryptionFails = true;
    rejects(fn() => $renewal->renew(103,$view['review_hash'])); $encryptionFails = false;
    check([$f->settings,$f->logs] === $before, 'Failed encryption replaced signer');

    // Preview is public-only; unusable issuer keys are detected again at issuance.
    $rootRow = null;
    foreach ($f->logs as $index=>$row) if (($row['identity_id'] ?? null) === $rootId && $row['message'] === 'pki_identity') $rootRow = $index;
    $rootCipher = $f->logs[$rootRow]['private_key_ciphertext'];
    $f->logs[$rootRow]['private_key_ciphertext'] = 'redcap-v1:invalid';
    check($renewal->preview(103)['review_hash'] === $view['review_hash'], 'Review required an issuer private key');
    $before = [$f->settings,$f->logs]; rejects(fn() => $renewal->renew(103,$view['review_hash']));
    check([$f->settings,$f->logs] === $before, 'Issuer failure replaced signer');
    $f->logs[$rootRow]['private_key_ciphertext'] = $rootCipher;
    $otherRootId = $identities->append('root',$root);
    $builtin['issuer_identity_id'] = $otherRootId; $f->settings['ca_provider_builtin-ca'] = json_encode($builtin);
    rejects(fn() => $renewal->renew(103,$view['review_hash']));
    $builtin['issuer_identity_id'] = $rootId; $f->settings['ca_provider_builtin-ca'] = json_encode($builtin);

    // Retirement, disablement, transitions and corrupt/pending enrollment block renewal.
    $f->settings['ca_provider_retired_builtin-ca'] = 'true';
    rejects(fn() => $renewal->preview(103)); rejects(fn() => $renewal->renew(103,$view['review_hash']));
    unset($f->settings['ca_provider_retired_builtin-ca']);
    $f->enabled = [101,102]; rejects(fn() => $renewal->renew(103,$view['review_hash'])); $f->enabled = [101,102,103,104,105];
    $transition = $bindings->startTransition(103,$second);
    $pending = $enrollment->generate(103);
    $before = [$f->settings,$f->logs]; rejects(fn() => $renewal->preview(103)); rejects(fn() => $renewal->renew(103,$view['review_hash']));
    check([$f->settings,$f->logs] === $before && $projects->getOrIssue(103)->id === $old->id, 'Renewal destroyed pending work or active signer');
    $enrollment->cancel(103,$pending['pending']['id']); $bindings->cancelTransition(103,$transition);
    $f->settings['pending_enrollment_103'] = 'corrupt';
    rejects(fn() => $renewal->preview(103)); unset($f->settings['pending_enrollment_103']);

    $admin = new ProviderAdminService($f,$providers,$bindings,$validator,$configLock,$projectLock);
    $interleavings = 0;
    $f->onLog = static function(string $message) use ($renewal,$admin,$projects,&$interleavings): void {
        if (!in_array($message,['pki_identity','project_identity_binding','project_certificate_renewal'],true)) return;
        rejects(fn() => $renewal->preview(103));
        rejects(fn() => $admin->previewRetirement('builtin-ca'));
        rejects(fn() => $projects->getOrIssue(103));
        $interleavings++;
    };
    $result = $renewal->renew(103,$view['review_hash']); $f->onLog = null;
    $current = $projects->getOrIssue(103); $binding = $bindings->find(103);
    check($current->id === $result['identity_id'] && $current->id !== $old->id && $binding->uuid === $original->uuid
        && $binding->providerId === $original->providerId && $identities->find($old->id) !== null, 'Renewal lost UUID/provider/history');
    check(openssl_pkey_get_details($current->privateKey($protector))['key'] !== openssl_pkey_get_details($old->privateKey($protector))['key'], 'Renewal reused old key');
    check($interleavings === 3, 'Renewal mutation locks were not exercised');
    $audit = end($f->logs);
    check($audit['message'] === 'project_certificate_renewal' && $audit['previous_identity_id'] === $old->id
        && $audit['identity_id'] === $current->id && $audit['actor'] === 'admin' && !str_contains(json_encode($audit),'PRIVATE KEY'), 'Bad renewal audit');
    $before = [$f->settings,$f->logs]; rejects(fn() => $renewal->renew(103,$view['review_hash']));
    check([$f->settings,$f->logs] === $before, 'Repeated confirmation issued another identity');
    $inventory = (new ExpiryInventory($f,$logs,$settings))->collect();
    check(isset($inventory[$current->id]) && !isset($inventory[$old->id]), 'Expiry scan did not follow latest signer');

    // A genuine expired certificate can be replaced without decrypting its key for review.
    $config = $f->createTempFile();
    file_put_contents($config,"[req]\ndistinguished_name=dn\n[dn]\n[leaf]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.5.5.7.3.36\n[root]\nbasicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid:always,issuer\n");
    $expiredKey = $old->privateKey($protector);
    $csr = openssl_csr_new(['O'=>'External CA Test','CN'=>'REDCap Project '.$original->uuid],$expiredKey,['config'=>$config]);
    $expired = openssl_csr_sign($csr,Certificate::derToPem($root->certificateDer),$root->privateKey(),0,['config'=>$config,'x509_extensions'=>'leaf'],9001);
    openssl_x509_export($expired,$expiredPem);
    $expiredId = $identities->append('project',new GeneratedIdentity(Certificate::pemToDer($expiredPem),$old->asGeneratedIdentity($protector)->privateKeyPem()),$original->uuid,'builtin-ca',$rootId);
    $bindings->replace(103,$original->uuid,$current->id,$expiredId); sleep(1);
    rejects(fn() => $projects->getOrIssue(103));
    $view = $renewal->preview(103); check($view['certificate']['valid_until'] < time(), 'Expired fixture is not expired');
    $renewal->renew(103,$view['review_hash']);
    $current = $projects->getOrIssue(103);
    check($current->id !== $expiredId && $identities->find($expiredId) !== null, 'Expired certificate was not renewed');

    // A valid project leaf cannot make issuance under an expired root acceptable.
    $rootKey = $root->privateKey();
    $rootCsr = openssl_csr_new(['O'=>'External CA Test','CN'=>'REDCap PDF Sealer Root CA'],$rootKey,['config'=>$config]);
    $expiredRoot = openssl_csr_sign($rootCsr,null,$rootKey,0,['config'=>$config,'x509_extensions'=>'root'],9002);
    openssl_x509_export($expiredRoot,$expiredRootPem);
    $expiredRootId = $identities->append('root',new GeneratedIdentity(Certificate::pemToDer($expiredRootPem),$root->privateKeyPem()));
    $view = $renewal->preview(103);
    $builtin['issuer_identity_id']=$expiredRootId; $f->settings['ca_provider_builtin-ca']=json_encode($builtin); sleep(1);
    $before = [$f->settings,$f->logs];
    rejects(fn() => $renewal->preview(103)); rejects(fn() => $renewal->renew(103,$view['review_hash']));
    check([$f->settings,$f->logs] === $before,'Expired issuer renewal changed state');
    $builtin['issuer_identity_id']=$rootId; $f->settings['ca_provider_builtin-ca']=json_encode($builtin);

    // Drive real authenticated module dispatch, including one successful renewal.
    $module = new DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule(); $module->framework = $framework;
    foreach (['preview_project_renewal','renew_project_certificate'] as $action) {
        foreach ([null,[],['pid'=>'103'],['pid'=>0]] as $payload) {
            check($module->redcap_module_ajax($action,$payload,null) === ['ok'=>false,'message'=>'pki_invalid_request'], 'Invalid renewal request accepted');
        }
        foreach ([[false,null,null],[true,103,null],[true,null,103]] as [$super,$ambient,$context]) {
            $framework->superuser=$super; $framework->projectId=$ambient;
            rejects(fn() => $module->redcap_module_ajax($action,[], $context));
        }
        $framework->superuser=true; $framework->projectId=null;
    }
    foreach ([['pid'=>103],['pid'=>103,'review_hash'=>null],['pid'=>103,'review_hash'=>'bad']] as $payload)
        check($module->redcap_module_ajax('renew_project_certificate',$payload,null)['message'] === 'pki_invalid_request','Invalid renewal hash accepted');
    $before = [$f->settings,$f->logs,$decryptCalls];
    $review = $module->redcap_module_ajax('preview_project_renewal',['pid'=>103,'extra'=>'ignored'],null);
    check($review['ok'] && [$f->settings,$f->logs,$decryptCalls] === $before,'AJAX review failed or mutated state');
    $response = $module->redcap_module_ajax('renew_project_certificate',['pid'=>103,'review_hash'=>$review['review_hash']],null);
    check($response['ok'] && $response['identity_id'] !== $current->id,'AJAX renewal failed');
    check($module->redcap_module_ajax('renew_project_certificate',['pid'=>103,'review_hash'=>$review['review_hash']],null)
        === ['ok'=>false,'message'=>'renewal_failed'],'AJAX replay not rejected safely');
    $current = $projects->getOrIssue(103);
    // Both finalizer profiles use the latest certificate; old PDFs remain verifiable.
    $path = $f->createTempFile(); $f->projectId = 103;
    $verifier = new SampleSealVerifier();
    foreach ([null,'builtin-tsa'] as $timestampSource) {
        $builtin['timestamp_source']=$timestampSource; $builtin['bb_fallback']=false; $f->settings['ca_provider_builtin-ca']=json_encode($builtin);
        file_put_contents($path,$sample);
        $result = (new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],['terminal_action_reserved_for_core' => false, 'document_type'=>'econsent','project_id'=>103,'record_id'=>'2','event_id'=>9]);
        check($result->isModified() && $result->isTerminal(),'Renewed signer finalizer failed');
        $verifier->verify($sample,file_get_contents($path),$current->certificateDer,$timestampSource === null ? null : $tsa->certificateDer);
    }
    $verifier->verify($sample,$oldPdf,$old->certificateDer);
    check($held === [] && $f->snapshot === null,'Renewal leaked locks/transaction');
    echo "Project renewal: public review, expired signer, fresh key, UUID/provider/history, stale/replayed reviews, pending work, retirement/issuer failures, rollback, locks, authenticated AJAX, expiry and B-B/B-T finalizer passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
