<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{ProjectRevocationService,ProjectRevocationRepository,ProjectRevoked,ProjectRenewalService,
    ProjectIdentityService,CertificateIssuer,CrlRepository,CrlIssuer,CrlPublicationService,PublicTrustRepository,BuiltinMaintenanceService};
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService,AlarmRepository,AlarmLock};
use DE\RUB\PDFSealerExternalModule\Diagnostics\{PkiDiagnosticService,SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Pdf\{PdfFinalizeService,PdfSealBuilder};

require __DIR__ . '/project_renewal.php';
require __DIR__ . '/support/pdf_seal_checks.php';
require __DIR__ . '/support/certificate_validity.php';

if (!defined('APP_PATH_SURVEY_FULL')) define('APP_PATH_SURVEY_FULL','https://redcap.example/surveys/');
$revocationIssuer = new CertificateIssuer([$f,'createTempFile'],[\PDFSealerTests\CertificateSerials::class,'reserve'],APP_PATH_SURVEY_FULL);
$baseRoot = $revocationIssuer->createRoot('Revocation Test');
$baseTsa = $revocationIssuer->createTsa('Revocation Test',$baseRoot);
$projects = new ProjectIdentityService($bindings,$identities,$protector,$revocationIssuer,$health,$projectLock,$configLock);
$renewal = new ProjectRenewalService($framework,$bindings,$identities,$enrollment,$projects,$health,$projectLock,$configLock);
$revocations = $identities->revocations();
$crls = new CrlRepository($framework,$settings);
$public = new PublicTrustRepository($logs,$settings);
$publication = new CrlPublicationService($framework,$public,$identities,$protector,$crls,$configLock);
$service = new ProjectRevocationService($framework,$bindings,$identities,$revocations,$renewal,$publication,$crls,$projectLock,$configLock);
$alarmLock = new AlarmLock($lockQuery);
$alarms = new AdminAlarmService($framework,new AlarmRepository($framework),$alarmLock,static fn(): bool => true);
$worker = new BuiltinMaintenanceService($framework,$identities,$bindings,$protector,$revocationIssuer,$health,$renewal,
    $configLock,$alarmLock,$alarms,$settings);
$reset = static function() use ($f,$framework,$identities,$providers,$baseRoot,$baseTsa,$crls): void {
    $f->settings=[]; $f->logs=[]; $f->enabled=[501]; $f->projectId=501; $f->onLog=null; $f->onSettingWrite=null;
    $framework->superuser=true; $framework->projectId=null; $framework->failQuery=null;
    $rootId=$identities->append('root',$baseRoot); $tsaId=$identities->append('tsa',$baseTsa);
    $identities->activate('root',$rootId); $identities->activate('tsa',$tsaId); $providers->initialize($rootId,$tsaId);
    $f->settings['organization']='Revocation Test';
    $crls->save($baseRoot->certificateDer,(new CrlIssuer())->issue($baseRoot,10,time(),
        [['serial_hex'=>'aabbccddeeff','revoked_at'=>time()-60,'reason'=>4]]));
};
$install = static function(int $pid) use ($bindings,$identities,$revocationIssuer,$baseRoot): object {
    $uuid=$revocationIssuer->newProjectUuid();
    $leaf=$revocationIssuer->createProject('Revocation Test',$uuid,$baseRoot);
    $id=$identities->append('project',$leaf,$uuid,'builtin-ca',$identities->activeId('root'));
    $bindings->bindUuid($pid,$uuid,'builtin-ca'); $bindings->activate($pid,$uuid,$id);
    return $identities->find($id);
};
$sample=PkiDiagnosticService::samplePdf();
try {
    $reset(); $old=$install(501);
    $before=[$f->settings,$f->logs,$decryptCalls]; $view=$service->preview(501);
    check([$f->settings,$f->logs,$decryptCalls]===$before && !$view['revoked'],'Revocation review wrote or decrypted private material');
    foreach (['bad',str_repeat('0',64)] as $hash) rejects(fn()=>$service->revoke(501,$hash,'superseded'));
    foreach (['','hold','1',1,null] as $reason) rejects(fn()=>$service->revoke(501,$view['review_hash'],$reason));
    check([$f->settings,$f->logs,$decryptCalls]===$before,'Invalid review/reason changed state');
    foreach (['START TRANSACTION','COMMIT',ProjectRevocationRepository::MESSAGE] as $failure) {
        $framework->failQuery=$failure;
        $f->onLog=static function(string $message,array $values) use ($failure): void {
            if ($message===$failure) throw new RuntimeException('Block audit unavailable');
        };
        rejects(fn()=>$service->revoke(501,$view['review_hash'],'superseded'));
        $f->onLog=null; $framework->failQuery=null;
        check([$f->settings,$f->logs,$decryptCalls]===$before && $f->snapshot===null,'Rejected revocation partially committed');
    }

    // Accepted superseded revocation publishes immediately and replaces in an independent transaction.
    $f->onLog=static function(string $message,array $values) use (&$held): void {
        if ($message===ProjectRevocationRepository::MESSAGE) {
            check(isset($held['pdf_sealer_issue_501'],$held['pdf_sealer_initialize']),'Revocation used wrong locks');
            check(!str_contains(json_encode($values),'PRIVATE KEY') && $values['actor']==='admin','Unsafe or missing revocation actor');
        }
    };
    $result=$service->revoke(501,$view['review_hash'],'superseded'); $f->onLog=null;
    check($result['crl_published'] && $result['replacement']==='renewed','Immediate publication/replacement failed');
    $current=$projects->getOrIssue(501); $entry=$revocations->find($old);
    check($current->id!==$old->id && $current->projectUuid===$old->projectUuid
        && $current->providerId===$old->providerId && $entry['reason']==='4'
        && $identities->find($old->id)!==null,'Revocation recovery lost identity history/UUID or reason');
    rejects(fn()=>$projects->acceptSeal(501,$old,static fn()=>true));
    rejects(fn()=>$service->revoke(501,$view['review_hash'],'superseded'));
    $record=$crls->load($baseRoot->certificateDer);
    check($record['number']===11 && count($record['entries'])===2 && $record['entries'][0]['serial_hex']==='aabbccddeeff',
        'Prompt publication erased entries or failed to increase counter');
    $serial=strtolower(ltrim(openssl_x509_parse(Certificate::derToPem($old->certificateDer))['serialNumberHex'],'0'));
    check($record['entries'][1]['serial_hex']===$serial && $record['entries'][1]['reason']===4,'Published wrong revoked serial/reason');
    $caPath=$f->createTempFile(); $leafPath=$f->createTempFile(); $crlPath=$f->createTempFile();
    file_put_contents($caPath,Certificate::derToPem($baseRoot->certificateDer));
    file_put_contents($leafPath,Certificate::derToPem($old->certificateDer));
    file_put_contents($crlPath,base64_decode($record['der_b64'],true));
    [$code,$output]=runSealCommand(['openssl','verify','-crl_check','-CRLfile',$crlPath,'-CAfile',$caPath,$leafPath]);
    check($code!==0 && str_contains($output,'certificate revoked'),'OpenSSL did not reject the revoked leaf');
    file_put_contents($leafPath,Certificate::derToPem($current->certificateDer));
    check(runSealCommand(['openssl','verify','-crl_check','-CRLfile',$crlPath,'-CAfile',$caPath,$leafPath])[0]===0,
        'OpenSSL rejected the new signer against the same CRL');
    $before=[$f->settings,$f->logs];
    check($publication->run(time(),true)['published']===0 && [$f->settings,$f->logs]===$before,'Prompt publication repeated a completed list');
    $path=$f->createTempFile();
    foreach ([null,'builtin-tsa'] as $source) {
        $providers->saveTimestampPolicy('builtin-ca',$source,false);
        file_put_contents($path,$sample);
        $sealed=(new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],
            ['document_type'=>'econsent','project_id'=>501,'record_id'=>'1','event_id'=>1]);
        check($sealed->isModified(),'Recovery did not restore finalization');
        (new SampleSealVerifier())->verify($sample,file_get_contents($path),$current->certificateDer,$source===null?null:$baseTsa->certificateDer);
    }

    // CRL write/audit failure cannot undo a committed block; fresh-key recovery may still succeed.
    foreach (['setting','audit'] as $failure) {
        $reset(); $old=$install(501); $view=$service->preview(501);
        $prior=$crls->load($baseRoot->certificateDer);
        $key=CrlRepository::settingKey(CrlIssuer::keyId($baseRoot->certificateDer));
        $f->onSettingWrite=$failure==='setting'?static function(string $written) use ($key): void {
            if($written===$key)throw new RuntimeException('CRL storage unavailable');
        }:null;
        $f->onLog=$failure==='audit'?static function(string $message,array $values): void {
            if($message==='pki_crl_publication')throw new RuntimeException('CRL audit unavailable');
        }:null;
        $result=$service->revoke(501,$view['review_hash'],'compromise');
        $f->onSettingWrite=null; $f->onLog=null;
        check(!$result['crl_published'] && $result['replacement']==='renewed' && $revocations->find($old)['reason']==='1'
            && $crls->load($baseRoot->certificateDer)===$prior,'CRL failure undid block or overwrote publication');
        $recovered=$worker->run(time());
        check($recovered['failed']===0 && $crls->load($baseRoot->certificateDer)['number']===11
            && count($crls->load($baseRoot->certificateDer)['entries'])===2,'Hourly worker did not publish pending revocation');
    }

    // Replacement failure keeps the original blocked; the real finalizer fails before writing or first-use issuance.
    $reset(); $old=$install(501); $view=$service->preview(501);
    $encryptionFails=true;
    $result=$service->revoke(501,$view['review_hash'],'compromise');
    $encryptionFails=false;
    check($result['crl_published'] && $result['replacement']==='pending' && $bindings->find(501)->identityId===$old->id,
        'Failed replacement undid revocation or changed binding');
    check($projects->inspect(501)['state']==='revoked' && $service->preview(501)['revoked'],'Revoked status not visible');
    rejects(fn()=>$projects->getOrIssue(501));
    file_put_contents($path,$sample); $before=[$f->settings,count($f->logs)];
    $blocked=(new PdfFinalizeService($f))->finalize($path,['id'=>'seal'],
        ['document_type'=>'econsent','project_id'=>501,'record_id'=>'2','event_id'=>1]);
    check($blocked->getErrorCode()==='PROJECT_CERTIFICATE_REVOKED' && file_get_contents($path)===$sample
        && $bindings->find(501)->identityId===$old->id,'Blocked finalizer wrote a seal or silently issued a replacement');
    $recovered=$worker->run(time());
    check($recovered['renewed']===1 && $recovered['failed']===0 && $projects->inspect(501)['state']==='ready',
        'Cron did not recover a young revoked certificate');

    // Normal renewal remains a replacement without revocation.
    $reset(); $old=$install(501); $view=$renewal->preview(501);
    $renewal->renew(501,$view['review_hash']);
    check($revocations->find($old)===null && count($crls->load($baseRoot->certificateDer)['entries'])===1,'Normal renewal revoked the old signer');

    // Private-key damage, disabled projects and pending work cannot prevent a block.
    $reset(); $old=$install(501);
    foreach($f->logs as &$row) if(($row['identity_id']??null)===$old->id && $row['message']==='pki_identity') $row['private_key_ciphertext']='redcap-v1:invalid';
    unset($row);
    $before=$decryptCalls; $view=$service->preview(501);
    check($decryptCalls===$before,'Damaged key was decrypted during revocation review');
    $result=$service->revoke(501,$view['review_hash'],'compromise');
    check($result['replacement']==='renewed' && $projects->inspect(501)['state']==='ready','Recovery tried to use the revoked private key');
    foreach(['disabled','retired','pending'] as $condition) {
        $reset(); $old=$install(501);
        if($condition==='disabled')$f->enabled=[];
        if($condition==='retired') { $providers->saveAssignmentPolicy(true); $providers->saveRetired('builtin-ca',true); }
        if($condition==='pending') {
            $target=$admin->register('Pending CA',$fullPem,'builtin-tsa',false);
            $bindings->startTransition(501,$target); $enrollment->generate(501);
        }
        $view=$service->preview(501); $pending=$f->settings['pending_enrollment_501']??null;
        $result=$service->revoke(501,$view['review_hash'],'superseded');
        check($revocations->find($old)!==null && $result['crl_published'],'Administrative state prevented revocation');
        if($condition==='disabled') check($result['replacement']==='renewed' && $f->enabled===[],'Disabled project was enabled or not recovered');
        else check($result['replacement']==='deferred' && $projects->inspect(501)['state']==='revoked'
            && ($f->settings['pending_enrollment_501']??null)===$pending,'Retirement/pending work was overwritten');
    }

    // A new block invalidates ordinary backoff for the same identity.
    $reset(); $old=$install(501);
    $f->settings[BuiltinMaintenanceService::SETTING]=json_encode([
        'completed_at'=>time(),'status'=>'failed','renewed'=>0,'deferred'=>0,'failed'=>1,'remaining'=>0,'items'=>[],
        'root_identity_id'=>$identities->activeId('root'),
        'retries'=>['project-501'=>['identity_id'=>$old->id,'attempts'=>6,'retry_at'=>time()+86400]]]);
    $view=$service->preview(501); $encryptionFails=true;
    $service->revoke(501,$view['review_hash'],'compromise'); $encryptionFails=false;
    check($worker->run(time())['renewed']===1,'New revocation inherited obsolete ordinary renewal backoff');

    // Revoked projects have priority over ordinary due work; bounded runs resume without duplicate replacement.
    $reset();
    $ordinary=$install(500);
    $short=maintenanceCertificate($ordinary->certificateDer,$baseRoot->privateKey(),
        openssl_x509_parse(Certificate::derToPem($ordinary->certificateDer))['validFrom_time_t'],time()+30*86400);
    $shortId=$identities->append('project',new DE\RUB\PDFSealerExternalModule\Pki\GeneratedIdentity($short,
        $ordinary->asGeneratedIdentity($protector)->privateKeyPem()),$ordinary->projectUuid,'builtin-ca',$ordinary->issuerId);
    $bindings->replace(500,$ordinary->projectUuid,$ordinary->id,$shortId);
    $queued=[];
    for($pid=501;$pid<=506;++$pid) {
        $identity=$install($pid); $queued[$pid]=$identity->id;
        $projectLock->withLock($pid,fn()=>$configLock->withLock(function()use($pid,$identity,$framework,$revocations,$baseRoot):void{
            $framework->query('START TRANSACTION',[]);
            $revocations->append($pid,$identity,$baseRoot->certificateDer,4,time());
            $framework->query('COMMIT',[]);
        }));
    }
    $f->enabled=[500,506]; $first=$worker->run(time());
    check($first['renewed']===5 && $first['remaining']===2 && $bindings->find(500)->identityId===$shortId
        && $bindings->find(506)->identityId!==$queued[506],'Bounded worker did not prioritize revoked signers');
    check($worker->run(time())['renewed']===2 && count($crls->load($baseRoot->certificateDer)['entries'])===7,
        'Revoked batch did not resume or lost CRL entries');

    // A corrupt issuer key cannot stop the block, and publication/replacement resume once the key is restored.
    $reset(); $old=$install(501);
    foreach($f->logs as $index=>$row) if($row['message']==='pki_identity' && ($row['identity_id']??null)===$identities->activeId('root'))$rootIndex=$index;
    $cipher=$f->logs[$rootIndex]['private_key_ciphertext']; $f->logs[$rootIndex]['private_key_ciphertext']='redcap-v1:invalid';
    $view=$service->preview(501); $result=$service->revoke(501,$view['review_hash'],'compromise');
    check(!$result['crl_published'] && $result['replacement']==='pending' && $revocations->find($old)!==null,
        'Issuer-key damage prevented a durable block or reported successful recovery');
    $f->logs[$rootIndex]['private_key_ciphertext']=$cipher;
    check($worker->run(time())['renewed']===1 && count($crls->load($baseRoot->certificateDer)['entries'])===2,
        'Recovery did not resume after issuer-key repair');

    // Missing CRL must not restart its counter; valid-looking ledger tampering fails closed.
    $reset(); $old=$install(501); $view=$service->preview(501);
    $key=CrlRepository::settingKey(CrlIssuer::keyId($baseRoot->certificateDer)); unset($f->settings[$key]);
    $encryptionFails=true; $result=$service->revoke(501,$view['review_hash'],'superseded'); $encryptionFails=false;
    check(!$result['crl_published'] && $revocations->find($old)!==null && !isset($f->settings[$key]),
        'Revocation restarted a missing CRL counter or lost its block');
    foreach($f->logs as $index=>$row) if($row['message']===ProjectRevocationRepository::MESSAGE)$blockIndex=$index;
    $original=$f->logs[$blockIndex];
    foreach(['serial_hex'=>'abcdef','certificate_sha256'=>str_repeat('e',64),'issuer_key_id'=>str_repeat('f',64),'reason'=>'5'] as $field=>$bad) {
        $f->logs[$blockIndex]=$original; $f->logs[$blockIndex][$field]=$bad;
        rejects(fn()=>$projects->getOrIssue(501));
        check($projects->inspect(501)['state']!=='ready','Corrupt block made a signer ready');
    }
    $f->logs[$blockIndex]=$original;

    // Same-key root renewal publishes a pending block together with its fresh root/TSA pair.
    $reset(); $old=$install(501); $view=$service->preview(501);
    $key=CrlRepository::settingKey(CrlIssuer::keyId($baseRoot->certificateDer));
    $f->onSettingWrite=static function(string $written) use($key):void{
        if($written===$key)throw new RuntimeException('Publication temporarily unavailable');
    };
    $result=$service->revoke(501,$view['review_hash'],'compromise'); $f->onSettingWrite=null;
    check(!$result['crl_published'],'Fixture published its pending entry');
    $nearDer=maintenanceCertificate($baseRoot->certificateDer,$baseRoot->privateKey(),
        openssl_x509_parse(Certificate::derToPem($baseRoot->certificateDer))['validFrom_time_t'],
        time()+DE\RUB\PDFSealerExternalModule\Pki\RootRenewalService::WINDOW-1);
    $nearId=$identities->append('root',new DE\RUB\PDFSealerExternalModule\Pki\GeneratedIdentity($nearDer,$baseRoot->privateKeyPem()));
    $identities->activate('root',$nearId);
    $provider=$providers->provider('builtin-ca'); $provider['issuer_identity_id']=$nearId;
    $source=$providers->source('builtin-tsa'); $source['issuer_identity_id']=$nearId;
    $f->settings['ca_provider_builtin-ca']=json_encode($provider); $f->settings['tsa_source_builtin-tsa']=json_encode($source);
    $rootRenewal=new DE\RUB\PDFSealerExternalModule\Pki\RootRenewalService(
        $framework,$identities,$protector,$revocationIssuer,$health,$crls,$configLock);
    check($rootRenewal->renewIfDue(time())==='renewed'
        && count($crls->load($baseRoot->certificateDer)['entries'])===2
        && $crls->load($baseRoot->certificateDer)['entries'][1]['serial_hex']===$revocations->find($old)['serial_hex'],
        'Root renewal lost an unpublished project revocation');

    // Actual finalizer captures the old identity, then an overlapping revocation commits before acceptance.
    $reset(); $old=$install(501); $view=$service->preview(501);
    $encryptionFails=true;
    $raceFramework=new class($f,$held,$service,$view) {
        public bool $captured=false,$fired=false;
        public function __construct(private object $inner,private array &$locks,private object $service,private array $view) {}
        public function __call(string $name,array $args): mixed {return $this->inner->$name(...$args);}
        public function getQueryLogsSql(string $sql): string {
            if(str_contains($sql,'revoked_at'))$this->captured=true;
            if($this->captured && !$this->fired && !isset($this->locks['pdf_sealer_issue_501'])) {
                $this->fired=true;
                $this->service->revoke(501,$this->view['review_hash'],'compromise');
            }
            return $this->inner->getQueryLogsSql($sql);
        }
    };
    file_put_contents($path,$sample);
    $race=(new PdfFinalizeService($raceFramework))->finalize($path,['id'=>'seal'],
        ['document_type'=>'econsent','project_id'=>501,'record_id'=>'3','event_id'=>1]);
    $encryptionFails=false;
    check($raceFramework->fired && $race->getErrorCode()==='PROJECT_CERTIFICATE_REVOKED' && file_get_contents($path)===$sample,
        'Captured revoked signer published after the block boundary');
    // If acceptance holds the lock first, revocation must wait rather than retroactively editing an accepted copy.
    $reset(); $old=$install(501); $view=$service->preview(501);
    $accepted=$projects->acceptSeal(501,$old,function()use($service,$view):bool{
        rejects(fn()=>$service->revoke(501,$view['review_hash'],'superseded')); return true;
    });
    check($accepted && $revocations->find($old)===null,'Acceptance boundary did not serialize competing revocation');

    // Real authenticated AJAX: malformed payloads and unauthorized/project contexts are rejected.
    $module=new DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule(); $module->framework=$framework;
    foreach(['preview_project_revocation','revoke_project_certificate'] as $action) {
        foreach([null,[],['pid'=>'501'],['pid'=>0]] as $payload)
            check($module->redcap_module_ajax($action,$payload,null)['message']==='pki_invalid_request','Invalid revocation AJAX accepted');
        foreach([[false,null,null],[true,501,null],[true,null,501]] as [$super,$ambient,$context]) {
            $framework->superuser=$super; $framework->projectId=$ambient;
            rejects(fn()=>$module->redcap_module_ajax($action,[], $context));
        }
        $framework->superuser=true; $framework->projectId=null;
    }
    $before=[$f->settings,$f->logs,$decryptCalls];
    $review=$module->redcap_module_ajax('preview_project_revocation',['pid'=>501,'extra'=>'ignored'],null);
    check($review['ok'] && [$f->settings,$f->logs,$decryptCalls]===$before,'AJAX review wrote or decrypted state');
    $response=$module->redcap_module_ajax('revoke_project_certificate',
        ['pid'=>501,'review_hash'=>$review['review_hash'],'reason'=>'superseded'],null);
    check($response['ok'] && $response['crl_published'] && $response['replacement']==='renewed','AJAX revocation failed');
    check(!$module->redcap_module_ajax('revoke_project_certificate',
        ['pid'=>501,'review_hash'=>$review['review_hash'],'reason'=>'superseded'],null)['ok'],'Stale/replayed AJAX revoked the new signer');
    check($held===[] && $f->snapshot===null,'Revocation leaked locks or transaction');
    echo 'Project revocation: public review, stale/replayed requests, block rollback, immediate CRL/replacement, durable failure recovery, OpenSSL revoked/new leaf status, B-B/B-T, disabled/retired/pending preservation, damaged-key recovery, finalizer races and authenticated AJAX passed on PHP '.PHP_VERSION.".\n";
} finally {
    $f->onLog=null; $f->onSettingWrite=null; $framework->failQuery=null; $encryptionFails=false;
    foreach($f->paths as $file) { @unlink($file); }
}
