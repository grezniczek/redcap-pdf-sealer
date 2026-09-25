<?php

declare(strict_types=1);

// Run only against a disposable REDCap development instance. All log writes roll back.
if (getenv('PDF_SEALER_LIVE_TEST') !== '1') {
    throw new RuntimeException('Set PDF_SEALER_LIVE_TEST=1 to run this development-instance check');
}

$_SERVER['PHP_SELF'] = 'pdf_sealer_live_framework.php';
require '/home/gr/redcap/codebase/Config/init_global.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmLock;
use DE\RUB\PDFSealerExternalModule\Alerts\AlarmRepository;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

function assertFinalizedPdf(string $path): void
{
    foreach ([['qpdf', '--check', $path], ['pdfsig', '-nocert', '-no-ocsp', $path]] as $command) {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start PDF validator');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || ($command[0] === 'pdfsig'
            && (!str_contains($output, 'Signature Validation: Signature is Valid.')
                || !str_contains($output, 'Total document signed')))) {
            throw new RuntimeException('Finalized PDF validation failed: ' . $output);
        }
    }
}

$framework = \ExternalModules\ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
// REDCap disables user-based setting permissions inside hooks; mirror that for this CLI probe.
$framework->disableUserBasedSettingPermissions();
$protector = new SecretProtector();
$repository = new IdentityRepository($framework, $protector);
$issuer = new CertificateIssuer([$framework, 'createTempFile']);
$bindings = new ProjectBindingRepository($framework);
$previousRootId = $repository->activeId('root');
$previousTsaId = $repository->activeId('tsa');
$previousOrganization = $framework->getSystemSetting('organization');
$previousRecipients = $framework->getSystemSetting('admin-alert-recipients');
$previousPolicy = $framework->getSystemSetting('tsa_policy_oid');
$previousTimestampMode = $framework->getSystemSetting('timestamp_mode');
$previousFallback = $framework->getSystemSetting('bb_fallback');
\ExternalModules\ExternalModules::setProjectId('461');

$probe = 'pdf-sealer-crypto-probe-' . bin2hex(random_bytes(8));
if ($protector->decrypt($protector->encrypt($probe)) !== $probe) {
    throw new RuntimeException('REDCap encryption round trip failed');
}

if ($framework->query('START TRANSACTION', []) === false
    || $framework->query('ROLLBACK', []) === false) {
    throw new RuntimeException('Framework transaction commands failed');
}
if (db_query('START TRANSACTION') === false) {
    throw new RuntimeException('Could not start test transaction');
}
try {
    // The outer transaction belongs to this test. Suppress only the service's nested transaction commands.
    (new PkiInitializationService(
        $framework, $repository, $bindings, $issuer,
        new PkiHealthService($repository, $protector), new PkiInitializationLock(),
        static fn (string $sql): bool => in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true),
    ))->initialize('PDF Sealer Integration Test');
    $id = $repository->activeId('root');
    $tsaId = $repository->activeId('tsa');
    $stored = $id === null ? null : $repository->find($id);
    if ($id === null || $tsaId === null || $id === $tsaId
        || (new PkiHealthService($repository, $protector))->inspect(time())->status !== PkiHealth::Ready
        || $framework->getSystemSetting('organization') !== 'PDF Sealer Integration Test') {
        throw new RuntimeException('Live PKI initialization was not ready');
    }
    if ($stored === null || $stored->role !== 'root'
        || !openssl_x509_check_private_key(
            \Com\Tecnick\Pdf\Sign\Cms\Certificate::derToPem($stored->certificateDer),
            $stored->privateKey($protector),
        )) {
        throw new RuntimeException('Stored identity did not round trip through the Framework');
    }
    $row = $framework->queryLogs(
        'SELECT project_id, record, private_key_ciphertext WHERE message = ? AND identity_id = ? AND ISNULL(project_id) LIMIT 1',
        ['pki_identity', $id],
    )->fetch_assoc();
    if ($row === null || $row['project_id'] !== null || $row['record'] !== null
        || !str_starts_with($row['private_key_ciphertext'] ?? '', 'redcap-v1:')
        || str_contains($row['private_key_ciphertext'], 'PRIVATE KEY')) {
        throw new RuntimeException('Identity log scope or encryption is incorrect');
    }
    $projects = new ProjectIdentityService(
        $bindings, $repository, $protector, $issuer,
        new PkiHealthService($repository, $protector), new ProjectIssueLock(),
    );
    $project = $projects->getOrIssue(461);
    if ($projects->getOrIssue(461)->id !== $project->id
        || $bindings->find(461)?->identityId !== $project->id
        || $project->projectUuid === null) {
        throw new RuntimeException('Lazy project identity was not stable in a project context');
    }
    $pdf = "%PDF-1.4\n";
    $bodies = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
    ];
    $offsets = [];
    foreach ($bodies as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 4\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
    }
    $pdf .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    $workingPath = $framework->createTempFile();
    if (!is_string($workingPath) || file_put_contents($workingPath, $pdf) !== strlen($pdf)) {
        throw new RuntimeException('Could not create hook working PDF');
    }
    try {
        $module = $framework->getModuleInstance();
        $context = ['project_id' => 461, 'document_type' => 'econsent', 'generation_id' => 'pdf-sealer-live-test'];
        $operation = ['id' => 'seal'];
        $framework->setSystemSetting('timestamp_mode', 'internal');
        $framework->setSystemSetting('bb_fallback', '1');
        $framework->setSystemSetting('tsa_policy_oid', '1.3.6.1.4.1.55555.3161.1');
        $result = $module->redcap_pdf_finalize($workingPath, $operation, $context);
        if (!$result->isModified() || !$result->isTerminal()
            || ($result->getMetadata()['seal_profile'] ?? null) !== 'pades-b-t'
            || !is_string($result->getMetadata()['timestamp_serial'] ?? null)
            || !is_int($result->getMetadata()['timestamp_time'] ?? null)
            || !str_starts_with(file_get_contents($workingPath), $pdf)) {
            throw new RuntimeException('Live hook did not return a B-T working PDF');
        }
        assertFinalizedPdf($workingPath);

        $framework->setSystemSetting('tsa_policy_oid', '');
        file_put_contents($workingPath, $pdf);
        $result = $module->redcap_pdf_finalize($workingPath, $operation, $context);
        if (!$result->isModified() || ($result->getMetadata()['seal_profile'] ?? null) !== 'pades-b-b'
            || ($result->getMetadata()['timestamp_serial'] ?? null) !== null) {
            throw new RuntimeException('Live hook did not fall back to B-B');
        }
        assertFinalizedPdf($workingPath);

        $framework->setSystemSetting('bb_fallback', '0');
        file_put_contents($workingPath, $pdf);
        $result = $module->redcap_pdf_finalize($workingPath, $operation, $context);
        if (!$result->isFailed() || file_get_contents($workingPath) !== $pdf) {
            throw new RuntimeException('Live hook did not preserve the working PDF on failure');
        }

        $framework->setSystemSetting('timestamp_mode', 'none');
        file_put_contents($workingPath, $pdf);
        $result = $module->redcap_pdf_finalize($workingPath, $operation, $context);
        if (!$result->isModified() || ($result->getMetadata()['seal_profile'] ?? null) !== 'pades-b-b') {
            throw new RuntimeException('Live hook did not honor B-B-only mode');
        }
        \ExternalModules\ExternalModules::setProjectId(null);
        try {
            file_put_contents($workingPath, $pdf);
            $result = $module->redcap_pdf_finalize($workingPath, $operation, $context);
            if (!$result->isModified() || ($result->getMetadata()['seal_profile'] ?? null) !== 'pades-b-b') {
                throw new RuntimeException('Live hook rejected a context-only project ID');
            }
        } finally {
            \ExternalModules\ExternalModules::setProjectId('461');
        }
    } finally {
        @unlink($workingPath);
    }

    $framework->setSystemSetting('admin-alert-recipients', ['alarm@example.org']);
    $alarms = new AlarmRepository($framework);
    $mailCount = 0;
    $alarmService = new AdminAlarmService(
        $framework, $alarms, new AlarmLock(),
        static function (string $to, string $subject, string $body) use (&$mailCount): bool {
            if ($to !== 'alarm@example.org' || !str_contains($subject, 'PROJECT_KEY_MISMATCH')) {
                throw new RuntimeException('Unexpected alarm mail');
            }
            ++$mailCount;
            return true; // No real email leaves this test.
        },
    );
    $alarmNow = time();
    if ($alarmService->raise('PROJECT_KEY_MISMATCH', 'critical', $project->id, $alarmNow) !== 'sent'
        || $alarmService->raise('PROJECT_KEY_MISMATCH', 'critical', $project->id, $alarmNow + 1) !== 'throttled'
        || $mailCount !== 1) {
        throw new RuntimeException('Live alarm throttle failed');
    }
} finally {
    if (db_query('ROLLBACK') === false) {
        throw new RuntimeException('Could not roll back test records');
    }
}

if ($repository->find($id) !== null || $bindings->find(461) !== null
    || $repository->activeId('root') !== $previousRootId
    || $repository->activeId('tsa') !== $previousTsaId
    || $framework->getSystemSetting('organization') !== $previousOrganization
    || $framework->getSystemSetting('admin-alert-recipients') !== $previousRecipients
    || $framework->getSystemSetting('tsa_policy_oid') !== $previousPolicy
    || $framework->getSystemSetting('timestamp_mode') !== $previousTimestampMode
    || $framework->getSystemSetting('bb_fallback') !== $previousFallback
    || $alarms->lastMailedAt(hash('sha256', 'PROJECT_KEY_MISMATCH' . "\0" . $project->id)) !== null) {
    throw new RuntimeException('Test PKI records or settings remained after rollback');
}
echo "Live Framework PKI, PDF finalization hook, alarm throttle, and rollback passed.\n";
