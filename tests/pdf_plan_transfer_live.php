<?php

declare(strict_types=1);

// Native adapter/database acceptance, not full ODM replacement. Run always rolls back.
if (PHP_SAPI !== 'cli' || getenv('PDF_SEALER_LIVE_TEST') !== '1') {
    throw new RuntimeException('Use CLI with PDF_SEALER_LIVE_TEST=1 on a development instance.');
}
$mode = $argv[1] ?? '--preview';
if (!in_array($mode, ['--preview', '--run'], true) || $argc > 2) {
    throw new RuntimeException('Use --preview or --run.');
}
$pid = filter_var(getenv('PDF_SEALER_TEST_PID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$username = getenv('PDF_SEALER_TEST_USERNAME');
if ($pid === false || !is_string($username) || $username === '') {
    throw new RuntimeException('Supply a disposable PDF_SEALER_TEST_PID and its designer PDF_SEALER_TEST_USERNAME.');
}
define('NOAUTH', true);
define('CRON', true);
define('USERID', strtolower($username));
define('SUPER_USER', false);
define('PROJECT_ID', $pid);
$_SERVER['PHP_SELF'] = 'pdf_plan_transfer_live.php';
require rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/') . '/Config/init_global.php';
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
});

use Vanderbilt\REDCap\Classes\PdfFinalization\PdfCoreTerminalSettings;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanOdmAdapter;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanPermissions;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanRepository;
use Vanderbilt\REDCap\Classes\Settings\ProjectSettingKeys;
use Vanderbilt\REDCap\Classes\Settings\ProjectSettingRepository;

function checkTransfer(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$project = new Project($pid);
checkTransfer((int)$project->project['status'] === 0 && empty($project->project['date_deleted'])
    && $project->project['app_title'] === 'PDF plan XML import empty', 'Requires the named disposable empty-import fixture.');
checkTransfer(PdfExecutionPlanPermissions::canEdit($pid), 'Requires actual native Design/Setup rights.');
$recordCount = Records::getRecordCount($pid);
checkTransfer(is_numeric($recordCount) && (int)$recordCount === 0, 'Requires a record-free fixture.');
checkTransfer(!PdfCoreTerminalSettings::isTestActionAvailable(), 'Requires the Core test gate disabled.');
checkTransfer(PdfExecutionPlanRepository::hasProjectExecutionPlan($pid)
    && PdfExecutionPlanRepository::getProjectExecutionPlan($pid) === [], 'Requires the imported explicit-empty plan.');
checkTransfer(!isset(ExternalModules\ExternalModules::getEnabledModules($pid)['pdf_sealer']), 'Requires Sealer disabled.');
foreach (['redcap_project_settings', 'redcap_projects'] as $table) {
    $query = db_query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
    checkTransfer($query !== false && db_fetch_assoc($query)['ENGINE'] === 'InnoDB', 'Requires transactional table: ' . $table);
}
checkTransfer((int)db_fetch_assoc(db_query('SELECT @@autocommit AS enabled'))['enabled'] === 1, 'Requires a fresh CLI connection.');
$repository = new ProjectSettingRepository();
$key = ProjectSettingKeys::EXTERNAL_MODULES_PDF_FINALIZE_EXECUTION_PLAN;
$coreKey = ProjectSettingKeys::PDF_FINALIZATION_CORE_TERMINAL_WORKFLOWS;
$beforeSetting = $repository->get($pid, $key)->toArray();
$beforeCore = $repository->get($pid, $coreKey)?->toArray();
$activityKeys = array_flip(['project_note', 'last_logged_event', 'last_logged_event_exclude_exports']);
$beforeProject = array_intersect_key($project->project, $activityKeys);
echo "Preflight passed for PID $pid: disposable record-free fixture, native design rights, explicit-empty plan, disabled Sealer/Core gate, transactional tables.\n";
echo "Run scope: native Core adapter/repository replacements and a temporary project-note sentinel, inside one transaction with unconditional rollback. No full ODM replacement, records, activation, PKI, edocs or email.\n";
if ($mode !== '--run') { exit; }

$plan = ['pdf_sealer:seal', 'pdf_finalize_transfer_missing:annotate', 'pdf_sealer:seal'];
$payload = static fn(array $operations): array => [PdfExecutionPlanOdmAdapter::TABLE => [[
    'version' => '1', 'operations' => json_encode($operations, JSON_THROW_ON_ERROR),
]]];
$oldLog = ini_get('error_log');
$temporaryLog = tempnam(sys_get_temp_dir(), 'pdf-plan-transfer-errors-');
checkTransfer($temporaryLog !== false, 'Could not create the controlled-error log.');
ini_set('error_log', $temporaryLog);
checkTransfer(db_query('SET AUTOCOMMIT=0') !== false, 'Could not begin rollback containment.');
try {
    $sentinel = 'PDF plan transfer rollback sentinel ' . bin2hex(random_bytes(8));
    checkTransfer(Project::setAttribute('project_note', $sentinel, $pid) !== false, 'Could not set the transaction sentinel.');
    checkTransfer((new Project($pid, true))->project['project_note'] === $sentinel, 'Sentinel write was not observed.');
    $extensions = $payload($plan);
    checkTransfer(PdfExecutionPlanOdmAdapter::importProjectMetadata($pid, $extensions) === [] && $extensions === [], 'Valid adapter import failed.');
    checkTransfer(PdfExecutionPlanRepository::getProjectExecutionPlan($pid) === $plan, 'Native import changed order/duplicates/unavailable identifiers.');
    $currentSetting = $repository->get($pid, $key)->toArray();
    $extensions = ['unrelated_extension' => []];
    checkTransfer(PdfExecutionPlanOdmAdapter::importProjectMetadata($pid, $extensions) === []
        && $extensions === ['unrelated_extension' => []]
        && $repository->get($pid, $key)->toArray() === $currentSetting, 'Omitted extension changed existing state.');
    $extensions = $payload([]);
    checkTransfer(PdfExecutionPlanOdmAdapter::importProjectMetadata($pid, $extensions) === []
        && PdfExecutionPlanRepository::hasProjectExecutionPlan($pid)
        && PdfExecutionPlanRepository::getProjectExecutionPlan($pid) === [], 'Explicit empty did not replace an existing plan.');
    $extensions = $payload($plan);
    checkTransfer(PdfExecutionPlanOdmAdapter::importProjectMetadata($pid, $extensions) === [], 'Could not seed the existing plan.');
    $currentSetting = $repository->get($pid, $key)->toArray();
    foreach ([
        [['version' => '2', 'operations' => '[]']],
        [['version' => '1', 'operations' => '{}']],
        [['version' => '1', 'operations' => '[42]']],
        [['version' => '1', 'operations' => '[]'], ['version' => '1', 'operations' => '[]']],
        [[]],
    ] as $rows) {
        $extensions = [PdfExecutionPlanOdmAdapter::TABLE => $rows];
        checkTransfer(count(PdfExecutionPlanOdmAdapter::importProjectMetadata($pid, $extensions)) === 1
            && $extensions === [] && $repository->get($pid, $key)->toArray() === $currentSetting,
            'Malformed adapter payload changed an existing plan or was not consumed.');
    }
    checkTransfer($repository->delete($pid, $key), 'Could not prepare absent state within the transaction.');
    $extensions = [];
    checkTransfer(PdfExecutionPlanOdmAdapter::importProjectMetadata($pid, $extensions) === []
        && !PdfExecutionPlanRepository::hasProjectExecutionPlan($pid), 'Omitted extension created an absent plan.');
    $extensions = $payload([]);
    checkTransfer(PdfExecutionPlanOdmAdapter::importProjectMetadata($pid, $extensions) === []
        && PdfExecutionPlanRepository::hasProjectExecutionPlan($pid), 'Explicit empty did not create configured state.');
    echo "Native adapter checks passed: existing/absent targets, omitted versus empty, ordered duplicate/unavailable identifiers and five malformed payloads.\n";
} finally {
    try {
        checkTransfer(db_query('ROLLBACK') !== false, 'Rollback failed.');
        checkTransfer(db_query('SET AUTOCOMMIT=1') !== false, 'Could not restore autocommit.');
    } finally {
        ini_set('error_log', $oldLog);
        unlink($temporaryLog);
    }
    checkTransfer($repository->get($pid, $key)?->toArray() === $beforeSetting
        && $repository->get($pid, $coreKey)?->toArray() === $beforeCore,
        'Rollback did not restore exact original plan/Core setting rows.');
    checkTransfer(array_intersect_key((new Project($pid, true))->project, $activityKeys) === $beforeProject,
        'Rollback did not restore the project-note sentinel/activity.');
}
echo "Rollback verified: original setting ID/value/timestamps, Core setting absence and project note/activity restored.\n";
