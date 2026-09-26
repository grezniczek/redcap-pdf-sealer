<?php

declare(strict_types=1);

// Development instances only. Preview by default; --run performs rollback-contained log writes.
if (getenv('PDF_SEALER_LIVE_TEST') !== '1') {
    throw new RuntimeException('Set PDF_SEALER_LIVE_TEST=1 on a development instance');
}
if (!in_array($argv[1] ?? '--preview', ['--preview', '--run'], true) || $argc > 2) {
    throw new RuntimeException('Use --preview or --run');
}
$core = rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/');
$pid = filter_var(getenv('PDF_SEALER_TEST_PID') ?: '461', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($pid === false) { throw new RuntimeException('Invalid test project ID'); }
$_SERVER['PHP_SELF'] = 'pdf_sealer_pipeline_live.php';
require $core . '/Config/init_global.php';
require __DIR__ . '/support/pdf_timestamp_checks.php';
require __DIR__ . '/support/redcap_pdf_fixtures.php';

use Com\Tecnick\Pdf\Sign\Cms\Asn1;
use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use Com\Tecnick\Pdf\Sign\Signer;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampSettings;
use ExternalModules\ExternalModules;
use ExternalModules\PdfFinalize;

$framework = ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
ExternalModules::setProjectId((string) $pid);
$protector = new SecretProtector();
$identities = new IdentityRepository($framework, $protector);
$bindings = new ProjectBindingRepository($framework);
$health = new PkiHealthService($identities, $protector);
checkSeal($health->inspect(time())->status === PkiHealth::Ready, 'Preflight requires a healthy existing root and TSA');
$projects = new ProjectIdentityService($bindings, $identities, $protector,
    new CertificateIssuer([$framework, 'createTempFile']), $health, new ProjectIssueLock());
checkSeal($projects->inspect($pid)['state'] === 'ready', 'Preflight requires an existing usable project signer; no issuance is performed');
checkSeal(PdfFinalize::getProjectExecutionPlan($pid) === ['pdf_sealer:seal'], 'Preflight requires a pipeline containing only pdf_sealer:seal');
checkSeal(PdfFinalize::resolveOperation('pdf_sealer:seal', $pid) !== null, 'Sealer operation is not available');
$settings = new PrimarySystemSettingReader($framework);
$timestamp = TimestampSettings::fromStored($settings->get('timestamp_mode'), $settings->get('bb_fallback'));
$expectedProfile = $timestamp->mode === 'internal' ? 'pades-b-t' : 'pades-b-b';
$root = $identities->find($identities->activeId('root'));
$rootPem = Certificate::derToPem($root->certificateDer);
$project = new Project($pid);
$eventId = (int) $project->firstEventId;
checkSeal($eventId > 0, 'Project has no event');
$logTable = Logging::getLogEventTable($pid);
checkSeal(preg_match('/^redcap_log_event[0-9]*$/D', $logTable) === 1, 'Unexpected project log table');
// A rollback probe is meaningful only if every table it could write is transactional.
foreach ([$logTable, 'redcap_projects', 'redcap_external_modules_log', 'redcap_external_modules_log_parameters', 'redcap_external_module_settings'] as $table) {
    $result = db_query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table], null, MYSQLI_STORE_RESULT, true);
    checkSeal($result !== false && ($result->fetch_row()[0] ?? null) === 'InnoDB', 'Preflight requires InnoDB: ' . $table);
}
$autocommit = db_query('SELECT @@autocommit', [], null, MYSQLI_STORE_RESULT, true);
checkSeal($autocommit !== false && (int) $autocommit->fetch_row()[0] === 1, 'Run in a fresh CLI connection with autocommit enabled');
$before = [$bindings->find($pid), $identities->activeId('root'), $identities->activeId('tsa'),
    $settings->get('timestamp_mode'), $settings->get('bb_fallback'), $settings->get('tsa_policy_oid')];
echo "Preflight: PID $pid, existing PKI/signer ready, single sealer pipeline, expected $expectedProfile.\n";
echo "Run scope: five synthetic fixtures through Core file/bytes entry points; document-type bypass and already-certified rejection; test log writes rolled back. No setting changes, edoc writes, or email.\n";
if (($argv[1] ?? '--preview') !== '--run') { exit; }

$directory = sys_get_temp_dir() . '/pdf_sealer_pipeline_' . bin2hex(random_bytes(8));
checkSeal(mkdir($directory, 0700), 'Could not create pipeline test directory');
$originalErrorLog = ini_get('error_log');
$record = 'pdf-sealer-pipeline-' . bin2hex(random_bytes(8));
$transaction = false;
$generatedPaths = [];
try {
    [$status, $output] = runSealCommand([PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__ . '/support/generate_redcap_fixtures.php', $core, $directory]);
    checkSeal($status === 0, 'Fixture subprocess failed: ' . $output);
    $cases = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    checkSeal(ini_set('error_log', $directory . '/pipeline.log') !== false, 'Cannot capture Framework diagnostic log');
    // Framework startHook rolls back before AND after every hook. START TRANSACTION alone
    // is therefore insufficient: keep autocommit off across all those boundaries.
    checkSeal(db_query('SET AUTOCOMMIT=0', [], null, MYSQLI_STORE_RESULT, true) !== false, 'Could not disable test autocommit');
    $transaction = true;
    $context = ['document_type' => 'econsent', 'record_id' => $record, 'event_id' => $eventId,
        'generation_reason' => 'pdf_sealer_cli_acceptance', 'storage_target' => 'test_only'];
    $generations = [];
    $readEvents = static function (string $generation) use ($directory): array {
        $events = [];
        foreach (file($directory . '/pipeline.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/REDCap PDF finalization: (\{.*\})$/', $line, $match) !== 1) { continue; }
            $event = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            if (($event['generation_id'] ?? null) === $generation) { $events[$event['event']] = $event; }
        }
        return $events;
    };
    foreach ($cases as $name => [$sourcePath, $pages, $links]) {
        $source = file_get_contents($sourcePath);
        $presentation = fixturePresentation($sourcePath, $directory . '/render');
        foreach (['file', 'contents'] as $entry) {
            $generation = null;
            if ($entry === 'file') {
                $finalPath = PdfFinalizer::finalize($sourcePath, $pid, $context, $generation);
                checkSeal($finalPath !== $sourcePath, "$name: pipeline did not adopt a working copy");
                $generatedPaths[] = $finalPath;
                $sealed = file_get_contents($finalPath);
            } else {
                $sealed = PdfFinalizer::finalizeContents($source, $pid, $context, $generation);
            }
            checkSeal(is_string($generation) && preg_match('/^[a-f0-9]{32}$/D', $generation) === 1, 'Missing generation ID');
            $generations[] = $generation;
            checkSeal(file_get_contents($sourcePath) === $source, 'Pipeline changed its original input');
            $cms = verifySeal($source, $sealed, $rootPem);
            $tokens = (new Signer())->signatureTimestampTokens($cms);
            checkSeal(count($tokens) === ($expectedProfile === 'pades-b-t' ? 1 : 0), 'Unexpected sealing profile or silent B-B fallback');
            if ($tokens !== []) {
                file_put_contents($directory . '/token.der', $tokens[0]);
                file_put_contents($directory . '/signature.bin', cmsSignatureBytes($cms, new Asn1(), new Certificate()));
                file_put_contents($directory . '/root.pem', $rootPem);
                [$status, $output] = runSealCommand(['openssl', 'ts', '-verify', '-token_in', '-in', $directory . '/token.der',
                    '-data', $directory . '/signature.bin', '-CAfile', $directory . '/root.pem']);
                checkSeal($status === 0, 'Embedded timestamp verification failed: ' . $output);
            }
            checkSeal(fixtureLinks($source) === fixtureLinks($sealed), 'Footer links changed');
            file_put_contents($directory . '/sealed.pdf', $sealed);
            checkSeal(fixturePresentation($directory . '/sealed.pdf', $directory . '/render') === $presentation, 'Rendered pages or text changed');
            $events = $readEvents($generation);
            checkSeal(($events['operation_completed']['metadata']['seal_profile'] ?? null) === $expectedProfile
                && ($events['pipeline_completed']['terminal'] ?? false) === true
                && ($events['pipeline_completed']['accepted_modification_count'] ?? null) === 1
                && ($events['pipeline_completed']['final_sha256'] ?? null) === hash('sha256', $sealed),
                'Framework terminal result/profile/final hash mismatch');
            echo "$name: Core $entry entry, real hook, $expectedProfile, terminal adoption, signature and content preservation passed.\n";
        }
    }
    $source = file_get_contents($cases['consent-signature'][0]);
    $bypass = $context;
    $bypass['document_type'] = 'record_pdf';
    checkSeal(PdfFinalizer::finalizeContents($source, $pid, $bypass, $generation) === $source, 'Document-type bypass changed bytes');
    checkSeal(isset($readEvents($generation)['document_type_mismatch']), 'Document-type mismatch was not logged');
    $certified = $directory . '/sealed.pdf';
    $beforeFailure = file_get_contents($certified);
    checkSeal(PdfFinalizer::finalize($certified, $pid, $context, $generation) === $certified
        && file_get_contents($certified) === $beforeFailure, 'Already-certified failure did not preserve input');
    $generations[] = $generation;
    checkSeal(isset($readEvents($generation)['controlled_failure']), 'Expected controlled failure was not logged');

} finally {
    if ($transaction) {
        checkSeal(db_query('ROLLBACK', [], null, MYSQLI_STORE_RESULT, true) !== false, 'Test rollback failed');
        checkSeal(db_query('SET AUTOCOMMIT=1', [], null, MYSQLI_STORE_RESULT, true) !== false, 'Could not restore autocommit');
    }
    ini_set('error_log', (string) $originalErrorLog);
    foreach ($generatedPaths as $path) { if (is_file($path)) { unlink($path); } }
    foreach (glob($directory . '/*') as $path) { unlink($path); }
    rmdir($directory);
}
$remaining = db_query("SELECT COUNT(*) FROM $logTable WHERE project_id = ? AND pk = ?", [$pid, $record], null, MYSQLI_STORE_RESULT, true);
checkSeal($remaining !== false && (int) $remaining->fetch_row()[0] === 0, 'Project test logs survived rollback');
foreach ($generations as $generation) {
    checkSeal($framework->queryLogs('SELECT log_id WHERE message = ? AND generation_id = ? LIMIT 1', ['seal_event', $generation])->fetch_assoc() === null,
        'EM failure diagnostic survived rollback');
}
checkSeal($before == [$bindings->find($pid), $identities->activeId('root'), $identities->activeId('tsa'),
    $settings->get('timestamp_mode'), $settings->get('bb_fallback'), $settings->get('tsa_policy_oid')], 'Configuration/identity binding changed');
echo "Core/Framework pipeline acceptance passed; test log writes rolled back and temporary artifacts removed. Stored/downloaded edoc acceptance is a separate browser check.\n";
