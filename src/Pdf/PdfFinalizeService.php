<?php

declare(strict_types=1);

namespace DE\RUB\PDFSealerExternalModule\Pdf;

use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmLock;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmRepository;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthReport;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use ExternalModules\PdfFinalizeResult;
use RuntimeException;
use Throwable;

/** Applies one terminal seal to the Framework-owned PDF working copy. */
final class PdfFinalizeService
{
    public function __construct(
        private readonly object $framework,
        private readonly PdfSealBuilder $builder = new PdfSealBuilder(),
    ) {}

    public function finalize(string $path, array $operation, array $context): PdfFinalizeResult
    {
        if (($operation['id'] ?? null) !== 'seal' || ($context['document_type'] ?? null) !== 'econsent') {
            return PdfFinalizeResult::unchanged();
        }
        $events = new SealEventRepository($this->framework);
        $pid = $context['project_id'] ?? null;
        $event = [
            'generation_id' => is_string($context['generation_id'] ?? null) ? $context['generation_id'] : null,
            'pid' => (is_int($pid) || (is_string($pid) && ctype_digit($pid))) ? (string) $pid : null,
            'project_uuid' => null,
            'certificate_identity_id' => null,
            'certificate_serial' => null,
            'certificate_sha256' => null,
            'input_sha256' => null,
            'output_sha256' => null,
            'profile' => 'failed',
            'timestamp_source' => 'none',
            'timestamp_serial' => null,
            'timestamp_time' => null,
            'fallback_used' => '0',
            'success' => '0',
            'error_code' => null,
            'error_message' => null,
        ];
        $ambientPid = $this->framework->getProjectId();
        if ((!is_int($pid) && (!is_string($pid) || !ctype_digit($pid))) || (int) $pid < 1
            || ($ambientPid !== null && (int) $pid !== (int) $ambientPid)) {
            return $this->failed($events, $event, $context, null, 'INVALID_CONTEXT', 'PDF seal project context is invalid');
        }
        try {
            $protector = new SecretProtector();
            $identities = new IdentityRepository($this->framework, $protector);
            $health = new PkiHealthService($identities, $protector);
            $bindings = new ProjectBindingRepository($this->framework);
            $providers = $identities->providers();
            $source = file_get_contents($path);
            if (!is_string($source)) {
                return $this->failed($events, $event, $context, (int) $pid, 'INPUT_READ_FAILED', 'PDF working copy is unreadable');
            }
            $event['input_sha256'] = hash('sha256', $source);

            $projects = new ProjectIdentityService(
                $bindings, $identities, $protector,
                CertificateIssuer::forFramework($this->framework), $health, new ProjectIssueLock(),
            );
            $project = $projects->getOrIssue((int) $pid);
            $issuerChain = $projects->issuerChain($project);
            $provider = $providers->provider($project->providerId);
            // Keep order and fallback from the same provider record throughout this seal.
            $event['attempted_timestamp_source'] = $provider['timestamp_source'] ?? 'none';
            $mode = $provider['timestamp_source'] === null ? 'none' : 'timestamp';
            $fallback = $provider['bb_fallback'];
            if ($provider['kind'] === 'internal') {
                $issuanceHealth = $health->inspectIssuance($provider['issuer_identity_id'], time());
                if ($issuanceHealth->status !== PkiHealth::Ready) { $this->alarm($issuanceHealth); }
            }

            $certificate = openssl_x509_parse(\DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate::derToPem($project->certificateDer));
            if (!is_array($certificate) || !is_string($certificate['serialNumberHex'] ?? null)) {
                throw new RuntimeException('Project certificate serial is unavailable');
            }
            $event['project_uuid'] = $project->projectUuid;
            $event['certificate_identity_id'] = $project->id;
            $event['certificate_serial'] = strtoupper($certificate['serialNumberHex']);
            $event['certificate_sha256'] = hash('sha256', $project->certificateDer);
            $key = $project->privateKey($protector);
            $now = self::requestTime();
            $timestampChain = [];
            $externalTimestampState = null;
            if ($mode === 'none') {
                $sealed = $this->builder->seal($source, $project->certificateDer, $key, $issuerChain, $now);
                $result = new PdfSealResult($sealed, 'pades-b-b');
            } else {
                $result = $this->sealWithFallback(
                    $source, $project->certificateDer, $key, $issuerChain,
                    $identities, $health, [$provider['timestamp_source'], ...$provider['timestamp_alternatives']], $fallback, $now, $event, $timestampChain, $externalTimestampState,
                );
            }
            $accept = function () use ($path, $result, $mode, $event, $events, $context, $pid): PdfFinalizeResult {
                $written = file_put_contents($path, $result->pdf, LOCK_EX);
                if ($written !== strlen($result->pdf)) {
                    return $this->failed($events, $event, $context, (int) $pid, 'OUTPUT_WRITE_FAILED', 'Could not write sealed PDF working copy');
                }
                $fallbackUsed = $mode !== 'none' && $result->profile === 'pades-b-b';
                try {
                    self::logProjectOutcome(
                        (int) $pid, $context, 'PDF seal succeeded',
                        'Profile: ' . ($result->profile === 'pades-b-t' ? 'PAdES B-T' : 'PAdES B-B')
                            . ($fallbackUsed ? ' (timestamp fallback)' : (($event['alternative_used'] ?? '0') === '1' ? ' (alternative timestamp source)' : '')),
                    );
                } catch (Throwable $e) {
                    error_log('PDF Sealer project logging failed: ' . get_class($e));
                    return $this->failed($events, $event, $context, (int) $pid,
                        'PROJECT_LOG_FAILED', 'Could not record PDF seal outcome');
                }
                if ($fallbackUsed || ($event['alternative_used'] ?? '0') === '1') {
                    try {
                        $events->appendTimestampOutcome([
                            'pid' => (string) $pid, 'generation_id' => $event['generation_id'],
                            'attempted_timestamp_sources' => $event['attempted_timestamp_sources'] ?? '[]',
                            'timestamp_source' => $result->profile === 'pades-b-t' ? $event['timestamp_source'] : 'none',
                            'profile' => $result->profile,
                        ]);
                    } catch (Throwable $e) { error_log('PDF Sealer timestamp outcome logging failed: ' . get_class($e)); }
                }
                return PdfFinalizeResult::modified($path, true, [
                    'seal_profile' => $result->profile,
                    'timestamp_serial' => $result->timestampSerialHex,
                    'timestamp_time' => $result->timestampTime,
                ]);
            };
            return $projects->acceptSeal((int) $pid, $project, function () use ($identities, $event, $result, $accept, $timestampChain, $externalTimestampState) {
                if ($result->profile !== 'pades-b-t') { return $accept(); }
                if ($externalTimestampState !== null) {
                    // The project -> configuration locks serialize acceptance against retirement/reactivation.
                    (new \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($this->framework))
                        ->assertUsable($externalTimestampState['id'], $externalTimestampState['revision']);
                }
                $identities->rootRevocations()->assertChain($timestampChain);
                if (!isset($event['timestamp_identity_id'])) { return $accept(); }
                // ProjectIdentityService holds project -> configuration locks through acceptance.
                $tsa = $identities->find($event['timestamp_identity_id']);
                if ($tsa === null || $tsa->role !== 'tsa') { throw new RuntimeException('Captured TSA unavailable'); }
                $identities->rootRevocations()->assertNotRevoked($identities->publicCertificate($event['timestamp_issuer_identity_id'], 'root'));
                $identities->tsaRevocations()->assertNotRevoked($tsa);
                return $accept();
            });
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\RootRevoked) {
            return $this->failed($events, $event, $context, (int) $pid, 'ROOT_CA_REVOKED', 'Built-in CA issuing key revoked; replacement pending');
        } catch (\DE\RUB\PDFSealerExternalModule\Timestamp\TimestampSourceUnavailable) {
            return $this->failed($events, $event, $context, (int) $pid, 'TSA_SOURCE_UNAVAILABLE', 'External TSA lifecycle changed during sealing; retry with the configured timestamp sources');
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\TsaRevoked) {
            return $this->failed($events, $event, $context, (int) $pid, 'TSA_CERTIFICATE_REVOKED', 'TSA certificate revoked during sealing; retry with the configured timestamp sources');
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\ProjectRevoked) {
            return $this->failed($events, $event, $context, (int) $pid, 'PROJECT_CERTIFICATE_REVOKED', 'Project certificate revoked; replacement pending');
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\ProviderTransitionPending) {
            return $this->failed($events, $event, $context, (int) $pid, 'PROVIDER_TRANSITION_PENDING', 'Provider transition awaits certificate activation');
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\CaProviderRetired) {
            return $this->failed($events, $event, $context, (int) $pid, 'CA_PROVIDER_RETIRED', 'CA provider retired');
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\CaAssignmentRequired) {
            return $this->failed($events, $event, $context, (int) $pid, 'CA_ASSIGNMENT_REQUIRED', 'CA assignment required');
        } catch (\DE\RUB\PDFSealerExternalModule\Pki\ProjectCertificateRequired) {
            return $this->failed($events, $event, $context, (int) $pid, 'PROJECT_CERTIFICATE_REQUIRED', 'External CA project certificate is required');
        } catch (Throwable $e) {
            if (isset($health)) { $this->alarm($health->inspect(time())); }
            error_log('PDF Sealer finalization failed: ' . get_class($e));
            return $this->failed($events, $event, $context, (int) $pid, 'PDF_SEAL_FAILED', 'PDF sealing failed');
        }
    }

    /** @param array<string, string|null> $event */
    private function failed(SealEventRepository $events, array $event, array $context, ?int $projectLogPid, string $code, string $message): PdfFinalizeResult
    {
        $event['profile'] = 'failed';
        $event['timestamp_source'] = 'none';
        $event['timestamp_serial'] = null;
        $event['timestamp_time'] = null;
        $event['output_sha256'] = null;
        $event['success'] = '0';
        $event['error_code'] = $code;
        $event['error_message'] = $message;
        try {
            $events->appendFailure($event);
        } catch (Throwable $e) {
            error_log('PDF Sealer failure diagnostics failed: ' . get_class($e));
        }
        if ($projectLogPid !== null) {
            try {
                self::logProjectOutcome(
                    $projectLogPid, $context, match ($code) {
                        'PROJECT_CERTIFICATE_REVOKED' => 'PDF seal failed: project certificate revoked',
                        'CA_ASSIGNMENT_REQUIRED' => 'PDF seal failed: CA assignment required',
                        'CA_PROVIDER_RETIRED' => 'PDF seal failed: CA provider retired',
                        'PROVIDER_TRANSITION_PENDING' => 'PDF seal failed: provider transition pending',
                        default => 'PDF seal failed',
                    },
                    $event['generation_id'] === null ? '' : 'Reference: ' . $event['generation_id'],
                );
            } catch (Throwable $e) {
                error_log('PDF Sealer project logging failed: ' . get_class($e));
            }
        }
        return PdfFinalizeResult::failed($code, $message);
    }

    /** @param array<string, mixed> $context */
    private static function logProjectOutcome(int $pid, array $context, string $description, string $details): void
    {
        $record = $context['record_id'] ?? null;
        $record = (is_string($record) || is_int($record)) && (string) $record !== ''
            ? (string) $record : null;
        $eventId = $context['event_id'] ?? null;
        $eventId = (is_int($eventId) || (is_string($eventId) && ctype_digit($eventId)))
            && (int) $eventId > 0 ? (int) $eventId : null;

        // REDCap::logEvent otherwise inherits a query-string event ID when none is supplied.
        $hadQueryEventId = array_key_exists('event_id', $_GET);
        $queryEventId = $_GET['event_id'] ?? null;
        if ($eventId === null) {
            unset($_GET['event_id']);
        }
        try {
            \REDCap::logEvent($description, $details, '', $record, $eventId, $pid);
        } finally {
            if ($hadQueryEventId) {
                $_GET['event_id'] = $queryEventId;
            } else {
                unset($_GET['event_id']);
            }
        }
    }

    private function sealWithFallback(
        string $source,
        string $projectCert,
        \OpenSSLAsymmetricKey $key,
        array $issuerChain,
        IdentityRepository $identities,
        PkiHealthService $health,
        array $sourceIds,
        bool $fallback,
        int $now,
        array &$event,
        array &$timestampChain,
        ?array &$externalTimestampState,
    ): PdfSealResult {
        $capturedTsaIds = []; $capturedIssuers = []; $capturedChains = []; $capturedExternalRevisions = [];
        $ordered = new \DE\RUB\PDFSealerExternalModule\Timestamp\OrderedTimestampProvider(
            $sourceIds,
            function (string $sourceId, float $deadline) use ($identities, $health, &$capturedTsaIds, &$capturedIssuers, &$capturedChains, &$capturedExternalRevisions) {
                $timestamp = $identities->providers()->source($sourceId);
                if ($timestamp['kind'] === 'external') {
                    $capturedChains[$sourceId] = array_map([\DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::class, 'certificateDer'], $timestamp['chain']);
                    $sources = new \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($this->framework);
                    $revision = $sources->assertUsable($sourceId)['revision'];
                    $capturedExternalRevisions[$sourceId] = $revision;
                    return $sources->provider($sourceId, $deadline, $revision);
                }
                try {
                    $tsa = $health->captureTimestamp($timestamp, time());
                } catch (Throwable) {
                    $this->alarm(new PkiHealthReport(PkiHealth::Degraded, 'TSA_IDENTITY_INVALID', $timestamp['identity_id']));
                    throw new RuntimeException('TSA is unavailable');
                }
                $capturedTsaIds[$sourceId] = $timestamp['identity_id'];
                $capturedIssuers[$sourceId] = $timestamp['issuer_identity_id'];
                return new InternalTimestampProvider(
                    new InternalTsaService($timestamp['policy_oid']),
                    $tsa, static fn(): int => time(),
                );
            },
        );
        try {
            return $this->builder->sealTimestamped($source, $projectCert, $key, $issuerChain, $now, $ordered, time());
        } catch (Throwable $e) {
            if (!$fallback) { throw $e; }
            error_log('PDF Sealer timestamp failed; trying B-B: ' . get_class($e));
            return new PdfSealResult($this->builder->seal($source, $projectCert, $key, $issuerChain, $now), 'pades-b-b');
        } finally {
            $event['attempted_timestamp_sources'] = json_encode($ordered->attemptedSources(), JSON_THROW_ON_ERROR);
            $event['timestamp_source'] = $ordered->selectedSource() ?? 'none';
            if (isset($capturedTsaIds[$event['timestamp_source']])) {
                $event['timestamp_identity_id'] = $capturedTsaIds[$event['timestamp_source']];
                $event['timestamp_issuer_identity_id'] = $capturedIssuers[$event['timestamp_source']];
            }
            $timestampChain = $capturedChains[$event['timestamp_source']] ?? [];
            $externalTimestampState = isset($capturedExternalRevisions[$event['timestamp_source']])
                ? ['id' => $event['timestamp_source'], 'revision' => $capturedExternalRevisions[$event['timestamp_source']]] : null;
            $event['alternative_used'] = $ordered->selectedSource() !== null && $ordered->selectedSource() !== $sourceIds[0] ? '1' : '0';
        }
    }

    private static function requestTime(): int
    {
        if (defined('NOW_UTC')) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', NOW_UTC, new \DateTimeZone('UTC'));
            if ($parsed !== false) {
                // A project certificate issued during this request can start after NOW_UTC.
                return max($parsed->getTimestamp(), time());
            }
        }
        return time();
    }

    private function alarm(PkiHealthReport $report): void
    {
        if ($report->errorCode === null) {
            return;
        }
        try {
            (new AdminAlarmService($this->framework, new AlarmRepository($this->framework), new AlarmLock()))->raise(
                $report->errorCode,
                $report->status === PkiHealth::Broken ? 'critical' : 'degraded',
                $report->identityId,
            );
        } catch (Throwable $e) {
            error_log('PDF Sealer PKI alarm failed: ' . get_class($e));
        }
    }
}
