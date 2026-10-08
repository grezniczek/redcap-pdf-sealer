<?php

declare(strict_types=1);

// Read-only native export/configuration preflight. Never import or enable anything.
if (PHP_SAPI !== 'cli' || getenv('PDF_SEALER_LIVE_TEST') !== '1') {
    throw new RuntimeException('Use CLI with PDF_SEALER_LIVE_TEST=1 on a development instance.');
}
$pid = filter_var(getenv('PDF_SEALER_TEST_PID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$username = getenv('PDF_SEALER_TEST_USERNAME');
if ($pid === false || !is_string($username) || $username === '') {
    throw new RuntimeException('Supply PDF_SEALER_TEST_PID and a native project member PDF_SEALER_TEST_USERNAME.');
}
define('NOAUTH', true);
define('CRON', true);
define('USERID', strtolower($username));
define('SUPER_USER', false);
define('PROJECT_ID', $pid);
$_SERVER['PHP_SELF'] = 'pdf_core_transfer_preflight.php';
require rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/') . '/Config/init_global.php';
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
});

use Vanderbilt\REDCap\Classes\PdfFinalization\PdfCoreTerminalSettings;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanController;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanOdmAdapter;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanRepository;

function checkTransferPreflight(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$project_id = $pid;
$Proj = new Project($pid);
checkTransferPreflight(empty($Proj->project['date_deleted']), 'Requires an active development-instance project.');
ExternalModules\ExternalModules::setProjectId($pid);
$user_rights = UserRights::getPrivileges($pid, USERID)[$pid][USERID] ?? [];
checkTransferPreflight($user_rights !== [], 'Requires actual project membership.');
checkTransferPreflight(!PdfCoreTerminalSettings::isTestActionAvailable(), 'This preflight requires the development gate disabled.');
$before = PdfExecutionPlanRepository::getProjectExecutionPlan($pid);
$exists = PdfExecutionPlanRepository::hasProjectExecutionPlan($pid);
$beforeCore = PdfCoreTerminalSettings::getEnabledWorkflows($pid);

$assertExport = static function (string $options, bool $all, bool $migration, bool $expected): void {
    global $Proj, $before;
    $metadata = ODM::getOdmMetadata($Proj, false, false, $options, $all, $migration);
    $xml = simplexml_load_string('<ODM xmlns:redcap="https://projectredcap.org">' . $metadata . '</ODM>');
    checkTransferPreflight($xml !== false, 'Native ODM metadata was not well-formed.');
    $xml->registerXPathNamespace('redcap', 'https://projectredcap.org');
    $nodes = $xml->xpath('//redcap:PdfFinalizeExecutionPlan');
    checkTransferPreflight(count($nodes) === ($expected ? 1 : 0), 'Native export option selection failed.');
    if ($expected) {
        checkTransferPreflight((string)$nodes[0]['version'] === '1'
            && json_decode((string)$nodes[0]['operations'], true) === $before, 'Native export changed the execution plan.');
    }
    checkTransferPreflight(!str_contains($metadata, 'pdf_finalization.core_terminal_workflows'), 'Test activation leaked into metadata.');
};
$assertExport(PdfExecutionPlanOdmAdapter::METADATA_OPTION_KEY, false, false, $exists);
$assertExport('', false, false, false);
$assertExport('', true, false, $exists);
define('API', true);
$assertExport('', false, false, $exists);
$assertExport('', false, true, false);
$assertExport(PdfExecutionPlanOdmAdapter::METADATA_OPTION_KEY, false, true, $exists);

$migration = new ProjectMigration();
foreach ([false, true] as $migrationOptions) {
    $choices = $migration->renderProjectComponentChoices($pid, $migrationOptions);
    checkTransferPreflight((preg_match('/value=\x27pdffinalizationplan\x27 checked/', $choices) === 1) === $exists,
        'Native XML/PMT option did not match saved-plan presence.');
}
checkTransferPreflight($migration->getStatusCategoryText(PdfExecutionPlanOdmAdapter::METADATA_OPTION_KEY)
    === RCView::tt(PdfExecutionPlanOdmAdapter::METADATA_OPTION_LABEL_KEY), 'Missing migration category label.');
$configuration = PdfExecutionPlanController::load($pid);
checkTransferPreflight($configuration['core_configuration']['test_action_available'] === false
    && $configuration['core_configuration']['enabled_workflows'] === $beforeCore, 'Native Core configuration failed.');
foreach ($configuration['configuration']['workflows'] as $workflow) {
    checkTransferPreflight(($workflow['terminal_action_reserved_for_core'] ?? null) === false, 'Disabled gate reserved a terminal action.');
}
checkTransferPreflight(PdfExecutionPlanRepository::getProjectExecutionPlan($pid) === $before
    && PdfExecutionPlanRepository::hasProjectExecutionPlan($pid) === $exists
    && PdfCoreTerminalSettings::getEnabledWorkflows($pid) === $beforeCore, 'Read-only preflight changed project settings.');
echo "Native preflight passed for PID $pid: XML selected/excluded/all, API export, PMT selected/excluded, shared controls/category, disabled Core configuration, preserved settings.\n";
