<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Timestamp\{ExternalTimestampSources, TimestampSourceUnavailable, InternalTsaService, TsaPolicy};
use DE\RUB\PDFSealerExternalModule\Pdf\PdfFinalizeService;

// Real crypto over disposable storage/HTTP fixtures; no live service or database access.
require __DIR__ . '/external_timestamp_settings.php';
$held = [];
$externalTestLockQuery = static function(string $sql, array $params) use (&$held): Rows {
    $name = $params[0];
    if (str_contains($sql, 'GET_LOCK')) {
        if (isset($held[$name])) { return new Rows([[0]]); }
        $held[$name] = true;
    } else { check(isset($held[$name]), 'Release without lock'); unset($held[$name]); }
    return new Rows([[1]]);
};
$toggle = static function(bool $retired) use ($sources, $sourceId): array {
    return $sources->setRetired($sourceId, $retired, $sources->previewRetirement($sourceId)['review_hash']);
};
$assertUnavailable = static function(callable $work): void {
    try { $work(); } catch (TimestampSourceUnavailable) { return; }
    throw new RuntimeException('Expected unavailable external TSA');
};
try {
    HttpClient::$fail = false;
    HttpClient::$respond = static fn($query) => (new InternalTsaService(TsaPolicy::DEFAULT_OID))->respond($query, $identity, time());
    $sources->savePolicy($providerId, $sourceId, false, ['builtin-tsa']);
    $sources->savePolicy('builtin-ca', 'builtin-tsa', false, [$sourceId]);
    $f->allowIdentityReads = false;
    $before = [$f->settings, $f->logs, $decryptCalls, HttpClient::$calls];
    $preview = $sources->previewRetirement($sourceId);
    check(count($preview['providers']) === 2 && $preview['providers'][0]['position'] === 1 && $preview['providers'][1]['position'] === 0,
        'Preview missed primary/alternative usage');
    check($sources->previewRetirement($sourceId) === $preview && [$f->settings, $f->logs, $decryptCalls, HttpClient::$calls] === $before,
        'Review mutated, decrypted or contacted TSA');
    check(!str_contains(json_encode($preview), 'endpoint') && !str_contains(json_encode($preview), 'credentials')
        && !str_contains(json_encode($preview), 'test-secret-password'), 'Review leaked private configuration');
    $f->allowIdentityReads = true;
    // Fallback/policy changes invalidate the confirmation, even when source references remain.
    $sources->savePolicy($providerId, $sourceId, true, ['builtin-tsa']);
    $before = [$f->settings, $f->logs];
    rejects(fn() => $sources->setRetired($sourceId, true, $preview['review_hash']));
    check([$f->settings, $f->logs] === $before, 'Stale review changed state');
    $sources->savePolicy($providerId, $sourceId, false, ['builtin-tsa']);
    foreach (['', str_repeat('0', 64)] as $hash) { rejects(fn() => $sources->setRetired($sourceId, true, $hash)); }
    rejects(fn() => $sources->previewRetirement('builtin-tsa'));
    rejects(fn() => $sources->previewRetirement('remote-tsa-' . str_repeat('0', 16)));
    $before = [$f->settings, $f->logs];
    $f->onLog = static function(string $message): void { if ($message === 'timestamp_source_admin') { throw new RuntimeException('Audit failed'); } };
    rejects(fn() => $toggle(true)); $f->onLog = null;
    check([$f->settings, $f->logs] === $before && $f->snapshot === null, 'Audit failure partially retired source');
    $f->onSettingWrite = static function(string $key) use ($sourceId): void {
        if ($key === 'tsa_source_lifecycle_' . $sourceId) { throw new RuntimeException('Write failed'); }
    };
    rejects(fn() => $toggle(true)); $f->onSettingWrite = null;
    check([$f->settings, $f->logs] === $before, 'Failed write lost rollback');
    foreach (['START TRANSACTION', 'COMMIT'] as $statement) {
        $wrapper = new class($f, $statement) {
            public function __construct(private object $inner, private string $failure) {}
            public function __call(string $name, array $args): mixed { return $this->inner->$name(...$args); }
            public function query(string $sql, array $params): bool { return $sql === $this->failure ? false : $this->inner->query($sql, $params); }
        };
        $service = new ExternalTimestampSources($wrapper, $settings);
        rejects(fn() => $service->setRetired($sourceId, true, $service->previewRetirement($sourceId)['review_hash']));
        check([$f->settings, $f->logs] === $before && $f->snapshot === null, 'Transaction failure lost rollback');
    }
    // Revision persists across retirement/reactivation; captured providers never revive.
    $captured = $sources->provider($sourceId);
    $policyBefore = [$f->settings['ca_provider_' . $providerId], $f->settings['ca_provider_builtin-ca']];
    $observation = $sources->snapshot($sourceId); $review = $sources->previewRetirement($sourceId);
    $state = $toggle(true);
    check($state === ['retired' => true, 'revision' => 1] && $sources->summaries()[0]['retired'], 'Retirement status missing');
    check([$f->settings['ca_provider_' . $providerId], $f->settings['ca_provider_builtin-ca']] === $policyBefore
        && $sources->snapshot($sourceId) === $observation, 'Retirement changed policies or history');
    $calls = HttpClient::$calls; $decryptBefore = $decryptCalls;
    $assertUnavailable(fn() => $sources->provider($sourceId));
    $assertUnavailable(fn() => $sources->diagnose($sourceId));
    $assertUnavailable(fn() => $captured->respond($probe->buildRequest('test')->der, time()));
    check(HttpClient::$calls === $calls && $decryptCalls === $decryptBefore, 'Retired source made request/decrypted credentials');
    rejects(fn() => $sources->setRetired($sourceId, false, $review['review_hash']));
    // Existing roles can be retained/remediated; a retired source cannot be assigned in a new role or CA.
    $sources->savePolicy($providerId, $sourceId, false, ['builtin-tsa']);
    $sources->savePolicy('builtin-ca', 'builtin-tsa', true, [$sourceId]);
    rejects(fn() => $sources->savePolicy('builtin-ca', $sourceId, false));
    rejects(fn() => $sources->savePolicy($providerId, 'builtin-tsa', false, [$sourceId]));
    $assertUnavailable(fn() => $providers->registerExternal('Retired TSA assignment', [], $sourceId, false));
    // Retired primary skips HTTP, uses the explicit alternative, then B-B only when allowed.
    file_put_contents($working, $sample); $calls = HttpClient::$calls;
    $result = $finalizer->finalize($working, ['id' => 'seal'], $context);
    $builtin = $identities->find($providers->source('builtin-tsa')['identity_id']);
    check($result->isModified() && $result->getMetadata()['seal_profile'] === 'pades-b-t' && HttpClient::$calls === $calls,
        'Retired primary failed to use selected alternative');
    $verifier->verify($sample, file_get_contents($working), $active->certificateDer, $builtin->certificateDer);
    foreach ([false, true] as $fallback) {
        $sources->savePolicy($providerId, $sourceId, $fallback); file_put_contents($working, $sample);
        $result = $finalizer->finalize($working, ['id' => 'seal'], $context);
        check($result->isModified() === $fallback && HttpClient::$calls === $calls, 'Retirement silently contacted or substituted a TSA');
        if ($fallback) { check($result->getMetadata()['seal_profile'] === 'pades-b-b', 'Wrong fallback profile'); }
        else { check(file_get_contents($working) === $sample, 'Strict failure changed bytes'); }
    }
    $state = $toggle(false); check($state === ['retired' => false, 'revision' => 2], 'Reactivation lost revision');
    $assertUnavailable(fn() => $captured->respond($probe->buildRequest('test')->der, time()));
    $sources->savePolicy($providerId, $sourceId, false);
    $sources->provider($sourceId)->respond($probe->buildRequest('test')->der, time());
    // Lifecycle changes during the HTTP response reject that response, even across immediate reactivation.
    foreach ([false, true] as $reactivate) {
        HttpClient::$respond = static function($query) use ($identity, $toggle, $reactivate): string {
            $response = (new InternalTsaService(TsaPolicy::DEFAULT_OID))->respond($query, $identity, time());
            $toggle(true); if ($reactivate) { $toggle(false); }
            return $response;
        };
        $assertUnavailable(fn() => $sources->provider($sourceId)->respond($probe->buildRequest('test')->der, time()));
        if (!$reactivate) { $toggle(false); }
    }
    // An interrupted probe cannot overwrite the dated observation, even after immediate reactivation.
    foreach ([false, true] as $reactivate) {
        $observation = $sources->snapshot($sourceId);
        HttpClient::$respond = static function($query) use ($identity, $toggle, $reactivate): string {
            $response = (new InternalTsaService(TsaPolicy::DEFAULT_OID))->respond($query, $identity, time());
            $toggle(true); if ($reactivate) { $toggle(false); }
            return $response;
        };
        $assertUnavailable(fn() => $sources->diagnose($sourceId));
        check($sources->snapshot($sourceId) === $observation, 'Lifecycle-raced probe overwrote historical observation');
        if (!$reactivate) { $toggle(false); }
    }
    // Successful response cannot be published after retirement between token generation and acceptance.
    foreach ([false, true] as $reactivate) {
        $remoteCompleted = false;
        HttpClient::$respond = static function($query) use ($identity, &$remoteCompleted): string {
            $response = (new InternalTsaService(TsaPolicy::DEFAULT_OID))->respond($query, $identity, time());
            $remoteCompleted = true; return $response;
        };
        $raceFramework = new class($f, $held, $remoteCompleted, $toggle, $reactivate) {
            public bool $fired = false;
            public function __construct(private object $inner, private array &$locks, private bool &$completed,
                private Closure $toggle, private bool $reactivate) {}
            public function __call(string $name, array $args): mixed { return $this->inner->$name(...$args); }
            public function getQueryLogsSql(string $sql): string {
                if (!$this->fired && $this->completed && str_contains($sql, 'revoked_at')
                    && isset($this->locks['pdf_sealer_issue_104']) && !isset($this->locks['pdf_sealer_initialize'])) {
                    $this->fired = true; ($this->toggle)(true); if ($this->reactivate) { ($this->toggle)(false); }
                }
                return $this->inner->getQueryLogsSql($sql);
            }
        };
        file_put_contents($working, $sample);
        $result = (new PdfFinalizeService($raceFramework))->finalize($working, ['id' => 'seal'], $context);
        check($raceFramework->fired && $result->getErrorCode() === 'TSA_SOURCE_UNAVAILABLE' && file_get_contents($working) === $sample,
            'Captured external TSA passed the acceptance boundary after lifecycle change');
        if (!$reactivate) { $toggle(false); }
    }
    // Holding the configuration lock through acceptance prevents a competing retirement.
    $remoteCompleted = false;
    $guardedFramework = new class($f, $held, $remoteCompleted, $toggle) {
        public bool $checked = false;
        public function __construct(private object $inner, private array &$locks, private bool &$completed, private Closure $toggle) {}
        public function __call(string $name, array $args): mixed { return $this->inner->$name(...$args); }
        public function getQueryLogsSql(string $sql): string {
            if (!$this->checked && $this->completed && str_contains($sql, 'revoked_at')
                && isset($this->locks['pdf_sealer_issue_104'], $this->locks['pdf_sealer_initialize'])) {
                $this->checked = true; rejects(fn() => ($this->toggle)(true));
            }
            return $this->inner->getQueryLogsSql($sql);
        }
    };
    // Rebind the HTTP completion marker for this final request.
    HttpClient::$respond = static function($query) use ($identity, &$remoteCompleted): string {
        $response = (new InternalTsaService(TsaPolicy::DEFAULT_OID))->respond($query, $identity, time()); $remoteCompleted = true; return $response;
    };
    file_put_contents($working, $sample);
    check((new PdfFinalizeService($guardedFramework))->finalize($working, ['id' => 'seal'], $context)->isModified()
        && $guardedFramework->checked && !$sources->lifecycle($sourceId)['retired'], 'Acceptance did not serialize retirement');
    $accepted = file_get_contents($working); $toggle(true); check(file_get_contents($working) === $accepted, 'Retirement rewrote accepted PDF'); $toggle(false);
    check($held === [] && !str_contains(json_encode($f->logs), 'test-secret-password'), 'Locks leaked or audit exposed credentials');
    $saved = $f->settings['tsa_source_lifecycle_' . $sourceId];
    foreach (['false', '{"retired":"false","revision":1}', '{"retired":false,"revision":0}'] as $invalid) {
        $f->settings['tsa_source_lifecycle_' . $sourceId] = $invalid; rejects(fn() => $sources->assertUsable($sourceId));
    }
    $f->settings['tsa_source_lifecycle_' . $sourceId] = $saved;
    echo "External TSA retirement: public impact, stale/replayed reviews, rollback, assignment/probe guards, alternatives/B-B, generation/acceptance races and reactivation passed.\n";
} finally { $f->onLog = $f->onSettingWrite = null; foreach ($f->paths as $path) { if (is_file($path)) { unlink($path); } } }
