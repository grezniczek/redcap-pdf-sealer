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
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTimestampProvider;
use DE\RUB\PDFSealerExternalModule\Timestamp\InternalTsaService;
use DE\RUB\PDFSealerExternalModule\Timestamp\TsaIdentity;
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
        $pid = $context['project_id'] ?? null;
        $ambientPid = $this->framework->getProjectId();
        if ((!is_int($pid) && (!is_string($pid) || !ctype_digit($pid))) || (int) $pid < 1
            || ($ambientPid !== null && (int) $pid !== (int) $ambientPid)) {
            return PdfFinalizeResult::failed('INVALID_CONTEXT', 'PDF seal project context is invalid');
        }
        try {
            $protector = new SecretProtector();
            $identities = new IdentityRepository($this->framework, $protector);
            $health = new PkiHealthService($identities, $protector);
            $report = $health->inspect(time());
            if ($report->status === PkiHealth::Uninitialized || $report->status === PkiHealth::Broken) {
                $this->alarm($report);
                return PdfFinalizeResult::failed('PKI_NOT_READY', $report->errorCode ?? $report->status->value);
            }
            if ($report->status === PkiHealth::Degraded) {
                $this->alarm($report);
            }

            $settings = new PrimarySystemSettingReader($this->framework);
            $mode = $settings->get('timestamp_mode') ?? 'internal';
            if (!in_array($mode, ['internal', 'none'], true)) {
                throw new RuntimeException('Invalid timestamp mode setting');
            }
            $fallback = self::fallbackEnabled($settings->get('bb_fallback'));
            $source = file_get_contents($path);
            if (!is_string($source)) {
                return PdfFinalizeResult::failed('INPUT_READ_FAILED', 'PDF working copy is unreadable');
            }

            $rootId = $identities->activeId('root');
            $root = $rootId === null ? null : $identities->find($rootId);
            if ($root === null || $root->role !== 'root') {
                throw new RuntimeException('Active root identity is unavailable');
            }
            $project = (new ProjectIdentityService(
                new ProjectBindingRepository($this->framework), $identities, $protector,
                new CertificateIssuer([$this->framework, 'createTempFile']), $health, new ProjectIssueLock(),
            ))->getOrIssue((int) $pid);
            $key = $project->privateKey($protector);
            $now = self::requestTime();
            if ($mode === 'none') {
                $sealed = $this->builder->seal($source, $project->certificateDer, $key, [$root->certificateDer], $now);
                $result = new PdfSealResult($sealed, 'pades-b-b');
            } else {
                $result = $this->sealWithFallback(
                    $source, $project->certificateDer, $key, $root->certificateDer,
                    $identities, $protector, $settings, $report, $fallback, $now,
                );
            }
            $written = file_put_contents($path, $result->pdf, LOCK_EX);
            if ($written !== strlen($result->pdf)) {
                return PdfFinalizeResult::failed('OUTPUT_WRITE_FAILED', 'Could not write sealed PDF working copy');
            }
            return PdfFinalizeResult::modified($path, true, [
                'seal_profile' => $result->profile,
                'timestamp_serial' => $result->timestampSerialHex,
                'timestamp_time' => $result->timestampTime,
            ]);
        } catch (Throwable $e) {
            error_log('PDF Sealer finalization failed: ' . get_class($e));
            return PdfFinalizeResult::failed('PDF_SEAL_FAILED', 'PDF sealing failed');
        }
    }

    private function sealWithFallback(
        string $source,
        string $projectCert,
        \OpenSSLAsymmetricKey $key,
        string $rootCert,
        IdentityRepository $identities,
        SecretProtector $protector,
        PrimarySystemSettingReader $settings,
        PkiHealthReport $health,
        bool $fallback,
        int $now,
    ): PdfSealResult {
        try {
            if ($health->status !== PkiHealth::Ready) {
                throw new RuntimeException('TSA is unavailable');
            }
            $policy = $settings->get('tsa_policy_oid');
            if (!is_string($policy) || $policy === '') {
                throw new RuntimeException('TSA policy OID is not configured');
            }
            $tsaId = $identities->activeId('tsa');
            $tsa = $tsaId === null ? null : $identities->find($tsaId);
            if ($tsa === null || $tsa->role !== 'tsa') {
                throw new RuntimeException('Active TSA identity is unavailable');
            }
            $provider = new InternalTimestampProvider(
                new InternalTsaService($policy),
                new TsaIdentity($tsa->certificateDer, $tsa->privateKey($protector), [$rootCert]),
            );
            return $this->builder->sealTimestamped($source, $projectCert, $key, [$rootCert], $now, $provider, $now);
        } catch (Throwable $e) {
            if (!$fallback) {
                throw $e;
            }
            error_log('PDF Sealer timestamp failed; trying B-B: ' . get_class($e));
            $sealed = $this->builder->seal($source, $projectCert, $key, [$rootCert], $now);
            return new PdfSealResult($sealed, 'pades-b-b');
        }
    }

    private static function fallbackEnabled(mixed $setting): bool
    {
        if ($setting === null || $setting === '' || $setting === '1' || $setting === 'true') {
            return true;
        }
        if ($setting === '0' || $setting === 'false') {
            return false;
        }
        throw new RuntimeException('Invalid B-B fallback setting');
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
