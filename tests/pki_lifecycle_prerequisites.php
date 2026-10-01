<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\{Certificate, SignedDataVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Signer;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config};
use DE\RUB\PDFSealerExternalModule\Pki\{CertificateIssuer, GeneratedIdentity, PkiHealthService};
use DE\RUB\PDFSealerExternalModule\Timestamp\{InternalTimestampProvider, InternalTsaService, PolicyOidAsn1, TsaIdentity};

// Real crypto plus the existing fake persistence/finalizer; never bootstraps REDCap.
require __DIR__ . '/external_activation.php';
require __DIR__ . '/support/pdf_timestamp_checks.php';
require __DIR__ . '/support/root_certificate_probe.php';

try {
    $baseRoot = $issuer->createRoot('Lifecycle Test');
    $now = time();
    $shortRoot = new GeneratedIdentity(reissueProbeRoot($baseRoot->certificateDer, $baseRoot->privateKey(),
        $now - 60, $now + 2 * 86400 + 3600), $baseRoot->privateKeyPem());
    foreach ([$issuer->createProject('Lifecycle Test', $issuer->newProjectUuid(), $shortRoot),
        $issuer->createTsa('Lifecycle Test', $shortRoot)] as $leaf) {
        $info = openssl_x509_parse(Certificate::derToPem($leaf->certificateDer));
        check($info['validTo_time_t'] <= $now + 2 * 86400 + 3600
            && $info['validTo_time_t'] - $info['validFrom_time_t'] === 2 * 86400,
            'Built-in leaf outlived its issuer or ignored the whole-day cap');
        $leafFile = $f->createTempFile(); $caFile = $f->createTempFile();
        file_put_contents($leafFile, Certificate::derToPem($leaf->certificateDer));
        file_put_contents($caFile, Certificate::derToPem($shortRoot->certificateDer));
        [$status] = runSealCommand(['openssl', 'verify', '-CAfile', $caFile, $leafFile]);
        check($status === 0, 'OpenSSL rejected a capped leaf');
    }
    $normalLeaf = $issuer->createProject('Lifecycle Test', $issuer->newProjectUuid(), $baseRoot);
    $info = openssl_x509_parse(Certificate::derToPem($normalLeaf->certificateDer));
    check($info['validTo_time_t'] - $info['validFrom_time_t'] === CertificateIssuer::LEAF_DAYS * 86400,
        'Full leaf lifetime changed with a sufficiently long-lived issuer');
    $oneDayRoot = new GeneratedIdentity(reissueProbeRoot($baseRoot->certificateDer, $baseRoot->privateKey(),
        $now - 60, $now + 86400 + 3600), $baseRoot->privateKeyPem());
    $oneDayLeaf = $issuer->createTsa('Lifecycle Test', $oneDayRoot);
    $info = openssl_x509_parse(Certificate::derToPem($oneDayLeaf->certificateDer));
    check($info['validTo_time_t'] - $info['validFrom_time_t'] === 86400, 'One whole remaining day was rejected');
    $tooShort = new GeneratedIdentity(reissueProbeRoot($baseRoot->certificateDer, $baseRoot->privateKey(),
        $now - 60, $now + 86300), $baseRoot->privateKeyPem());
    $reservations = 0;
    $guardedIssuer = new CertificateIssuer([$f, 'createTempFile'], static function() use (&$reservations): int { return ++$reservations; });
    $before = count($f->paths);
    rejects(fn() => $guardedIssuer->createProject('Lifecycle Test', $guardedIssuer->newProjectUuid(), $tooShort));
    rejects(fn() => $guardedIssuer->createTsa('Lifecycle Test', $tooShort));
    check($reservations === 0 && count($f->paths) === $before, 'Insufficient issuer lifetime started allocation/signing work');

    $tsaA = $issuer->createTsa('Lifecycle Test', $baseRoot);
    $rootB = $issuer->createRoot('Replacement TSA');
    $tsaB = $issuer->createTsa('Replacement TSA', $rootB);
    $rootAId = $identities->append('root', $baseRoot); $tsaAId = $identities->append('tsa', $tsaA);
    $rootBId = $identities->append('root', $rootB); $tsaBId = $identities->append('tsa', $tsaB);
    $sourceA = ['id' => 'builtin-tsa', 'kind' => 'internal', 'identity_id' => $tsaAId,
        'issuer_identity_id' => $rootAId, 'policy_oid' => '1.3.6.1.4.1.55555.3161.20'];
    $sourceB = ['id' => 'builtin-tsa', 'kind' => 'internal', 'identity_id' => $tsaBId,
        'issuer_identity_id' => $rootBId, 'policy_oid' => '1.3.6.1.4.1.55555.3161.21'];
    $f->settings['tsa_source_builtin-tsa'] = json_encode($sourceA);
    $providerId = $bindings->find(104)->providerId;
    $providers->saveTimestampPolicy($providerId, 'builtin-tsa', false);
    $reads = 0;
    // Change all configured references immediately after the reader captured source A.
    $f->onSettingRead = static function(string $key) use ($f, $sourceB, &$reads): void {
        if ($key !== 'tsa_source_builtin-tsa') { return; }
        ++$reads;
        $f->settings[$key] = json_encode($sourceB);
    };
    $working = $f->createTempFile(); $sample = DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService::samplePdf();
    file_put_contents($working, $sample);
    $result = (new DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService($f))->finalize($working, ['id' => 'seal'],
        ['project_id' => 104, 'document_type' => 'econsent', 'record_id' => '1', 'event_id' => 1]);
    $f->onSettingRead = null;
    check($result->isModified() && $reads === 1, 'Finalizer re-read or mixed timestamp source generations');
    $project = $identities->find($bindings->find(104)->identityId);
    $signedPdf = file_get_contents($working);
    (new DE\RUB\PDFSealerExternalModule\Diagnostics\SampleSealVerifier())->verify($sample, $signedPdf, $project->certificateDer, $tsaA->certificateDer);
    $cms = verifySeal($sample, $signedPdf, Certificate::derToPem($testRoot->certificateDer));
    $token = (new Signer())->signatureTimestampTokens($cms)[0];
    check(str_contains($token, $baseRoot->certificateDer) && !str_contains($token, $rootB->certificateDer),
        'Timestamp used an issuer different from the captured TSA generation');
    $asn1 = new PolicyOidAsn1($sourceA['policy_oid']);
    $offset = 0; $content = (new Certificate($asn1))->encapsulatedContent((new Certificate($asn1))->signedDataContent($token), $offset)[1];
    $info = $asn1->readSingleElement($content, 0x30, 'TSTInfo')['value'];
    $offset = 0; $asn1->readTlv($info, $offset);
    check($asn1->readTlv($info, $offset)['raw'] === $asn1->encodeObjectIdentifier($sourceA['policy_oid']),
        'Timestamp policy did not come from the captured source');

    $health = new PkiHealthService($identities, $protector);
    $captured = $health->captureTimestamp($sourceA, time());
    $identities->activate('root', $rootAId); $identities->activate('tsa', $tsaAId);
    $f->settings['organization'] = 'Lifecycle Test';
    $builtin = $providers->provider('builtin-ca'); $builtin['issuer_identity_id'] = $rootAId;
    $f->settings['ca_provider_builtin-ca'] = json_encode($builtin);
    $f->settings['tsa_source_builtin-tsa'] = json_encode($sourceA);
    $diagnosticReads = 0;
    $f->onSettingRead = static function(string $key) use ($f, $sourceB, &$diagnosticReads): void {
        if ($key !== 'tsa_source_builtin-tsa') { return; }
        ++$diagnosticReads;
        $changed = $sourceB; $changed['policy_oid'] = 'invalid-policy';
        $f->settings[$key] = json_encode($changed);
    };
    $diagnostic = new DE\RUB\PDFSealerExternalModule\Diagnostics\PkiDiagnosticService($identities, $protector, $issuer, $settings,
        new DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock($lock));
    $diagnosticResult = $diagnostic->run();
    $f->onSettingRead = null;
    check($diagnosticResult['passed'] && $diagnosticReads === 1, 'Diagnostic re-read/mixed the TSA policy or identity');
    check($diagnosticResult['versions'] === ['root'=>$rootAId,'tsa'=>$tsaAId,'tsa_issuer'=>$rootAId,'tsa_policy'=>$sourceA['policy_oid']],
        'Diagnostic versions described later configuration rather than the identities actually tested');
    $bad = $sourceA; $bad['issuer_identity_id'] = $rootBId;
    rejects(fn() => $health->captureTimestamp($bad, time()));
    // An earlier request clock must not date a token before its newly captured TSA certificate.
    $request = (new Client(new Config('http://localhost.invalid/tsa'), $asn1))->buildRequest('fresh-source');
    $certificateStart = openssl_x509_parse(Certificate::derToPem($tsaA->certificateDer))['validFrom_time_t'];
    $currentTime = time();
    $provider = new InternalTimestampProvider(new InternalTsaService($sourceA['policy_oid']), $captured, static fn(): int => $currentTime);
    $response = $provider->respond($request->der, $certificateStart - 10);
    $acceptedToken = (new Client(new Config('http://localhost.invalid/tsa'), $asn1))->parseResponse($response, $request, $currentTime);
    check((new SignedDataVerifier(requireSigningCertificate: true))->verify($acceptedToken) === $tsaA->certificateDer,
        'Server-time token failed verification');
    $offset = 0; $contents = (new Certificate($asn1))->encapsulatedContent((new Certificate($asn1))->signedDataContent($acceptedToken), $offset)[1];
    $body = $asn1->readSingleElement($contents, 0x30, 'TSTInfo')['value']; $offset = 0;
    for ($i = 0; $i < 4; ++$i) { $asn1->readTlv($body, $offset); }
    check($asn1->readTlv($body, $offset)['value'] === gmdate('YmdHis', $currentTime) . 'Z', 'Token retained stale request time');
    $expiredAtUse = $currentTime + CertificateIssuer::LEAF_DAYS * 86400 + 10;
    $expiredProvider = new InternalTimestampProvider(new InternalTsaService($sourceA['policy_oid']), $captured, static fn(): int => $expiredAtUse);
    rejects(fn() => $expiredProvider->respond($request->der, $currentTime));
    // Chain validity is rechecked at actual token time, even if the TSA leaf is still valid.
    $shortIssuer = reissueProbeRoot($baseRoot->certificateDer, $baseRoot->privateKey(), $now - 60, $now + 1);
    $chainProvider = new InternalTimestampProvider(new InternalTsaService($sourceA['policy_oid']),
        new TsaIdentity($tsaA->certificateDer, $tsaA->privateKey(), [$shortIssuer]), static fn(): int => $now + 2);
    rejects(fn() => $chainProvider->respond($request->der, $now));
    echo 'Lifecycle prerequisites: issuer validity caps, independent chain checks, coherent finalizer TSA/issuer/policy capture and current token time passed on PHP ' . PHP_VERSION . ".\n";
} finally {
    $f->onSettingRead = null;
    foreach ($f->paths as $path) { @unlink($path); }
}
