<?php

declare(strict_types=1);
use DE\RUB\PDFSealerExternalModule\Pki\{ProjectEnrollmentService,CertificateIssuer,IdentityRepository,ProjectIdentityService,PkiHealthService,ExpiryInventory};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
require __DIR__ . '/project_enrollment.php';
require (getenv('PDF_SEALER_FRAMEWORK_ROOT') ?: '/home/gr/redcap/external_modules') . '/classes/PdfFinalizeResult.php';
function db_query(string $sql, array $params, mixed ...$rest): Rows {
    global $f;
    if (str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK')) return new Rows([[1]]);
    if (str_contains($sql,'SELECT s.value')) {
        $value = $f->settings[$params[1]] ?? null;
        return new Rows($value === null ? [] : [['value'=>$value,'type'=>'string']]);
    }
    return $f->queryLogs($sql,$params);
}
final class REDCap {
    public static array $events = [];
    public static function logEvent(...$args): void { self::$events[] = $args; }
}
$f->allowIdentityReads = true;
// Restore the corruption fixture before adding another provider.
// project_enrollment.php reuses $id for request IDs; restore by the external catalog.
foreach ($providers->externalIds() as $providerId) {
    $record = json_decode($f->settings['ca_provider_'.$providerId],true);
    if ($record['name'] === 'Test provider') { $record['chain'] = $validator->validate($fullPem); $f->settings['ca_provider_'.$providerId] = json_encode($record); }
}
try {
    $testRoot = $issuer->createRoot('Activation Test');
    $rootPem = Certificate::derToPem($testRoot->certificateDer);
    $cfg = $f->createTempFile();
    file_put_contents($cfg,"[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:true,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n[leaf]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=1.3.6.1.5.5.7.3.36\n[bad_usage]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,keyEncipherment\n[bad_eku]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=serverAuth\n[unknown]\nbasicConstraints=critical,CA:false\nkeyUsage=critical,digitalSignature\n1.2.3.4=critical,DER:05:00\n");
    $caKey = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>3072]);
    $caCsr = openssl_csr_new(['commonName'=>'Activation Issuing CA'],$caKey,['config'=>$cfg]);
    $ca = openssl_csr_sign($caCsr,$rootPem,$testRoot->privateKey(),365,['config'=>$cfg,'x509_extensions'=>'ca'],500);
    openssl_x509_export($ca,$caPem);
    $providerId = $admin->register('Activation provider',$caPem.$rootPem,null,false);
    $bindings->bindUuid(104,$issuer->newProjectUuid(),$providerId);
    $enrollment = new ProjectEnrollmentService($f,$bindings,$providers,$protector,$projectLock,$settings,$identities);
    $request = $enrollment->generate(104);
    $csr = base64_decode($request['base64'],true); $requestId = $request['pending']['id'];
    $sign = static function(string $csr, string $section='leaf', int $days=200) use ($cfg,$caPem,$caKey): string {
        static $serial=1000;
        $cert = openssl_csr_sign($csr,$caPem,$caKey,$days,['config'=>$cfg,'x509_extensions'=>$section,'digest_alg'=>'sha256'],++$serial);
        check($cert !== false && openssl_x509_export($cert,$pem), 'Test certificate issuance failed');
        return $pem;
    };
    $leaf = $sign($csr);
    $before = [$f->settings,$f->logs];
    $review = $enrollment->reviewCertificate(104,$requestId,$leaf);
    check($review['active_identity_id'] === null && $review['certificate']['chain_length'] === 2, 'Bad initial review');
    check([$f->settings,$f->logs] === $before, 'Review wrote certificate/key state');
    $otherRequest = $enrollment->generate(102);
    $wrongIssuer = openssl_csr_sign($csr,$rootPem,$testRoot->privateKey(),200,['config'=>$cfg,'x509_extensions'=>'leaf'],499);
    openssl_x509_export($wrongIssuer,$wrongIssuerPem);
    $badCertificates = [$wrongIssuerPem,$caPem, $leaf.$caPem, $testRoot->privateKeyPem(), $sign(base64_decode($otherRequest['base64'],true)),
        $sign($csr,'bad_usage'), $sign($csr,'bad_eku'), $sign($csr,'unknown')];
    $before = [$f->settings,$f->logs];
    foreach ($badCertificates as $bad) rejects(fn() => $enrollment->reviewCertificate(104,$requestId,$bad));
    check([$f->settings,$f->logs] === $before, 'Invalid upload changed enrollment');
    $expired = $sign($csr,'leaf',0); sleep(1);
    rejects(fn() => $enrollment->reviewCertificate(104,$requestId,$expired));
    rejects(fn() => $enrollment->activateCertificate(104,$requestId,$leaf,str_repeat('0',64),null));
    rejects(fn() => $enrollment->activateCertificate(104,$requestId,$leaf,$review['review_hash'],str_repeat('0',32)));
    $before = [$f->settings,$f->logs];
    $f->failEnrollment = true;
    rejects(fn() => $enrollment->activateCertificate(104,$requestId,$leaf,$review['review_hash'],null));
    $f->failEnrollment = false;
    check([$f->settings,$f->logs] === $before, 'Activation failure left identity, binding, or deleted pending key');
    $enrollment->activateCertificate(104,$requestId,$leaf,$review['review_hash'],null);
    check($enrollment->inspect(104) === null, 'Activation retained pending key');
    $activeId = $bindings->find(104)->identityId;
    $active = $identities->find($activeId);
    check(count($active->issuerChain) === 2 && $active->issuerId === hash('sha256',$active->issuerChain[0]), 'Issuer chain not pinned');
    $projects = new ProjectIdentityService($bindings,$identities,$protector,$issuer,new PkiHealthService($identities,$protector),$projectLock);
    check($projects->getOrIssue(104)->id === $activeId && $projects->inspect(104)['state'] === 'ready', 'External signer cannot be reused');
    rejects(fn() => $enrollment->activateCertificate(104,$requestId,$leaf,$review['review_hash'],null));
    // Public-only expiry scan includes active external signer and complete pinned chain.
    $inventory = (new ExpiryInventory($f,$logs,$settings))->collect();
    check(isset($inventory[$activeId]) && $inventory[$activeId]['der'] === $active->certificateDer, 'External active signer missing from inventory');
    foreach ($active->issuerChain as $der) check(isset($inventory[hash('sha256',$der)]), 'Pinned issuer omitted');
    // Independent CMS/ByteRange verification through the existing sample verifier, both profiles.
    $sample = DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService::samplePdf();
    $builder = new DE\RUB\PDFSealerExternalModule\Pdf\PdfSealBuilder();
    $verifier = new DE\RUB\PDFSealerExternalModule\Diagnostics\SampleSealVerifier();
    $bb = $builder->seal($sample,$active->certificateDer,$active->privateKey($protector),$projects->issuerChain($active),time());
    $verifier->verify($sample,$bb,$active->certificateDer);
    $tsaRoot = $issuer->createRoot('Independent TSA');
    $tsa = $issuer->createTsa('Independent TSA',$tsaRoot);
    $tsaProvider = new DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider(
        new DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService(DE\RUB\PDFSealerExternalModule\Timestamp\TsaPolicy::DEFAULT_OID),
        new DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity($tsa->certificateDer,$tsa->privateKey(),[$tsaRoot->certificateDer]));
    $bt = $builder->sealTimestamped($sample,$active->certificateDer,$active->privateKey($protector),$projects->issuerChain($active),time(),$tsaProvider,time());
    $verifier->verify($sample,$bt->pdf,$active->certificateDer,$tsa->certificateDer);
    // Exercise the actual finalizer, including source selection and passing the entire chain.
    $tsaRootId = $identities->append('root',$tsaRoot);
    $tsaId = $identities->append('tsa',$tsa);
    $sourceConfig = json_decode($f->settings['tsa_source_builtin-tsa'],true);
    $sourceConfig['identity_id'] = $tsaId; $sourceConfig['issuer_identity_id'] = $tsaRootId;
    $f->settings['tsa_source_builtin-tsa'] = json_encode($sourceConfig);
    $working = $f->createTempFile();
    foreach ([null, 'builtin-tsa'] as $sourceId) {
        $providerConfig = $providers->provider($providerId);
        $providerConfig['timestamp_source'] = $sourceId; $providerConfig['bb_fallback'] = false;
        $f->settings['ca_provider_'.$providerId] = json_encode($providerConfig);
        file_put_contents($working,$sample);
        $result = (new DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService($f))->finalize($working,['id'=>'seal'],
            ['document_type'=>'econsent','project_id'=>104,'record_id'=>'1','event_id'=>1]);
        check($result->isModified() && $result->isTerminal(), 'External finalizer failed: '.($result->getErrorCode() ?? 'unknown'));
        $verifier->verify($sample,file_get_contents($working),$active->certificateDer,$sourceId === null ? null : $tsa->certificateDer);
        $sealed = file_get_contents($working);
        check(preg_match('/\/ByteRange\s*\[\s*0\s+(\d+)\s+(\d+)\s+\d+\s*\]/',$sealed,$range) === 1, 'Missing test ByteRange');
        $paddedCms = hex2bin(substr($sealed,(int)$range[1]+1,(int)$range[2]-(int)$range[1]-2));
        $offset = 0;
        $cms = (new DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Asn1())->readTlv($paddedCms,$offset)['raw'];
        $embedded = (new Certificate())->fromSignedData($cms,true);
        foreach ([$active->certificateDer,...$active->issuerChain] as $der) check(in_array($der,$embedded,true), 'Full issuer chain not embedded in CMS');

    }
    // Pending replacement keeps the current signer usable until atomic activation.
    $replacement = $enrollment->generate(104);
    $replacementPem = $sign(base64_decode($replacement['base64'],true));
    $replacementReview = $enrollment->reviewCertificate(104,$replacement['pending']['id'],$replacementPem);
    check($projects->getOrIssue(104)->id === $activeId, 'Pending enrollment replaced active signer');
    $enrollment->activateCertificate(104,$replacement['pending']['id'],$replacementPem,$replacementReview['review_hash'],$activeId);
    check($projects->getOrIssue(104)->id !== $activeId && $identities->find($activeId) !== null, 'Replacement erased history or failed');
    // An external-only installation can initialize its built-in CA/TSA later.
    $fresh = clone $f;
    $fresh->settings = array_filter($fresh->settings,fn($k) => str_starts_with($k,'ca_provider_external-') || $k === 'external_ca_provider_ids',ARRAY_FILTER_USE_KEY);
    $fresh->logs = array_values(array_filter($fresh->logs,fn($r) => ($r['message'] === 'pki_identity' && $r['identity_role'] === 'project')
        || ($r['message'] === 'project_identity_binding' && $r['redcap_pid'] === '104')));
    $freshSettings = new DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader($fresh,[$fresh,'getSystemSetting']);
    $freshLogs = new DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader($fresh,[$fresh,'queryLogs']);
    $freshIdentities = new IdentityRepository($fresh,$protector,$freshLogs,$freshSettings);
    $freshBindings = new DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository($fresh,$freshLogs);
    $freshHealth = new PkiHealthService($freshIdentities,$protector);
    $priorBinding = $freshBindings->find(104);
    (new DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationService($fresh,$freshIdentities,$freshBindings,$issuer,$freshHealth,
        new DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock($lock)))->initialize('Added Later');
    check($freshBindings->find(104) == $priorBinding && $freshHealth->inspect(time())->status === DE\RUB\PDFSealerExternalModule\Pki\PkiHealth::Ready,
        'External identity prevented later built-in initialization or lost binding');
    echo "External activation: review, rejection, rollback, stale requests, pinned-chain expiry, signer reuse/replacement, and B-B/B-T seals passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
