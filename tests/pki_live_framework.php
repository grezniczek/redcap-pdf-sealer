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
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

$framework = \ExternalModules\ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
// REDCap disables user-based setting permissions inside hooks; mirror that for this CLI probe.
$framework->disableUserBasedSettingPermissions();
$protector = new SecretProtector();
$repository = new IdentityRepository($framework, $protector);
$issuer = new CertificateIssuer([$framework, 'createTempFile']);
$bindings = new ProjectBindingRepository($framework);
$previousRootId = $repository->activeId('root');
$previousRecipients = $framework->getSystemSetting('admin-alert-recipients');
\ExternalModules\ExternalModules::setProjectId('461');
$root = $issuer->createRoot('PDF Sealer Integration Test');

$probe = 'pdf-sealer-crypto-probe-' . bin2hex(random_bytes(8));
if ($protector->decrypt($protector->encrypt($probe)) !== $probe) {
    throw new RuntimeException('REDCap encryption round trip failed');
}

if (db_query('START TRANSACTION') === false) {
    throw new RuntimeException('Could not start test transaction');
}
try {
    $id = $repository->append('root', $root);
    $repository->activate('root', $id);
    $stored = $repository->find($id);
    if (!$repository->hasRole('root')) {
        throw new RuntimeException('System-scoped role lookup missed the test identity');
    }
    if ($stored === null || $stored->role !== 'root'
        || $stored->certificateDer !== $root->certificateDer
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
    || $framework->getSystemSetting('admin-alert-recipients') !== $previousRecipients
    || $alarms->lastMailedAt(hash('sha256', 'PROJECT_KEY_MISMATCH' . "\0" . $project->id)) !== null) {
    throw new RuntimeException('Test PKI records or settings remained after rollback');
}
echo "Live Framework PKI storage, project issuance, alarm throttle, system scope, and rollback passed.\n";
