<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{RootRevocationService,RootRevocationRepository,RootRenewalService,
    ProjectRenewalService,ProjectIdentityService,CertificateIssuer,CrlRepository,CrlIssuer,CrlPublicationService,
    PublicTrustRepository,BuiltinMaintenanceService,GeneratedIdentity,PkiHealth};
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService,AlarmRepository,AlarmLock};
use DE\RUB\PDFSealerExternalModule\Diagnostics\{PkiDiagnosticService,SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Timestamp\{InternalTimestampProvider,InternalTsaService};
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;

require __DIR__.'/project_renewal.php';
require __DIR__.'/support/pdf_seal_checks.php';
if(!defined('APP_PATH_SURVEY_FULL'))define('APP_PATH_SURVEY_FULL','https://redcap.example/surveys/');
$rootIssuer=new CertificateIssuer([$f,'createTempFile'],[\PDFSealerTests\CertificateSerials::class,'reserve'],APP_PATH_SURVEY_FULL);
$baseRoot=$rootIssuer->createRoot('Root Revocation Test');$baseTsa=$rootIssuer->createTsa('Root Revocation Test',$baseRoot);
$projects=new ProjectIdentityService($bindings,$identities,$protector,$rootIssuer,$health,$projectLock,$configLock);
$projectRenewal=new ProjectRenewalService($framework,$bindings,$identities,$enrollment,$projects,$health,$projectLock,$configLock);
$crls=new CrlRepository($framework,$settings);$public=new PublicTrustRepository($logs,$settings);
$publication=new CrlPublicationService($framework,$public,$identities,$protector,$crls,$configLock);
$rootRenewal=new RootRenewalService($framework,$identities,$protector,$rootIssuer,$health,$crls,$configLock);
$alarmLock=new AlarmLock($lockQuery);
$worker=new BuiltinMaintenanceService($framework,$identities,$bindings,$protector,$rootIssuer,$health,$projectRenewal,$configLock,$alarmLock,
    new AdminAlarmService($framework,new AlarmRepository($framework),$alarmLock,static fn():bool=>true),$settings);
$service=new RootRevocationService($framework,$identities,$rootRenewal,$publication,$crls,$worker,$health,$configLock);
$reset=static function()use($f,$framework,$identities,$providers,$baseRoot,$baseTsa,$crls):void{
    $f->settings=[];$f->logs=[];$f->enabled=[501];$f->projectId=501;$f->onLog=null;$f->onSettingWrite=null;
    $framework->superuser=true;$framework->projectId=null;$framework->failQuery=null;
    $rootId=$identities->append('root',$baseRoot);$tsaId=$identities->append('tsa',$baseTsa);
    $identities->activate('root',$rootId);$identities->activate('tsa',$tsaId);$providers->initialize($rootId,$tsaId);
    $providers->saveTimestampPolicy('builtin-ca','builtin-tsa',false);
    $f->settings['organization']='Root Revocation Test';
    $crls->save($baseRoot->certificateDer,(new CrlIssuer())->issue($baseRoot,10,time(),
        [['serial_hex'=>'aabbccddeeff','revoked_at'=>time()-60,'reason'=>4]]));
};
$sample=PkiDiagnosticService::samplePdf();$path=$f->createTempFile();
$finalize=static function(object $target,int $pid=501)use($path,$sample){
    $f=$GLOBALS['f'];$f->projectId=$pid;file_put_contents($path,$sample);
    return(new PdfFinalizeService($target))->finalize($path,['id'=>'seal'],['document_type'=>'econsent','project_id'=>$pid,'record_id'=>'1']);
};
try{
    $reset();$old=$projects->getOrIssue(501);$view=$service->preview();$before=[$f->settings,$f->logs,$decryptCalls];
    check($service->preview()===$view && [$f->settings,$f->logs,$decryptCalls]===$before,'Root review wrote/decrypted private material');
    foreach(['',str_repeat('0',64)]as$hash)rejects(fn()=>$service->revoke($hash,'superseded'));
    foreach(['','hold','1',1,null]as$reason)rejects(fn()=>$service->revoke($view['review_hash'],$reason));
    foreach(['START TRANSACTION','COMMIT',RootRevocationRepository::MESSAGE]as$failure){
        $framework->failQuery=$failure;$f->onLog=static function(string $message)use($failure){if($message===$failure)throw new RuntimeException('Block audit failed');};
        rejects(fn()=>$service->revoke($view['review_hash'],'compromise'));$framework->failQuery=null;$f->onLog=null;
        check([$f->settings,$f->logs,$decryptCalls]===$before && $f->snapshot===null,'Rejected root block partially committed');
    }
    $result=$service->renew($view['review_hash']);$renewed=$identities->find($identities->activeId('root'));
    check($result['replacement']==='renewed' && $renewed->id!==$view['identity_id']
        && CrlIssuer::keyId($renewed->certificateDer)===CrlIssuer::keyId($baseRoot->certificateDer)
        && $identities->rootRevocations()->find($baseRoot->certificateDer)===null
        && $crls->load($baseRoot->certificateDer)['number']===11,'Manual same-key renewal changed trust or revoked');
    check($projects->getOrIssue(501)->id===$old->id,'Normal renewal blocked an old valid signer');
    rejects(fn()=>$service->renew($view['review_hash']));

    foreach(['superseded'=>4,'compromise'=>2]as$reason=>$code){
        $reset();$f->enabled=[501,502,503,504,505];$oldProjects=[];
        foreach(range(501,506)as$pid)$oldProjects[$pid]=$projects->getOrIssue($pid);
        // A historical same-key root version must be blocked too, including a project it issued.
        $alias=$rootIssuer->renewRoot($baseRoot);$aliasId=$identities->append('root',$alias);
        $uuid=$oldProjects[501]->projectUuid;$leaf=$rootIssuer->createProject('Root Revocation Test',$uuid,$alias);
        $aliasLeafId=$identities->append('project',$leaf,$uuid,'builtin-ca',$aliasId);
        $bindings->replace(501,$uuid,$oldProjects[501]->id,$aliasLeafId);$oldProjects[501]=$identities->find($aliasLeafId);
        $captured=$health->captureTimestamp($providers->source('builtin-tsa'),time());
        $policies=[$providers->provider('builtin-ca'),$providers->source('builtin-tsa')['policy_oid']];
        $view=$service->preview();$result=$service->revoke($view['review_hash'],$reason);
        $newRoot=$identities->find($identities->activeId('root'));$newTsa=$identities->find($identities->activeId('tsa'));
        check($result['crl_published'] && $result['replacement']==='renewed'
            && $identities->rootRevocations()->find($alias->certificateDer)['reason']===(string)$code
            && CrlIssuer::keyId($newRoot->certificateDer)!==CrlIssuer::keyId($baseRoot->certificateDer),'Issuing-key block/fresh recovery failed');
        $policy=$providers->provider('builtin-ca');$original=$policies[0];$original['issuer_identity_id']=$newRoot->id;
        check($policy===$original && $providers->source('builtin-tsa')['policy_oid']===$policies[1],'Root recovery changed policy');
        $oldCrl=$crls->load($baseRoot->certificateDer);$newCrl=$crls->load($newRoot->certificateDer);
        check($oldCrl['number']===11 && $oldCrl['entries'][0]['serial_hex']==='aabbccddeeff'
            && count($oldCrl['entries'])===9 && $newCrl['number']===1 && $newCrl['entries']===[],'CRL continuity/fanout/new-key counter failed');
        foreach(array_slice($oldCrl['entries'],1)as$entry)check($entry['reason']===$code,'Incorrect CA-wide CRL reason');
        $changed=0;foreach($oldProjects as$pid=>$previous){if($bindings->find($pid)->identityId!==$previous->id)++$changed;}
        check($changed===5 && $projects->inspect(506)['state']==='revoked','Recovery did not preserve five-project bound or disabled block');
        rejects(fn()=>$projects->acceptSeal(501,$oldProjects[501],static fn()=>true));rejects(fn()=>$captured->assertNotRevoked());
        rejects(fn()=>$service->revoke($view['review_hash'],$reason));
        check($worker->run(time())['failed']===0 && $projects->inspect(506)['state']==='ready','Bounded catch-up did not resume');
        $current=$projects->getOrIssue(501);check($current->projectUuid===$oldProjects[501]->projectUuid,'Recovery changed UUID');
        check($finalize($f)->isModified(),'Fresh root/TSA did not seal');
        (new SampleSealVerifier())->verify($sample,file_get_contents($path),$current->certificateDer,$newTsa->certificateDer);
        $caPath=$f->createTempFile();$leafPath=$f->createTempFile();$crlPath=$f->createTempFile();
        file_put_contents($caPath,Certificate::derToPem($baseRoot->certificateDer));file_put_contents($leafPath,Certificate::derToPem($oldProjects[501]->certificateDer));
        file_put_contents($crlPath,base64_decode($oldCrl['der_b64'],true));
        [$status,$output]=runSealCommand(['openssl','verify','-crl_check','-CRLfile',$crlPath,'-CAfile',$caPath,$leafPath]);
        check($status!==0 && str_contains($output,'certificate revoked'),'Old signer not rejected against published CRL');
        file_put_contents($leafPath,Certificate::derToPem($current->certificateDer));
        check(runSealCommand(['openssl','verify','-CAfile',$caPath,$leafPath])[0]!==0,'Old trust anchor accepted the fresh-key chain');
        file_put_contents($caPath,Certificate::derToPem($newRoot->certificateDer));file_put_contents($crlPath,base64_decode($newCrl['der_b64'],true));
        check(runSealCommand(['openssl','verify','-crl_check','-CRLfile',$crlPath,'-CAfile',$caPath,$leafPath])[0]===0,'New root trust/CRL rejected replacement');
        $roots=$public->roots();check(count(array_filter($roots,static fn($root)=>$root['revoked']))===2
            && !$roots[0]['revoked'],'Public root history did not label every revoked same-key version');
    }

    // Damaged old root/TSA/project keys and missing old CRL cannot prevent independent fresh-root recovery.
    foreach(['damaged','missing-crl']as$case){
        $reset();$old=$projects->getOrIssue(501);$view=$service->preview();
        if($case==='damaged')foreach($f->logs as&$row){if($row['message']==='pki_identity')$row['private_key_ciphertext']='broken';}unset($row);
        if($case==='missing-crl')unset($f->settings[CrlRepository::settingKey(CrlIssuer::keyId($baseRoot->certificateDer))]);
        $result=$service->revoke($view['review_hash'],'compromise');
        check(!$result['crl_published'] && $result['replacement']==='renewed' && $projects->inspect(501)['state']==='ready'
            && $health->inspect(time())->status===PkiHealth::Ready,'Unavailable old publication blocked new deployment');
        check($identities->rootRevocations()->find($baseRoot->certificateDer)!==null,'Recovery lost permanent block');
    }

    // A failed replacement retains the issuer-wide block, then cron recovers without old-key reuse.
    $reset();$old=$projects->getOrIssue(501);$view=$service->preview();$rootId=$identities->activeId('root');
    $f->settings[BuiltinMaintenanceService::SETTING]=json_encode(['completed_at'=>time(),'status'=>'failed','renewed'=>0,'deferred'=>0,
        'failed'=>1,'remaining'=>0,'root_identity_id'=>$rootId,'items'=>[],
        'retries'=>['root'=>['identity_id'=>$rootId,'attempts'=>6,'retry_at'=>time()+86400]]]);
    $encryptionFails=true;$result=$service->revoke($view['review_hash'],'superseded');$encryptionFails=false;
    check($result['crl_published'] && $result['replacement']==='pending' && $identities->activeId('root')===$rootId
        && $service->preview()['revoked'],'Failed root deployment lost accepted block');
    $blocked=$finalize($f);check($blocked->getErrorCode()==='ROOT_CA_REVOKED' && file_get_contents($path)===$sample,'Revoked root allowed sealing');
    $snapshot=BuiltinMaintenanceService::load($settings);check($snapshot['retries']['root']['attempts']===1,'New block did not bypass ordinary root backoff');
    check($worker->run(time()+3601)['renewed']===3 && $projects->inspect(501)['state']==='ready','Cron did not resume root/project recovery');

    // Fresh-key activation rolls back its pointers/history/CRL while the earlier block survives.
    foreach (['COMMIT', 'root_certificate_renewal', 'crl-write'] as $failure) {
        $reset(); $projects->getOrIssue(501); $rootId = $identities->activeId('root');
        $configLock->withLock(fn() => $identities->rootRevocations()->append($rootId, 2, time()));
        $before = [$f->settings, $f->logs];
        $framework->failQuery = $failure;
        $f->onLog = static function (string $message) use ($failure): void {
            if ($message === $failure) { throw new RuntimeException('Fresh activation audit failed'); }
        };
        $f->onSettingWrite = static function (string $key) use ($failure): void {
            if ($failure === 'crl-write' && str_starts_with($key, 'crl_')) { throw new RuntimeException('Fresh CRL failed'); }
        };
        rejects(fn() => $rootRenewal->renewIfDue(time()));
        $framework->failQuery = null; $f->onLog = null; $f->onSettingWrite = null;
        check([$f->settings, $f->logs] === $before && $f->snapshot === null
            && $identities->rootRevocations()->find($baseRoot->certificateDer) !== null, 'Fresh activation rollback lost block or left partial state');
    }

    // Retired CA and pending transitions stay deferred; unrelated external projects are not substituted.
    $reset();$old=$projects->getOrIssue(501);$providers->saveAssignmentPolicy(true);$providers->saveRetired('builtin-ca',true);
    $view=$service->preview();$result=$service->revoke($view['review_hash'],'superseded');
    check($result['replacement']==='renewed' && $providers->isRetired('builtin-ca') && $providers->requiresAssignment()
        && $bindings->find(501)->identityId===$old->id && $projects->inspect(501)['state']==='revoked','Root recovery bypassed retirement/gate');
    $providers->saveRetired('builtin-ca',false);check($worker->run(time()+3601)['failed']===0 && $projects->inspect(501)['state']==='ready','Reactivated provider did not recover');
    $reset();$projects->getOrIssue(501);$old=$projects->getOrIssue(502);$external=$providers->registerExternal('Independent external CA',[$validator->validate(Certificate::derToPem($root->certificateDer))[0]],'builtin-tsa',false);
    $transition=$bindings->startTransition(502,$external);$view=$service->preview();$result=$service->revoke($view['review_hash'],'superseded');
    check($bindings->find(502)->transitionId===$transition && $bindings->find(502)->identityId===$old->id
        && $projects->inspect(502)['state']==='revoked','Recovery overwrote pending provider transition');

    // Same key registered as an external issuer cannot bypass the local block or trigger local substitution.
    $reset();$aliasProvider=$providers->registerExternal('External alias of built-in key',$validator->validate(Certificate::derToPem($baseRoot->certificateDer)),'builtin-tsa',false);
    $uuid=$rootIssuer->newProjectUuid();$leaf=$rootIssuer->createProject('Root Revocation Test',$uuid,$baseRoot);
    $externalIdentity=$identities->append('project',$leaf,$uuid,$aliasProvider,hash('sha256',$baseRoot->certificateDer),[$baseRoot->certificateDer]);
    $bindings->bindUuid(501,$uuid,$aliasProvider);$bindings->activate(501,$uuid,$externalIdentity);
    $view=$service->preview();$result=$service->revoke($view['review_hash'],'compromise');
    check($bindings->find(501)->identityId===$externalIdentity && $finalize($f)->getErrorCode()==='ROOT_CA_REVOKED','External alias bypassed block or was locally replaced');

    // External TSA configurations and captured providers cannot use an alias of the blocked root key.
    $reset();
    $sources = new DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($framework, $settings);
    $sourceId = $sources->register('Root-key alias TSA', 'https://timestamp.example/tsa',
        Certificate::derToPem($baseRoot->certificateDer), '', '', '');
    $remote = $sources->provider($sourceId);
    $view = $service->preview(); $service->revoke($view['review_hash'], 'compromise');
    foreach ([fn() => $sources->provider($sourceId), fn() => $remote->respond('request', time())] as $attempt) {
        try { $attempt(); throw new RuntimeException('External TSA root alias accepted'); }
        catch (DE\RUB\PDFSealerExternalModule\Pki\RootRevoked) { /* Blocked before transport. */ }
    }

    // A root block committed after capture rejects the B-T working copy even if recovery already deployed a new root.
    $reset();$projects->getOrIssue(501);$view=$service->preview();
    $raceFramework=new class($f,$held,$service,$view){
        public int $checks=0;public bool $fired=false;
        public function __construct(private object $inner,private array &$locks,private object $service,private array $view){}
        public function __call(string $name,array $args):mixed{return $this->inner->$name(...$args);}
        public function getQueryLogsSql(string $sql):string{
            if(str_contains($sql,'revoked_at') && !isset($this->locks['pdf_sealer_issue_501']))++$this->checks;
            if($this->checks>=3 && !$this->fired && isset($this->locks['pdf_sealer_issue_501']) && !isset($this->locks['pdf_sealer_initialize'])){
                $this->fired=true;$this->service->revoke($this->view['review_hash'],'compromise');
            }
            return $this->inner->getQueryLogsSql($sql);
        }
    };
    $race=$finalize($raceFramework);check($raceFramework->fired && $race->getErrorCode()==='ROOT_CA_REVOKED'
        && file_get_contents($path)===$sample,'Captured old root passed acceptance after block');
    $reset();$old=$projects->getOrIssue(501);$view=$service->preview();
    check($projects->acceptSeal(501,$old,function()use($service,$view):bool{rejects(fn()=>$service->revoke($view['review_hash'],'compromise'));return true;}),
        'Acceptance did not serialize a competing root block');

    $module=new DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule();$module->framework=$framework;
    foreach(['preview_root_lifecycle','renew_root_certificate','revoke_root_certificate']as$action){
        foreach([[false,null,null],[true,501,null],[true,null,501]]as[$super,$ambient,$context]){
            $framework->superuser=$super;$framework->projectId=$ambient;rejects(fn()=>$module->redcap_module_ajax($action,[], $context));
        }
        $framework->superuser=true;$framework->projectId=null;
        check(!$module->redcap_module_ajax($action,null,null)['ok'],'Malformed root AJAX accepted');
    }
    $view=$module->redcap_module_ajax('preview_root_lifecycle',[],null);check($view['ok'],'Root AJAX preview failed');
    $response=$module->redcap_module_ajax('revoke_root_certificate',['review_hash'=>$view['review_hash'],'reason'=>'superseded'],null);
    check($response['ok'] && $response['replacement']==='renewed' && $response['crl_published'],'Root AJAX recovery failed');
    check(!$module->redcap_module_ajax('revoke_root_certificate',['review_hash'=>$view['review_hash'],'reason'=>'superseded'],null)['ok'],'Stale AJAX revoked replacement root');
    check($held===[] && $f->snapshot===null,'Root lifecycle leaked locks/transaction');
    echo 'Root lifecycle: public review, same-key renewal, issuing-key block, fresh root/TSA, complete CRLs, bounded project recovery, failure/backoff, damaged old keys, policy/pending preservation, aliases, trust boundaries, races and CC AJAX passed on PHP '.PHP_VERSION.".\n";
}finally{
    $f->onLog=null;$f->onSettingWrite=null;$framework->failQuery=null;$encryptionFails=false;
    foreach($f->paths as$file)@unlink($file);
}
