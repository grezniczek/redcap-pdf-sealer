<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pki;

use Closure;
use DE\RUB\PDFSealerExternalModule\Alerts\{AdminAlarmService, AlarmLock};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\{Certificate, SignedDataVerifier};
use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Timestamp\{Client, Config};
use DE\RUB\PDFSealerExternalModule\Timestamp\{InternalTimestampProvider, InternalTsaService, PolicyOidAsn1, TsaIdentity};
use RuntimeException;
use Throwable;

/** Hourly same-key root and fresh-key leaf maintenance; never initializes or resets missing PKI. */
final class BuiltinMaintenanceService
{
    public const SETTING = 'last-builtin-maintenance';
    public const PROJECT_LIMIT = 5;
    private const BUDGET = 60;
    private Closure $monotonic;

    public function __construct(
        private readonly object $framework,
        private readonly IdentityRepository $identities,
        private readonly ProjectBindingRepository $bindings,
        private readonly SecretProtector $protector,
        private readonly CertificateIssuer $issuer,
        private readonly PkiHealthService $health,
        private readonly ProjectRenewalService $renewal,
        private readonly PkiInitializationLock $configurationLock,
        private readonly AlarmLock $runLock,
        private readonly AdminAlarmService $alarms,
        private readonly PrimarySystemSettingReader $settings,
        ?callable $monotonic = null,
    ) {
        $this->monotonic = $monotonic === null ? static fn(): float => hrtime(true) / 1e9 : Closure::fromCallable($monotonic);
    }

    public function run(?int $now = null): array
    {
        $now ??= time();
        if ($now < 1) { throw new RuntimeException('Invalid maintenance time'); }
        // Serialize cron runs, but release the configuration lock before project work.
        return $this->runLock->withLock(hash('sha256', 'builtin-maintenance'), function () use ($now): array {
            $result = ['completed_at' => $now, 'status' => 'ok', 'renewed' => 0, 'deferred' => 0,
                'failed' => 0, 'remaining' => 0, 'items' => [], 'retries' => [], 'root_identity_id' => null];
            $deadline = ($this->monotonic)() + self::BUDGET;
            try {
                $previous = self::load($this->settings);
                $result['retries'] = $previous['retries'] ?? [];
                $rootId = $this->identities->activeId('root');
                $result['root_identity_id'] = $rootId;
                if ($rootId !== ($previous['root_identity_id'] ?? null)) { $result['retries'] = []; }
                if ($rootId === null) {
                    if ($this->identities->hasRole('root') || $this->identities->providers()->hasConfiguration()) {
                        throw new RuntimeException('Root reference missing');
                    }
                    $result['status'] = 'uninitialized';
                } else {
                    if ($this->readyToRetry($result, 'root', $rootId, $now)) {
                        $rootRenewal = new RootRenewalService($this->framework, $this->identities, $this->protector,
                            $this->issuer, $this->health, new CrlRepository($this->framework, $this->settings), $this->configurationLock);
                        $this->attempt($result, 'root', $rootId, null, $now, fn(): string => $rootRenewal->renewIfDue($now));
                        $currentRootId = $this->identities->activeId('root');
                        if ($currentRootId !== $rootId) { $result['retries'] = []; }
                        $result['root_identity_id'] = $currentRootId;
                    } else { ++$result['remaining']; }
                    $currentRootId = $this->identities->activeId('root');
                    if ($this->readyToRetry($result, 'crl', $currentRootId, $now)) {
                        $publication = new CrlPublicationService($this->framework,
                            new PublicTrustRepository(new PrimaryLogReader($this->framework), $this->settings),
                            $this->identities, $this->protector, new CrlRepository($this->framework, $this->settings),
                            $this->configurationLock);
                        $this->attempt($result, 'crl', $currentRootId, null, $now,
                            fn(): string => $publication->run(max($now, time()), true)['published'] > 0 ? 'published' : 'skipped');
                    } else { ++$result['remaining']; }
                    $source = $this->identities->providers()->source(ProviderRepository::BUILTIN_TSA);
                    if ($this->readyToRetry($result, 'tsa', $source['identity_id'], $now)) {
                        $this->attempt($result, 'tsa', $source['identity_id'], null, $now,
                            fn(): string => $this->renewTsa($now));
                    } else { ++$result['remaining']; }
                    $enabled = array_fill_keys(array_map('intval', $this->framework->getProjectsWithModuleEnabled()), true);
                    $provider = $this->identities->providers()->provider(ProviderRepository::BUILTIN_CA);
                    $candidates = []; $currentKeys = ['root' => true, 'crl' => true, 'tsa' => true];
                    foreach ($this->bindings->providerUsage(ProviderRepository::BUILTIN_CA) as $usage) {
                        $pid = $usage['pid'];
                        $binding = $this->bindings->find($pid);
                        if ($binding?->providerId !== ProviderRepository::BUILTIN_CA || $binding->identityId === null) { continue; }
                        $identity = $this->identities->find($binding->identityId);
                        if ($identity === null || $identity->role !== 'project' || $identity->issuerId === null) {
                            throw new RuntimeException('Maintenance identity unavailable');
                        }
                        $currentKeys['project-' . $pid] = true;
                        $expires = (new Certificate())->fields($identity->certificateDer)['not_after'];
                        $revocation = $this->identities->revocations()->find($identity);
                        if ($revocation === null && !LeafRenewalPolicy::due($expires, $identity->issuerId, $provider['issuer_identity_id'], $now)) {
                            unset($result['retries']['project-' . $pid]);
                            continue;
                        }
                        $candidates[] = ['pid' => $pid, 'id' => $identity->id, 'expires' => $expires, 'enabled' => isset($enabled[$pid]),
                            'revoked_at' => $revocation === null ? null : (int) $revocation['revoked_at']];
                    }
                    $result['retries'] = array_intersect_key($result['retries'], $currentKeys);
                    usort($candidates, static fn(array $a, array $b): int =>
                        [$a['revoked_at'] === null, !$a['enabled'], $a['expires'], $a['pid']]
                        <=> [$b['revoked_at'] === null, !$b['enabled'], $b['expires'], $b['pid']]);
                    $attempted = 0;
                    foreach ($candidates as $candidate) {
                        $key = 'project-' . $candidate['pid'];
                        if ($attempted >= self::PROJECT_LIMIT || ($this->monotonic)() >= $deadline
                            || !$this->readyToRetry($result, $key, $candidate['id'], $now, $candidate['revoked_at'])) {
                            ++$result['remaining']; continue;
                        }
                        ++$attempted;
                        $this->attempt($result, $key, $candidate['id'], $candidate['pid'], $now,
                            fn(): string => $this->renewal->renewAutomatically($candidate['pid'], $now), $candidate['revoked_at']);
                    }
                }
            } catch (Throwable) {
                ++$result['failed'];
                $result['items'][] = ['role' => 'system', 'pid' => null, 'status' => 'failed'];
            }
            if ($result['failed'] > 0) { $result['status'] = 'failed'; }
            elseif ($result['deferred'] > 0 || $result['remaining'] > 0) { $result['status'] = 'pending'; }
            $this->framework->setSystemSetting(self::SETTING, json_encode($result, JSON_THROW_ON_ERROR));
            if ($result['failed'] > 0 || $result['deferred'] > 0) {
                try {
                    $this->alarms->raise($result['failed'] > 0 ? 'BUILTIN_MAINTENANCE_FAILED' : 'BUILTIN_MAINTENANCE_DEFERRED',
                        $result['failed'] > 0 ? 'critical' : 'degraded', null, $now);
                } catch (Throwable) { /* Cron result remains available independently of mail delivery. */ }
            }
            return $result;
        });
    }

    private function readyToRetry(array &$result, string $key, string $identityId, int $now, ?int $revokedAt = null): bool
    {
        $retry = $result['retries'][$key] ?? null;
        if ($retry !== null && ($retry['identity_id'] !== $identityId || ($retry['revoked_at'] ?? null) !== $revokedAt)) { unset($result['retries'][$key]); return true; }
        return $retry === null || $retry['retry_at'] <= $now;
    }

    private function attempt(array &$result, string $key, string $identityId, ?int $pid, int $now, callable $work, ?int $revokedAt = null): void
    {
        $role = in_array($key, ['root', 'crl'], true) ? $key : ($pid === null ? 'tsa' : 'project');
        try { $status = $work(); }
        catch (Throwable) { $status = 'failed'; }
        if (in_array($status, ['failed', 'deferred'], true)) {
            $attempts = min(6, ($result['retries'][$key]['attempts'] ?? 0) + 1);
            $result['retries'][$key] = ['identity_id' => $identityId, 'attempts' => $attempts,
                'revoked_at' => $revokedAt, 'retry_at' => $now + ($status === 'deferred' ? 3600 : min(86400, 3600 * 2 ** ($attempts - 1)))];
            ++$result[$status];
            try {
                $this->framework->log('builtin_maintenance_outcome', [
                    'project_id' => null, 'record' => '', 'actor' => 'system:cron',
                    'redcap_pid' => $pid === null ? '' : (string) $pid, 'identity_id' => $identityId,
                    'operation' => $role . '_renewal', 'status' => $status,
                    'retry_at' => (string) $result['retries'][$key]['retry_at'],
                ]);
            } catch (Throwable) { /* Keep the failure/retry snapshot even if its supplemental log fails. */ }
        } else {
            unset($result['retries'][$key]);
            if ($status === 'renewed') { $result['renewed'] += $role === 'root' ? 2 : 1; }
        }
        $result['items'][] = ['role' => $role, 'pid' => $pid, 'status' => $status];
    }

    private function renewTsa(int $now): string
    {
        return $this->configurationLock->withLock(function () use ($now): string {
            $now = max($now, time());
            $providers = $this->identities->providers();
            $source = $providers->source(ProviderRepository::BUILTIN_TSA);
            $rootId = $this->identities->activeId('root');
            if ($source['identity_id'] !== $this->identities->activeId('tsa')
                || $providers->provider(ProviderRepository::BUILTIN_CA)['issuer_identity_id'] !== $rootId) {
                throw new RuntimeException('Built-in references disagree');
            }
            $tsaDer = $this->identities->publicCertificate($source['identity_id'], 'tsa');
            $fields = (new Certificate())->fields($tsaDer);
            if (!LeafRenewalPolicy::due($fields['not_after'], $source['issuer_identity_id'], $rootId, $now)) { return 'skipped'; }
            if ($this->health->inspectIssuance($rootId, $now)->status !== PkiHealth::Ready) {
                throw new RuntimeException('Root is unavailable for TSA renewal');
            }
            $root = $this->identities->find($rootId);
            $rootFields = (new Certificate())->fields($root->certificateDer);
            LeafRenewalPolicy::assertIssuerWindow($rootFields['not_after'], $now);
            $oldIssuer = (new Certificate())->fields($this->identities->publicCertificate($source['issuer_identity_id'], 'root'));
            $this->health->captureTimestamp($source, max($fields['not_before'], $oldIssuer['not_before']));
            $organization = openssl_x509_parse(Certificate::derToPem($root->certificateDer))['subject']['O'] ?? null;
            if (!is_string($organization) || $organization === '') { throw new RuntimeException('Root organization missing'); }
            $generated = $this->issuer->createTsa($organization, $root->asGeneratedIdentity($this->protector));
            $provider = new InternalTimestampProvider(new InternalTsaService($source['policy_oid']),
                new TsaIdentity($generated->certificateDer, $generated->privateKey(), [$root->certificateDer]),
                static fn(): int => max($now, time()));
            $client = new Client(new Config('http://localhost.invalid/tsa'), new PolicyOidAsn1($source['policy_oid']));
            $request = $client->buildRequest(random_bytes(32));
            $sampleNow = max($now, time());
            $token = $client->parseResponse($provider->respond($request->der, $sampleNow), $request, $sampleNow);
            if ((new SignedDataVerifier(requireSigningCertificate: true))->verify($token) !== $generated->certificateDer) {
                throw new RuntimeException('Replacement TSA sample failed');
            }
            if ($this->framework->query('START TRANSACTION', []) === false) { throw new RuntimeException('TSA transaction failed'); }
            try {
                $id = $this->identities->append('tsa', $generated);
                $this->identities->activate('tsa', $id);
                $updated = $source; $updated['identity_id'] = $id; $updated['issuer_identity_id'] = $rootId;
                $this->framework->setSystemSetting('tsa_source_' . ProviderRepository::BUILTIN_TSA, json_encode($updated, JSON_THROW_ON_ERROR));
                $this->health->captureTimestamp($updated, max($now, time())); // Includes encrypted-key round trip before activation commits.
                $audit = $this->framework->log('tsa_certificate_renewal', [
                    'project_id' => null, 'record' => '', 'actor' => 'system:cron', 'reason' => 'automatic',
                    'previous_identity_id' => $source['identity_id'], 'identity_id' => $id, 'issuer_identity_id' => $rootId,
                    'previous_certificate_sha256' => hash('sha256', $tsaDer), 'certificate_sha256' => hash('sha256', $generated->certificateDer),
                ]);
                if ((!is_int($audit) && !ctype_digit((string) $audit)) || (int) $audit < 1) { throw new RuntimeException('TSA audit failed'); }
                if ($this->framework->query('COMMIT', []) === false) { throw new RuntimeException('TSA commit failed'); }
                return 'renewed';
            } catch (Throwable $e) {
                $this->framework->query('ROLLBACK', []);
                throw $e;
            }
        });
    }

    public static function load(PrimarySystemSettingReader $settings): ?array
    {
        $raw = $settings->get(self::SETTING);
        if ($raw === null) { return null; }
        $value = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
        if (!is_array($value) || !is_int($value['completed_at'] ?? null) || $value['completed_at'] < 1
            || !in_array($value['status'] ?? null, ['ok', 'pending', 'failed', 'uninitialized'], true)
            || !is_array($value['retries'] ?? null) || !is_array($value['items'] ?? null)) {
            throw new RuntimeException('Invalid maintenance snapshot');
        }
        $rootId = $value['root_identity_id'] ?? null;
        if ($rootId !== null && (!is_string($rootId) || preg_match('/^[0-9a-f]{32}$/D', $rootId) !== 1)) {
            throw new RuntimeException('Invalid maintenance root version');
        }
        foreach (['renewed', 'deferred', 'failed', 'remaining'] as $count) {
            if (!is_int($value[$count] ?? null) || $value[$count] < 0) { throw new RuntimeException('Invalid maintenance count'); }
        }
        foreach ($value['retries'] as $key => $retry) {
            if (!is_string($key) || preg_match('/^(?:root|crl|tsa|project-[1-9][0-9]*)$/D', $key) !== 1 || !is_array($retry)
                || !is_string($retry['identity_id'] ?? null) || preg_match('/^[0-9a-f]{32}$/D', $retry['identity_id']) !== 1
                || !is_int($retry['attempts'] ?? null) || $retry['attempts'] < 1 || $retry['attempts'] > 6
                || !is_int($retry['retry_at'] ?? null) || $retry['retry_at'] < 1
                || (isset($retry['revoked_at']) && (!is_int($retry['revoked_at']) || $retry['revoked_at'] < 1))) {
                throw new RuntimeException('Invalid maintenance retry');
            }
        }
        return $value;
    }
}
