<?php

declare(strict_types=1);
use DE\RUB\PDFSealerExternalModule\Timestamp\{ExternalTimestampSources, InternalTsaService, TimestampSourceRegistrationFailed, TsaIdentity, TsaPolicy};
use DE\RUB\PDFSealerExternalModule\Pki\ExpiryInventory;
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Vanderbilt\REDCap\Classes\Http\ResponseByteLimit;
require __DIR__ . '/external_activation.php';
require (getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase') . '/Classes/Http/ResponseByteLimit.php';
final class HttpClient {
    public static int $calls = 0;
    public static bool $fail = false;
    public static mixed $respond;
    public static function requestWithResponseLimit(string $method, string $url, array $options, ResponseByteLimit $limit): object {
        self::$calls++;
        check($method === 'POST' && $url === 'https://tsa.example.test/stamp', 'Unexpected destination');
        check($options['auth'] === ['account', 'test-secret-password', 'basic'], 'Stored credentials were not decrypted');
        if (self::$fail) throw new RuntimeException('Remote error with test-secret-password');
        $body = (self::$respond)($options['body']);
        return new class($body) {
            public function __construct(private string $body) {}
            public function getStatusCode(): int { return 200; }
            public function getHeaderLine(string $key): string { return $key === 'Content-Type' ? 'application/timestamp-reply' : ''; }
            public function getBody(): string { return $this->body; }
        };
    }
}
$sources = new ExternalTimestampSources($f);
$registrationFailsAt = static function (callable $action, string $stage): void {
    try { $action(); }
    catch (TimestampSourceRegistrationFailed $error) {
        check($error->stage === $stage, 'Unexpected TSA registration failure stage');
        return;
    }
    throw new RuntimeException('Expected TSA registration failure');
};
try {
    $registrationFailsAt(fn() => $sources->register('Bad endpoint', 'http://tsa.example.test/stamp', Certificate::derToPem($tsaRoot->certificateDer), '', '', ''), 'endpoint');
    $registrationFailsAt(fn() => $sources->register('Bad chain', 'https://tsa.example.test/stamp', 'not a PEM certificate', '', '', ''), 'chain');
    $registrationFailsAt(fn() => $sources->register('Bad policy', 'https://tsa.example.test/stamp', Certificate::derToPem($tsaRoot->certificateDer), 'invalid', '', ''), 'details');
    $sourceId = $sources->register('External TSA test', 'https://tsa.example.test/stamp', Certificate::derToPem($tsaRoot->certificateDer), '', 'account', 'test-secret-password');
    check(HttpClient::$calls === 0, 'Registration contacted TSA');
    check(!str_contains(json_encode($f->settings), 'test-secret-password'), 'Plaintext password stored');
    $beforeReads = $decryptCalls;
    $summary = $sources->summaries();
    check($decryptCalls === $beforeReads && $summary[0]['diagnostic'] === null, 'Page read decrypted credentials or ran probe');
    check(!str_contains(json_encode($summary), 'endpoint') && !str_contains(json_encode($summary), 'credentials'), 'Public summary contains private config');
    $before = [$f->settings,$f->logs];
    $registrationFailsAt(fn() => $sources->register('External TSA test', 'https://tsa.example.test/stamp', Certificate::derToPem($tsaRoot->certificateDer), '', '', ''), 'duplicate');
    check([$f->settings,$f->logs] === $before, 'Duplicate registration changed storage');
    $f->onLog = static function ($message): void { if ($message === 'timestamp_source_admin') throw new RuntimeException('Audit failed'); };
    $registrationFailsAt(fn() => $sources->register('Rollback TSA', 'https://tsa.example.test/stamp', Certificate::derToPem($tsaRoot->certificateDer), '', 'account', 'test-secret-password'), 'storage');
    check([$f->settings,$f->logs] === $before, 'Registration rollback lost atomicity');
    rejects(fn() => $sources->savePolicy($providerId, $sourceId, false));
    check([$f->settings,$f->logs] === $before, 'Policy rollback lost atomicity');
    $f->onLog = null;
    rejects(fn() => $sources->savePolicy($providerId, 'missing-source', false));
    $sources->savePolicy($providerId, $sourceId, false);
    check($providers->timestampSettings($providerId)->mode === 'external', 'External mode not selected');
    $identity = new TsaIdentity($tsa->certificateDer, $tsa->privateKey(), [$tsaRoot->certificateDer]);
    HttpClient::$respond = static fn($query) => (new InternalTsaService(TsaPolicy::DEFAULT_OID))->respond($query, $identity, time());
    $probe = new \DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\Client(new \DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\Config('https://timestamp.invalid/'));
    $sources->provider($sourceId)->respond($probe->buildRequest('test')->der, time());
    $diagnostic = $sources->diagnose($sourceId);
    check($diagnostic['ok'] && $diagnostic['signer_sha256'] === hash('sha256',$tsa->certificateDer), 'Diagnostic failed to verify signer');
    check($sources->snapshot($sourceId) === $diagnostic, 'Diagnostic not persisted');
    $inventory = (new ExpiryInventory($f,$logs,$settings))->collect();
    check(isset($inventory[hash('sha256',$tsaRoot->certificateDer)]), 'External TSA trust root omitted from expiry scan');
    $active = $projects->getOrIssue(104);
    $working = $f->createTempFile();
    $context = ['document_type'=>'econsent','project_id'=>104,'record_id'=>'1','event_id'=>1];
    $finalizer = new PdfFinalizeService($f);
    file_put_contents($working,$sample);
    $result = $finalizer->finalize($working,['id'=>'seal'],$context);
    check($result->isModified() && $result->isTerminal(), 'Configured external finalizer failed');
    $verifier->verify($sample,file_get_contents($working),$active->certificateDer,$tsa->certificateDer);
    HttpClient::$fail = true;
    $f->onLog = static function ($message): void { if ($message === 'timestamp_source_admin') throw new RuntimeException('Audit failed'); };
    rejects(fn() => $sources->diagnose($sourceId));
    check($sources->snapshot($sourceId) === $diagnostic, 'Diagnostic audit failure overwrote the saved result');
    $f->onLog = null;
    $failed = $sources->diagnose($sourceId);
    check(!$failed['ok'] && $sources->snapshot($sourceId) === $failed, 'Failed diagnostic retained old success');
    file_put_contents($working,$sample);
    $calls = HttpClient::$calls;
    $result = $finalizer->finalize($working,['id'=>'seal'],$context);
    check(!$result->isModified() && file_get_contents($working) === $sample && HttpClient::$calls === $calls + 1,
        'Strict failure changed bytes or silently tried an alternative');
    $failures = array_values(array_filter($f->logs, static fn($row) => $row['message'] === 'seal_event'));
    check(end($failures)['attempted_timestamp_source'] === $sourceId, 'Failure audit omitted attempted source ID');
    $sources->savePolicy($providerId,$sourceId,true);
    file_put_contents($working,$sample);
    $result = $finalizer->finalize($working,['id'=>'seal'],$context);
    check($result->isModified(), 'Explicit B-B fallback failed');
    $verifier->verify($sample,file_get_contents($working),$active->certificateDer);
    check(str_contains(json_encode(end(REDCap::$events)), 'timestamp fallback'), 'External fallback missing from project logging');
    $sources->savePolicy($providerId,null,true);
    check(!$providers->provider($providerId)['bb_fallback'], 'No timestamp retained a meaningless fallback');
    $calls = HttpClient::$calls;
    file_put_contents($working,$sample);
    check($finalizer->finalize($working,['id'=>'seal'],$context)->isModified() && HttpClient::$calls === $calls, 'No-timestamp policy contacted TSA');
    check(!str_contains(json_encode($f->logs), 'test-secret-password'), 'Audit leaked password');
    echo "External TSA registration, encrypted credentials, rollback, diagnostics, inventory and live-finalizer B-T/strict/B-B paths passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
