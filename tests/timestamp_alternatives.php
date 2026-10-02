<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Timestamp\{OrderedTimestampProvider, TimestampProvider, InternalTimestampProvider, InternalTsaService, PolicyOidAsn1};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config};
require __DIR__ . '/external_timestamp_settings.php';

try {
    $before = [$f->settings, $f->logs];
    foreach ([[null, ['builtin-tsa']], [$sourceId, [$sourceId]], [$sourceId, ['builtin-tsa', 'builtin-tsa']],
        [$sourceId, ['builtin-tsa', 'missing-source']], [$sourceId, [1 => 'builtin-tsa']],
        [$sourceId, ['builtin-tsa', 'missing-source', 'other-source']]] as [$primary, $alternatives]) {
        rejects(fn() => $sources->savePolicy($providerId, $primary, false, $alternatives));
        check([$f->settings, $f->logs] === $before, 'Invalid order mutated policy or audit');
    }
    $f->onLog = static function ($message): void { if ($message === 'timestamp_source_admin') throw new RuntimeException('Audit failed'); };
    rejects(fn() => $sources->savePolicy($providerId, $sourceId, false, ['builtin-tsa']));
    check([$f->settings, $f->logs] === $before, 'Alternative policy rollback lost atomicity');
    $f->onLog = null;
    $sources->savePolicy($providerId, 'builtin-tsa', false, [$sourceId]);
    check(isset((new \DE\RUB\PDFSealerExternalModule\Pki\ExpiryInventory($f, $logs, $settings))->collect()[hash('sha256', $tsaRoot->certificateDer)]),
        'Alternative TSA trust root omitted from expiry inventory');
    $sources->savePolicy($providerId, $sourceId, false, ['builtin-tsa']);
    check($providers->provider($providerId)['timestamp_alternatives'] === ['builtin-tsa'], 'Order not persisted');

    // Live finalizer: remote primary fails, explicitly selected built-in alternative succeeds.
    HttpClient::$fail = true;
    file_put_contents($working, $sample);
    $calls = HttpClient::$calls;
    $result = $finalizer->finalize($working, ['id' => 'seal'], $context);
    check($result->isModified() && HttpClient::$calls === $calls + 1, 'Explicit internal alternative failed');
    $builtin = $identities->find($providers->source('builtin-tsa')['identity_id']);
    $verifier->verify($sample, file_get_contents($working), $active->certificateDer, $builtin->certificateDer);
    check(str_contains(json_encode(end(REDCap::$events)), 'alternative timestamp source'), 'Project log omitted alternative use');
    $outcomes = array_values(array_filter($f->logs, static fn($row) => $row['message'] === 'seal_timestamp_outcome'));
    $outcome = end($outcomes);
    check($outcome['timestamp_source'] === 'builtin-tsa'
        && json_decode($outcome['attempted_timestamp_sources'], true) === [$sourceId, 'builtin-tsa'], 'Alternative outcome lost source order');
    // A healthy primary short-circuits, even with an alternative configured.
    HttpClient::$fail = false;
    file_put_contents($working, $sample);
    $count = count($outcomes);
    check($finalizer->finalize($working, ['id' => 'seal'], $context)->isModified(), 'Primary with alternatives failed');
    $verifier->verify($sample, file_get_contents($working), $active->certificateDer, $tsa->certificateDer);
    check(count(array_filter($f->logs, static fn($row) => $row['message'] === 'seal_timestamp_outcome')) === $count, 'Primary logged alternative use');

    $request = (new Client(new Config('https://timestamp.invalid/')))->buildRequest('same signature');
    $successful = new InternalTimestampProvider(new InternalTsaService('1.2.3.4'), $identity);
    $seen = [];
    $make = static function (callable $respond): TimestampProvider {
        return new class($respond) implements TimestampProvider {
            public function __construct(private mixed $respond) {}
            public function policyOid(): string { return '1.2.3.4'; }
            public function respond(string $requestDer, int $now): string { return ($this->respond)($requestDer, $now); }
        };
    };
    $clock = 100.0;
    $ordered = new OrderedTimestampProvider([$sourceId, 'builtin-tsa'], function ($id, $deadline) use (&$seen, &$clock, $make, $successful, $sourceId) {
        $seen[] = [$id, $deadline - $clock];
        return $make(function ($der, $now) use ($id, &$seen, &$clock, $sourceId, $successful) {
            $seen[] = $der;
            if ($id === $sourceId) { $clock += 11; return 'malformed'; }
            return $successful->respond($der, $now);
        });
    }, static function () use (&$clock): float { return $clock; });
    $response = $ordered->respond($request->der, time());
    check($ordered->selectedSource() === 'builtin-tsa' && $ordered->attemptedSources() === [$sourceId, 'builtin-tsa'], 'Invalid response did not try next source');
    check($seen[1] === $request->der && $seen[3] === $request->der, 'Alternative changed signature imprint or nonce');
    check($seen[0][1] === 20.0 && $seen[2][1] === 9.0, 'Deadline reset for alternative');
    rejects(fn() => $ordered->respond($request->der, time()));

    // Deadline expiry while constructing a provider prevents a new request.
    $clock = 100.0; $requests = 0; $factories = 0;
    $expired = new OrderedTimestampProvider([$sourceId, 'builtin-tsa'], function () use (&$clock, &$requests, &$factories, $make, $successful) {
        $factories++; $clock += 20;
        return $make(function ($der, $now) use (&$requests, $successful) { $requests++; return $successful->respond($der, $now); });
    }, static function () use (&$clock): float { return $clock; });
    rejects(fn() => $expired->respond($request->der, time()));
    check($factories === 1 && $requests === 0 && $expired->selectedSource() === null, 'Expired budget started another request');

    // A valid but late response is not accepted, and the next source is skipped.
    $clock = 100.0;
    $late = new OrderedTimestampProvider([$sourceId, 'builtin-tsa'], function () use (&$clock, $make, $successful) {
        return $make(function ($der, $now) use (&$clock, $successful) { $clock += 20; return $successful->respond($der, $now); });
    }, static function () use (&$clock): float { return $clock; });
    rejects(fn() => $late->respond($request->der, time()));
    check($late->attemptedSources() === [$sourceId] && $late->selectedSource() === null, 'Late timestamp accepted');

    // Two explicitly selected external sources fail: strict failure preserves input;
    // B-B occurs only after exhaustion when explicitly allowed.
    $second = $sources->register('Second unavailable TSA', 'https://tsa.example.test/stamp',
        Certificate::derToPem($tsaRoot->certificateDer), '1.2.3.4', 'account', 'test-secret-password');
    HttpClient::$fail = false;
    $queries = [];
    HttpClient::$respond = static function ($query) use (&$queries, $identity) {
        $queries[] = $query;
        if (count($queries) === 1) { return 'invalid timestamp response'; }
        return (new InternalTsaService('1.2.3.4'))->respond($query, $identity, time());
    };
    $sources->savePolicy($providerId, $sourceId, false, [$second]);
    file_put_contents($working, $sample);
    check($finalizer->finalize($working, ['id' => 'seal'], $context)->isModified(), 'External alternative with distinct policy failed');
    $verifier->verify($sample, file_get_contents($working), $active->certificateDer, $tsa->certificateDer);
    $outcomes = array_values(array_filter($f->logs, static fn($row) => $row['message'] === 'seal_timestamp_outcome'));
    check(end($outcomes)['timestamp_source'] === $second && count($queries) === 2, 'External selected source not recorded');
    HttpClient::$fail = true;
    foreach ([false, true] as $bb) {
        $sources->savePolicy($providerId, $sourceId, $bb, [$second]);
        file_put_contents($working, $sample);
        $calls = HttpClient::$calls;
        $result = $finalizer->finalize($working, ['id' => 'seal'], $context);
        check(HttpClient::$calls === $calls + 2, 'Did not exhaust explicit order exactly once');
        if ($bb) {
            check($result->isModified(), 'Exhausted explicit B-B fallback failed');
            $verifier->verify($sample, file_get_contents($working), $active->certificateDer);
        } else {
            check(!$result->isModified() && file_get_contents($working) === $sample, 'Strict exhaustion modified PDF');
            $failures = array_values(array_filter($f->logs, static fn($row) => $row['message'] === 'seal_event'));
            check(json_decode(end($failures)['attempted_timestamp_sources'], true) === [$sourceId, $second], 'Failure lost attempt order');
        }
    }
    // A permanently blocked built-in primary uses only its explicitly configured external alternative.
    $internalSource=$providers->source('builtin-tsa');
    $internalIdentity=$identities->find($internalSource['identity_id']);
    (new DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock())->withLock(function()use($f,$identities,$internalIdentity,$internalSource){
        $f->query('START TRANSACTION',[]);
        $identities->tsaRevocations()->append(null,$internalIdentity,
            $identities->publicCertificate($internalSource['issuer_identity_id'],'root'),4,time(),$internalSource['issuer_identity_id']);
        $f->query('COMMIT',[]);
    });
    HttpClient::$fail=false;
    HttpClient::$respond=static fn(string $query):string=>(new InternalTsaService('1.2.3.4'))->respond($query,$identity,time());
    $sources->savePolicy($providerId,'builtin-tsa',false,[$second]);
    file_put_contents($working,$sample);$calls=HttpClient::$calls;
    check($finalizer->finalize($working,['id'=>'seal'],$context)->isModified() && HttpClient::$calls===$calls+1,
        'Revoked built-in primary did not reach the explicit external alternative');
    $verifier->verify($sample,file_get_contents($working),$active->certificateDer,$tsa->certificateDer);
    $outcomes=array_values(array_filter($f->logs,static fn($row)=>$row['message']==='seal_timestamp_outcome'));
    check(end($outcomes)['timestamp_source']===$second && json_decode(end($outcomes)['attempted_timestamp_sources'],true)===['builtin-tsa',$second],
        'Revoked-primary alternative lost source order');
    check(!str_contains(json_encode($f->logs), 'test-secret-password'), 'Order audit leaked secrets');
    echo "Ordered TSA policy, rollback, same request, validation, short-circuit, deadline, logging and B-T/strict/B-B finalizer checks passed.\n";
} finally { foreach ($f->paths as $path) { if (is_file($path)) unlink($path); } }
