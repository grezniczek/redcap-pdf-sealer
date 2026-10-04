<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pki\{TsaRevocationService,TsaRevocationRepository,TsaRenewalService,
    ProjectRenewalService,ProjectIdentityService,CertificateIssuer,CrlRepository,CrlIssuer,CrlPublicationService,
    PublicTrustRepository,BuiltinMaintenanceService,RootRenewalService,GeneratedIdentity};
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService,AlarmRepository,AlarmLock};
use DE\RUB\PDFSealerExternalModule\Diagnostics\{PkiDiagnosticService,SampleSealVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\{Certificate,SignedDataVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client,Config};
use DE\RUB\PDFSealerExternalModule\Timestamp\{InternalTimestampProvider,InternalTsaService,PolicyOidAsn1};
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;

// Disposable crypto and transactional fake persistence. No live REDCap writes.
require __DIR__ . '/project_renewal.php';
require __DIR__ . '/support/pdf_seal_checks.php';
require __DIR__ . '/support/certificate_validity.php';
if (!defined('APP_PATH_SURVEY_FULL')) define('APP_PATH_SURVEY_FULL','https://redcap.example/surveys/');
$tsaIssuer = new CertificateIssuer([$f,'createTempFile'],[\PDFSealerTests\CertificateSerials::class,'reserve'],APP_PATH_SURVEY_FULL);
$baseRoot = $tsaIssuer->createRoot('TSA Revocation Test');
$baseTsa = $tsaIssuer->createTsa('TSA Revocation Test',$baseRoot);
$projects = new ProjectIdentityService($bindings,$identities,$protector,$tsaIssuer,$health,$projectLock,$configLock);
$projectRenewal = new ProjectRenewalService($framework,$bindings,$identities,$enrollment,$projects,$health,$projectLock,$configLock);
$tsaRenewal = new TsaRenewalService($framework,$identities,$protector,$tsaIssuer,$health,$configLock);
$crls = new CrlRepository($framework,$settings);
$publication = new CrlPublicationService($framework,new PublicTrustRepository($logs,$settings),$identities,$protector,$crls,$configLock);
$service = new TsaRevocationService($framework,$identities,$tsaRenewal,$publication,$crls,$configLock);
$alarmLock = new AlarmLock($lockQuery);
$worker = new BuiltinMaintenanceService($framework,$identities,$bindings,$protector,$tsaIssuer,$health,$projectRenewal,
    $configLock,$alarmLock,new AdminAlarmService($framework,new AlarmRepository($framework),$alarmLock,static fn(): bool=>true),$settings);
$reset = static function() use ($f,$framework,$identities,$providers,$baseRoot,$baseTsa,$crls,$projects): void {
    $f->settings=[]; $f->logs=[]; $f->enabled=[501]; $f->projectId=501; $f->onLog=null; $f->onSettingWrite=null;
    $framework->superuser=true; $framework->projectId=null; $framework->failQuery=null;
    $rootId=$identities->append('root',$baseRoot); $tsaId=$identities->append('tsa',$baseTsa);
    $identities->activate('root',$rootId); $identities->activate('tsa',$tsaId); $providers->initialize($rootId,$tsaId);
    $providers->saveTimestampPolicy('builtin-ca','builtin-tsa',false);
    $f->settings['organization']='TSA Revocation Test';
    $crls->save($baseRoot->certificateDer,(new CrlIssuer())->issue($baseRoot,10,time(),
        [['serial_hex'=>'aabbccddeeff','revoked_at'=>time()-60,'reason'=>4]]));
    $projects->getOrIssue(501);
};
$capture = static fn()=>$health->captureTimestamp($providers->source('builtin-tsa'),time());
$sample=PkiDiagnosticService::samplePdf(); $path=$f->createTempFile();
$finalize=static function(object $target)use($path,$sample){
    file_put_contents($path,$sample);
    return (new PdfFinalizeService($target))->finalize($path,['id'=>'seal'],
        ['terminal_action_reserved_for_core' => false, 'document_type'=>'econsent','project_id'=>501,'record_id'=>'1','event_id'=>1]);
};
try {
    $reset(); $view=$service->preview(); $old=$identities->find($view['identity_id']);
    $before=[$f->settings,$f->logs,$decryptCalls];
    check($service->preview()===$view && [$f->settings,$f->logs,$decryptCalls]===$before,'TSA review wrote or decrypted private material');
    foreach(['',str_repeat('0',64)] as $hash) rejects(fn()=>$service->revoke($hash,'superseded'));
    foreach(['','hold','1',1,null] as $reason) rejects(fn()=>$service->revoke($view['review_hash'],$reason));
    foreach(['START TRANSACTION','COMMIT',TsaRevocationRepository::MESSAGE] as $failure) {
        $framework->failQuery=$failure;
        $f->onLog=static function(string $message)use($failure){if($message===$failure)throw new RuntimeException('Block audit unavailable');};
        rejects(fn()=>$service->revoke($view['review_hash'],'superseded'));
        $framework->failQuery=null; $f->onLog=null;
        check([$f->settings,$f->logs,$decryptCalls]===$before && $f->snapshot===null,'Failed block partially committed');
    }
    // Ordinary replacement preserves previous tokens and never publishes a revocation.
    $captured=$capture(); $source=$providers->source('builtin-tsa');
    $client=new Client(new Config('http://localhost.invalid/tsa'),new PolicyOidAsn1($source['policy_oid']));
    $request=$client->buildRequest(random_bytes(32));
    $provider=new InternalTimestampProvider(new InternalTsaService($source['policy_oid']),$captured);
    $oldToken=$client->parseResponse($provider->respond($request->der,time()),$request,time());
    $result=$service->replace($view['review_hash']);
    check($result['replacement']==='renewed' && $identities->activeId('tsa')!==$old->id
        && $identities->tsaRevocations()->find($old)===null && $crls->load($baseRoot->certificateDer)['number']===10,
        'Ordinary TSA replacement revoked or changed the CRL');
    $provider->respond($request->der,time());
    rejects(fn()=>$service->replace($view['review_hash']));
    check((new SignedDataVerifier(requireSigningCertificate:true))->verify($oldToken)===$baseTsa->certificateDer,'Replacement altered an old token');

    foreach(['superseded'=>4,'compromise'=>1] as $reason=>$code) {
        $reset(); $view=$service->preview(); $old=$identities->find($view['identity_id']); $captured=$capture();
        $policies=[$f->settings['ca_provider_builtin-ca'],$providers->source('builtin-tsa')['policy_oid']];
        $f->onLog=static function(string $message,array $values)use(&$held){
            if($message===TsaRevocationRepository::MESSAGE)check(isset($held['pdf_sealer_initialize'])
                && $values['actor']==='admin' && !str_contains(json_encode($values),'PRIVATE KEY'),'Unsafe TSA block audit/lock');
        };
        $result=$service->revoke($view['review_hash'],$reason); $f->onLog=null;
        $current=$identities->find($identities->activeId('tsa')); $record=$crls->load($baseRoot->certificateDer);
        check($result['crl_published'] && $result['replacement']==='renewed' && $record['number']===11
            && count($record['entries'])===2 && $record['entries'][1]['reason']===$code
            && $identities->tsaRevocations()->find($old)['reason']===(string)$code,'Incorrect TSA block/publication');
        check($current->id!==$old->id && (new Certificate())->fields($current->certificateDer)['public_key']!==(new Certificate())->fields($old->certificateDer)['public_key']
            && [$f->settings['ca_provider_builtin-ca'],$providers->source('builtin-tsa')['policy_oid']]===$policies,'Recovery changed policy or reused the old key');
        rejects(fn()=>$captured->assertNotRevoked()); rejects(fn()=>$service->revoke($view['review_hash'],$reason));
        check($finalize($f)->isModified(),'Fresh TSA did not restore B-T finalization');
        (new SampleSealVerifier())->verify($sample,file_get_contents($path),$projects->getOrIssue(501)->certificateDer,$current->certificateDer);
        $caPath=$f->createTempFile(); $leafPath=$f->createTempFile(); $crlPath=$f->createTempFile();
        file_put_contents($caPath,Certificate::derToPem($baseRoot->certificateDer));
        file_put_contents($leafPath,Certificate::derToPem($old->certificateDer));file_put_contents($crlPath,base64_decode($record['der_b64'],true));
        [$status,$output]=runSealCommand(['openssl','verify','-purpose','timestampsign','-crl_check','-CRLfile',$crlPath,'-CAfile',$caPath,$leafPath]);
        check($status!==0 && str_contains($output,'certificate revoked'),'OpenSSL did not see the revoked TSA');
        file_put_contents($leafPath,Certificate::derToPem($current->certificateDer));
        check(runSealCommand(['openssl','verify','-purpose','timestampsign','-crl_check','-CRLfile',$crlPath,'-CAfile',$caPath,$leafPath])[0]===0,'Fresh TSA rejected by CRL');
        check((new SignedDataVerifier(requireSigningCertificate:true))->verify($oldToken)===$baseTsa->certificateDer,
            'Revocation rewrote old token bytes (cryptographic verification is not a trust verdict)');
    }

    // Publication failure cannot undo the accepted block or prevent fresh-key recovery.
    $reset(); $view=$service->preview(); $key=CrlRepository::settingKey(CrlIssuer::keyId($baseRoot->certificateDer));
    $f->onSettingWrite=static function(string $written)use($key){if($written===$key)throw new RuntimeException('CRL unavailable');};
    $result=$service->revoke($view['review_hash'],'superseded');$f->onSettingWrite=null;
    check(!$result['crl_published'] && $result['replacement']==='renewed' && $crls->load($baseRoot->certificateDer)['number']===10,'CRL failure undid block');
    check($worker->run(time())['failed']===0 && $crls->load($baseRoot->certificateDer)['number']===11,'Hourly publication retry failed');

    // A young revoked TSA retries even after an ordinary renewal backoff. A damaged old key is not needed.
    $reset(); $view=$service->preview(); $old=$identities->find($view['identity_id']); $captured=$capture();
    foreach($f->logs as &$row)if(($row['identity_id']??null)===$old->id)$row['private_key_ciphertext']='broken';unset($row);
    check($service->preview()===$view,'Public review depended on the damaged old key');
    $f->settings[BuiltinMaintenanceService::SETTING]=json_encode(['completed_at'=>time(),'status'=>'failed','renewed'=>0,'deferred'=>0,
        'failed'=>1,'remaining'=>0,'root_identity_id'=>$identities->activeId('root'),'items'=>[],
        'retries'=>['tsa'=>['identity_id'=>$old->id,'attempts'=>6,'retry_at'=>time()+86400]]]);
    $encryptionFails=true;$result=$service->revoke($view['review_hash'],'compromise');$encryptionFails=false;
    check($result['crl_published'] && $result['replacement']==='pending' && $identities->activeId('tsa')===$old->id,'Failed replacement lost the block');
    rejects($capture); rejects(fn()=>$captured->assertNotRevoked());
    $strict=$finalize($f);check(!$strict->isModified() && file_get_contents($path)===$sample,'Blocked TSA was used by strict finalization');
    $providers->saveTimestampPolicy('builtin-ca','builtin-tsa',true);
    $fallback=$finalize($f);check($fallback->isModified() && $fallback->getMetadata()['seal_profile']==='pades-b-b','Configured B-B fallback did not work');
    $recovered=$worker->run(time());check($recovered['failed']===0 && $identities->activeId('tsa')!==$old->id,'Revocation did not bypass old backoff/recover damaged key');

    // A block during token generation is detected after signing, even with a previously captured key.
    $reset(); $view=$service->preview();$captured=$capture();$encryptionFails=true;
    $guardCalls=0;
    $guarded=new DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity($captured->certificateDer,$captured->privateKey,$captured->chainDer,
        function()use($service,$view,$captured,&$guardCalls):void{
            if(++$guardCalls===2)$service->revoke($view['review_hash'],'superseded');
            $captured->assertNotRevoked();
        });
    $provider=new InternalTimestampProvider(new InternalTsaService($providers->source('builtin-tsa')['policy_oid']),$guarded);
    rejects(fn()=>$provider->respond($request->der,time()));$encryptionFails=false;
    check($guardCalls===2,'Token race did not reach the post-signing check');

    // A completed token revoked before working-copy acceptance cannot publish that copy, even if recovery succeeds.
    $reset();$view=$service->preview();
    $raceFramework=new class($f,$held,$service,$view) {
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
    $race=$finalize($raceFramework);
    check($raceFramework->fired && $race->getErrorCode()==='TSA_CERTIFICATE_REVOKED' && file_get_contents($path)===$sample,'Captured revoked TSA passed the acceptance boundary');
    $reset();$view=$service->preview();
    $acceptFramework=new class($f,$held,$service,$view){
        public bool $checked=false;
        public function __construct(private object $inner,private array &$locks,private object $service,private array $view){}
        public function __call(string $name,array $args):mixed{return $this->inner->$name(...$args);}
        public function getQueryLogsSql(string $sql):string{
            if(!$this->checked && str_contains($sql,'revoked_at') && isset($this->locks['pdf_sealer_issue_501'],$this->locks['pdf_sealer_initialize'])){
                $this->checked=true;rejects(fn()=>$this->service->revoke($this->view['review_hash'],'superseded'));
            }
            return $this->inner->getQueryLogsSql($sql);
        }
    };
    check($finalize($acceptFramework)->isModified() && $acceptFramework->checked,'Final acceptance did not serialize competing TSA revocation');
    check($identities->tsaRevocations()->find($identities->find($view['identity_id']))===null,'Competing revocation bypassed lock');

    // Root renewal merges both roles even when prompt CRL publication/recovery failed.
    $reset();$view=$service->preview();$old=$identities->find($view['identity_id']);
    $rootId=$identities->activeId('root');$key=CrlRepository::settingKey(CrlIssuer::keyId($baseRoot->certificateDer));
    $f->onSettingWrite=static function(string $written)use($key){if($written===$key)throw new RuntimeException('CRL unavailable');};
    $encryptionFails=true;$result=$service->revoke($view['review_hash'],'compromise');$encryptionFails=false;$f->onSettingWrite=null;
    check(!$result['crl_published'] && $result['replacement']==='pending','Fixture did not retain unpublished TSA block');
    $project=$projects->getOrIssue(501);
    $configLock->withLock(function()use($framework,$identities,$project,$baseRoot){
        $framework->query('START TRANSACTION',[]);
        $identities->revocations()->append(501,$project,$baseRoot->certificateDer,4,time());
        $framework->query('COMMIT',[]);
    });
    $nearDer=maintenanceCertificate($baseRoot->certificateDer,$baseRoot->privateKey(),
        openssl_x509_parse(Certificate::derToPem($baseRoot->certificateDer))['validFrom_time_t'],time()+RootRenewalService::WINDOW-1);
    $nearId=$identities->append('root',new GeneratedIdentity($nearDer,$baseRoot->privateKeyPem()));$identities->activate('root',$nearId);
    $ca=$providers->provider('builtin-ca');$ca['issuer_identity_id']=$nearId;
    $source=$providers->source('builtin-tsa');$source['issuer_identity_id']=$nearId;
    $f->settings['ca_provider_builtin-ca']=json_encode($ca);$f->settings['tsa_source_builtin-tsa']=json_encode($source);
    foreach($f->logs as &$row)if(($row['identity_id']??null)===$old->id)$row['private_key_ciphertext']='broken';unset($row);
    check((new RootRenewalService($framework,$identities,$protector,$tsaIssuer,$health,$crls,$configLock))->renewIfDue(time())==='renewed'
        && count($crls->load($baseRoot->certificateDer)['entries'])===3 && $identities->activeId('tsa')!==$old->id,
        'Root renewal failed revoked TSA recovery or lost project/TSA entries');
    $capture()->assertNotRevoked();

    // Authenticated CC AJAX only; malformed/stale requests cannot revoke a replacement.
    $reset();
    $module=new DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule();$module->framework=$framework;
    foreach(['preview_tsa_lifecycle','replace_tsa_certificate','revoke_tsa_certificate'] as $action){
        foreach([[false,null,null],[true,501,null],[true,null,501]] as [$super,$ambient,$context]){
            $framework->superuser=$super;$framework->projectId=$ambient;rejects(fn()=>$module->redcap_module_ajax($action,[], $context));
        }
        $framework->superuser=true;$framework->projectId=null;
        check(!$module->redcap_module_ajax($action,null,null)['ok'],'Malformed AJAX accepted');
    }
    $view=$module->redcap_module_ajax('preview_tsa_lifecycle',[],null);check($view['ok'],'AJAX public review failed');
    $result=$module->redcap_module_ajax('revoke_tsa_certificate',['review_hash'=>$view['review_hash'],'reason'=>'superseded'],null);
    check($result['ok'] && $result['crl_published'] && $result['replacement']==='renewed','AJAX TSA recovery failed');
    check(!$module->redcap_module_ajax('revoke_tsa_certificate',['review_hash'=>$view['review_hash'],'reason'=>'superseded'],null)['ok'],'Replayed AJAX blocked new TSA');
    check($held===[] && $f->snapshot===null,'TSA lifecycle leaked locks/transaction');
    echo 'TSA lifecycle: public review, ordinary replacement, permanent block, rollback, CRL status, fresh-key recovery, policy preservation, damaged-key/backoff recovery, token/finalizer races and CC AJAX passed on PHP '.PHP_VERSION.".\n";
} finally {
    $f->onLog=null;$f->onSettingWrite=null;$framework->failQuery=null;$encryptionFails=false;
    foreach($f->paths as $file)@unlink($file);
}
