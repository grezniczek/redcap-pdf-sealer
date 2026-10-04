<?php

declare(strict_types=1);

// CLI-only development acceptance. Preview is read-only; run rolls back all test writes.
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
$_SERVER['PHP_SELF'] = 'pdf_plan_management_live.php';
$core = rtrim(getenv('PDF_SEALER_REDCAP_ROOT') ?: '/home/gr/redcap/codebase', '/');
require $core . '/Config/init_global.php';
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
});

use ExternalModules\ExternalModules;
use ExternalModules\PdfFinalize;
use ExternalModules\PdfFinalizeExecutionPlanRequiredException;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanController;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanManager;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanPermissions;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanRepository;
use Vanderbilt\REDCap\Classes\PdfFinalization\PdfExecutionPlanView;

function checkPlan(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function sealerEnabledOverride(int $projectId): mixed
{
    $row = ExternalModules::getSettings('pdf_sealer', $projectId, 'enabled')->fetch_assoc();
    return ExternalModules::validateSettingsRow($row)['value'] ?? null;
}

function refreshPlanTestEnablementCache(): void
{
    (new ReflectionMethod(ExternalModules::class, 'cacheAllEnableData'))->invoke(null);
}

ExternalModules::setProjectId($pid);
$project = new Project($pid);
checkPlan((int)$project->project['status'] === 0 && empty($project->project['date_deleted']), 'Requires an active development project.');
checkPlan(PdfExecutionPlanPermissions::canEdit($pid), 'The supplied user needs native Design/Setup rights.');
checkPlan(!PdfExecutionPlanRepository::hasProjectExecutionPlan($pid), 'Requires no existing execution-plan setting.');
checkPlan(!isset(ExternalModules::getEnabledModules($pid)['pdf_sealer']), 'Requires Sealer disabled in the test project.');
$framework = ExternalModules::getFrameworkInstance('pdf_sealer', 'v9.9.9');
checkPlan(sealerEnabledOverride($pid) === null, 'Requires no existing Sealer enabled override.');
checkPlan(!method_exists($framework->getModuleInstance(), 'redcap_module_project_enable')
    && !method_exists($framework->getModuleInstance(), 'hook_module_project_enable'), 'Enable hooks would break transaction containment.');
foreach ($framework->getConfig()['project-settings'] ?? [] as $setting) {
    checkPlan(!array_key_exists('default', $setting), 'Project defaults are outside this test scope.');
}
$candidate = PdfFinalize::getModuleOperationCatalog('pdf_sealer', 'v9.9.9', $pid);
checkPlan(array_column($candidate, 'identifier') === ['pdf_sealer:seal'], 'Unexpected pending Sealer declarations.');
$logTable = Logging::getLogEventTable($pid);
checkPlan(preg_match('/^redcap_log_event[0-9]*$/D', $logTable) === 1, 'Unexpected project audit table.');
foreach (['redcap_project_settings', 'redcap_external_module_settings', 'redcap_projects', 'redcap_user_information', $logTable] as $table) {
    $query = db_query('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
    checkPlan($query !== false && db_fetch_assoc($query)['ENGINE'] === 'InnoDB', 'Requires transactional table: ' . $table);
}
checkPlan((int)db_fetch_assoc(db_query('SELECT @@autocommit AS enabled'))['enabled'] === 1, 'Requires a fresh CLI connection.');
$initial = PdfExecutionPlanController::load($pid);
checkPlan($initial['can_save'] && $initial['configuration']['execution_plan'] === [], 'Native designer load failed.');
checkPlan(!in_array('pdf_sealer:seal', array_column($initial['configuration']['available_operations'], 'identifier'), true), 'Disabled operation was offered as active.');
$preview = PdfExecutionPlanController::load($pid, ['pdf_sealer:seal'], $candidate);
checkPlan($preview['configuration']['execution_plan'][0]['resolved'], 'Trusted pending-operation preview failed.');
checkPlan(count($preview['configuration']['workflows']) === 3, 'Missing workflow previews.');
ob_start();
PdfExecutionPlanView::render($pid);
$view = ob_get_clean();
preg_match('/window\.RedcapPdfFinalization = (.*?);<\/script>/', $view, $configurationMatch);
$viewConfiguration = json_decode($configurationMatch[1] ?? '', true);
checkPlan(str_contains($view, 'id="redcap-pdf-finalize-plan-template" hidden') && !str_contains($view, 'class="modal')
    && str_ends_with($viewConfiguration['baseUrl'] ?? '', 'PdfFinalization/')
    && preg_match('/>\s*pdf_finalize_manage_/', $view) === 0, 'Native localized Core view failed.');
checkPlan(!PdfExecutionPlanRepository::hasProjectExecutionPlan($pid), 'Loading or rendering created a plan.');
echo "Preflight: PID $pid, native designer rights, disabled Sealer, absent plan, pending declarations and localized Core view passed.\n";
echo "Run scope: native Core saves/audits, Framework placement rejection, explicit placement/nonassignment, duplicate persistence, rollback. No finalization, PKI, edocs or email.\n";
if ($mode !== '--run') { exit; }

$beforeProject = db_fetch_assoc(db_query('SELECT last_logged_event,last_logged_event_exclude_exports FROM redcap_projects WHERE project_id=?', [$pid]));
$beforeUser = db_fetch_assoc(db_query('SELECT user_firstactivity,user_lastactivity FROM redcap_user_information WHERE username=?', [USERID]));
$auditRows = static function () use ($pid, $logTable): array {
    $query = db_query("SELECT log_event_id,user,event,data_values FROM $logTable WHERE project_id=? AND description=? ORDER BY log_event_id",
        [$pid, 'Modify PDF finalization execution plan']);
    $rows = [];
    while ($row = db_fetch_assoc($query)) { $rows[] = $row; }
    return $rows;
};
$beforeAudits = $auditRows();
checkPlan(db_query('SET AUTOCOMMIT=0') !== false, 'Could not start rollback containment.');
try {
    PdfExecutionPlanController::save($pid, '[]');
    checkPlan(PdfExecutionPlanRepository::hasProjectExecutionPlan($pid), 'Explicit empty save lost configured state.');
    try {
        ExternalModules::enableForProject('pdf_sealer', 'v9.9.9', $pid);
        throw new RuntimeException('Enablement accepted a missing placement decision.');
    } catch (PdfFinalizeExecutionPlanRequiredException) {
        checkPlan(sealerEnabledOverride($pid) === null, 'Placement rejection enabled Sealer.');
    }
    $originalConfig = $framework->getConfig();
    $failureConfig = $originalConfig;
    $failureConfig['project-settings'] = [[
        'key' => str_repeat('x', ExternalModules::SETTING_KEY_SIZE_LIMIT + 1),
        'name' => 'Acceptance invalid default', 'type' => 'text', 'default' => 'trigger failure',
    ]];
    ExternalModules::setCachedConfig('pdf_sealer', 'v9.9.9', true, $failureConfig);
    try {
        try {
            ExternalModules::enableForProject('pdf_sealer', 'v9.9.9', $pid, ['pdf_sealer:seal']);
            throw new RuntimeException('Invalid default did not fail enablement.');
        } catch (Exception $exception) {
            checkPlan(str_contains($exception->getMessage(), 'key is longer'), 'Unexpected enablement failure.');
        }
    } finally {
        ExternalModules::setCachedConfig('pdf_sealer', 'v9.9.9', true, $originalConfig);
    }
    checkPlan(sealerEnabledOverride($pid) === null && PdfExecutionPlanRepository::getProjectExecutionPlan($pid) === [],
        'Failed enablement created an inherited override or replaced the prior plan.');
    ExternalModules::enableForProject('pdf_sealer', 'v9.9.9', $pid, ['pdf_sealer:seal']);
    checkPlan(isset(ExternalModules::getEnabledModules($pid)['pdf_sealer']), 'Explicit placement did not enable Sealer.');
    $loaded = PdfExecutionPlanController::load($pid);
    checkPlan($loaded['configuration']['execution_plan'][0]['resolved'], 'Enabled declaration did not resolve on reopen.');
    $duplicates = ['pdf_sealer:seal', 'pdf_sealer:seal'];
    PdfExecutionPlanController::save($pid, json_encode($duplicates));
    $loaded = PdfExecutionPlanController::load($pid);
    checkPlan(array_column($loaded['configuration']['execution_plan'], 'identifier') === $duplicates, 'Saved order/duplicates changed.');
    checkPlan(in_array('duplicate', array_column($loaded['configuration']['execution_plan'][0]['warnings'], 'code'), true), 'Missing duplicate warning.');
    checkPlan($loaded['configuration']['workflows'][1]['operations'] === [], 'eConsent-only operation leaked into record-PDF preview.');
    $count = count($auditRows());
    PdfExecutionPlanController::save($pid, json_encode($duplicates));
    checkPlan(count($auditRows()) === $count, 'Unchanged save emitted another audit.');
    ExternalModules::removeProjectSetting('pdf_sealer', $pid, 'enabled');
    refreshPlanTestEnablementCache();
    $loaded = PdfExecutionPlanController::load($pid);
    checkPlan(!$loaded['configuration']['execution_plan'][0]['resolved'], 'Disabled saved operation should remain unavailable.');
    PdfExecutionPlanController::save($pid, json_encode($duplicates));
    try {
        PdfExecutionPlanController::save($pid, '["core:untrusted"]');
        throw new RuntimeException('Submitted Core identifier was accepted.');
    } catch (InvalidArgumentException) {
        checkPlan(PdfExecutionPlanRepository::getProjectExecutionPlan($pid) === $duplicates, 'Rejected save changed stored intent.');
    }
    PdfExecutionPlanController::save($pid, '[]');
    ExternalModules::enableForProject('pdf_sealer', 'v9.9.9', $pid, []);
    checkPlan(isset(ExternalModules::getEnabledModules($pid)['pdf_sealer'])
        && PdfExecutionPlanRepository::getProjectExecutionPlan($pid) === [], 'Explicit nonassignment failed.');
    $newAudits = array_slice($auditRows(), count($beforeAudits));
    checkPlan(array_map(static fn(array $row): array => json_decode($row['data_values'], true), $newAudits)
        === [[], ['pdf_sealer:seal'], $duplicates, []], 'Native audit sequence differs from changed plans.');
    foreach ($newAudits as $row) {
        checkPlan((int)$row['log_event_id'] > 0 && $row['user'] === USERID && $row['event'] === 'OTHER', 'Native audit attribution failed.');
    }
    echo "Native save/reopen, placement rejection, Save & Enable, duplicate/unavailable retention, no-op audit, Core-ID rejection and nonassignment passed.\n";
} finally {
    checkPlan(db_query('ROLLBACK') !== false, 'Rollback failed.');
    checkPlan(db_query('SET AUTOCOMMIT=1') !== false, 'Could not restore autocommit.');
    refreshPlanTestEnablementCache();
}
checkPlan(!PdfExecutionPlanRepository::hasProjectExecutionPlan($pid)
    && sealerEnabledOverride($pid) === null
    && !isset(ExternalModules::getEnabledModules($pid)['pdf_sealer']), 'Rollback left a plan or module override.');
checkPlan($auditRows() === $beforeAudits, 'Rollback left project audits.');
checkPlan(db_fetch_assoc(db_query('SELECT last_logged_event,last_logged_event_exclude_exports FROM redcap_projects WHERE project_id=?', [$pid])) === $beforeProject,
    'Rollback changed project activity.');
checkPlan(db_fetch_assoc(db_query('SELECT user_firstactivity,user_lastactivity FROM redcap_user_information WHERE username=?', [USERID])) === $beforeUser,
    'Rollback changed user activity.');
echo "Rollback verified: absent plan/override, prior audits and user/project activity restored.\n";
