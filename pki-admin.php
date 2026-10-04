<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Dependencies\Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Diagnostics\DiagnosticSnapshot;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\ExpiryMonitor;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationService;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

/** @var \DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule $module */
$framework = $module->framework;
if (!$framework->isSuperUser() || $framework->getProjectId() !== null) {
    http_response_code(403);
    exit($framework->tt('pki_access_denied'));
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sealingSupport = \DE\RUB\PDFSealerExternalModule\Pdf\SealingSupport::inspect();
$protector = new SecretProtector();
$identities = new IdentityRepository($framework, $protector);
$health = new PkiHealthService($identities, $protector);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $notice = 'invalid';
    if (($_POST['action'] ?? null) === 'initialize' && is_string($_POST['organization'] ?? null)) {
        try {
            (new PkiInitializationService(
                $framework,
                $identities,
                new ProjectBindingRepository($framework),
                CertificateIssuer::forFramework($framework),
                $health,
                new PkiInitializationLock(),
            ))->initialize($_POST['organization']);
            $notice = 'initialized';
        } catch (Throwable $e) {
            error_log('PDF Sealer PKI initialization failed (' . get_class($e) . ')');
            $notice = 'init_failed';
        }
    }
    header('Location: ' . $framework->getUrl('pki-admin.php') . '&pki_notice=' . $notice, true, 303);
    exit;
}
$notice = $_GET['pki_notice'] ?? null;
$error = $notice === 'invalid' ? $framework->tt('pki_invalid_request')
    : ($notice === 'init_failed' ? $framework->tt('pki_init_failed') : null);
$success = $notice === 'initialized';
$report = $health->inspect(time());
$settings = new PrimarySystemSettingReader($framework);
$organization = $settings->get('organization');
$timestampSettings = null;
try {
    $timestampSettings = $identities->providers()->timestampSettings(\DE\RUB\PDFSealerExternalModule\Pki\ProviderRepository::BUILTIN_CA);
} catch (Throwable) {
    // Show an explicit unknown state; do not silently replace invalid stored settings with defaults.
}
$assignmentRequired = null;
$providerCatalog = []; $providerCertificates = []; $providersUnavailable = false; $builtinSourceAvailable = false;
try {
    $providers = $identities->providers();
    $assignmentRequired = $providers->requiresAssignment();
    if ($providers->hasConfiguration()) {
        $providerCatalog[] = $providers->provider($providers::BUILTIN_CA);
        $providers->source($providers::BUILTIN_TSA);
        $builtinSourceAvailable = true;
    }
    foreach ($providers->externalIds() as $providerId) { $providerCatalog[] = $providers->provider($providerId); }
    foreach ($providerCatalog as &$provider) { $provider['retired'] = $providers->isRetired($provider['id']); }
    unset($provider);
    $providerCertificates = $providers->publicCertificates();
} catch (Throwable) { $providersUnavailable = true; }
$sourceSummaries = []; $sourcesUnavailable = false;
$sourceChoices = ['none' => $framework->tt('timestamp_mode_none')];
if ($builtinSourceAvailable) { $sourceChoices['builtin-tsa'] = $framework->tt('timestamp_mode_internal'); }
try {
    $sourceSummaries = (new \DE\RUB\PDFSealerExternalModule\Timestamp\ExternalTimestampSources($framework))->summaries();
    foreach ($sourceSummaries as $source) { $sourceChoices[$source['id']] = $source['name']; }
} catch (Throwable) { $sourcesUnavailable = true; }
$timestampPolicies = array_map(static fn(array $p): array => array_intersect_key($p, array_flip(['id', 'timestamp_source', 'timestamp_alternatives', 'bb_fallback'])), $providerCatalog);
$assignableProviders = array_values(array_filter($providerCatalog, static fn(array $p): bool => !($p['retired'] ?? true)));
$builtinRetired = false;
foreach ($providerCatalog as $p) { if ($p['id'] === $providers::BUILTIN_CA) { $builtinRetired = $p['retired'] ?? false; } }
$assignmentProjects = [];
$transitionProjects = [];
$transitionBindings = [];
$transitionEnrollments = [];
$renewalProjects = [];
$renewalPids = [];
$revocationProjects = [];
$revocationPids = [];
$assignedPids = [];
$assignmentProjectsUnavailable = false;
try {
    // Unlike the settings dialog's project-id choices, CC must also include
    // enabled projects where the superuser is not a project member.
    $enabledPids = $framework->getProjectsWithModuleEnabled();
    // Any binding (including pending issuance/enrollment) already fixes the provider.
    $bindings = (new \DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader($framework))->query(
        'SELECT redcap_pid, MAX(log_id) AS latest_id WHERE message = ? AND ISNULL(project_id) GROUP BY redcap_pid',
        ['project_identity_binding'],
    );
    if ($bindings === false) { throw new RuntimeException('Project bindings unavailable'); }
    $assignedPids = [];
    $latestIds = [];
    while ($binding = $bindings->fetch_assoc()) {
        $assignedPids[] = $binding['redcap_pid'];
        $latestIds[] = $binding['latest_id'];
    }
    // Filter by the latest binding, not a historical built-in assignment.
    foreach (array_chunk($latestIds, 200) as $batch) {
        $latest = (new \DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader($framework))->query(
            'SELECT redcap_pid, provider_id, identity_id, pending_provider_id, transition_id WHERE message = ? AND ISNULL(project_id) AND log_id IN ('
                . implode(',', array_fill(0, count($batch), '?')) . ')', ['project_identity_binding', ...$batch]);
        if ($latest === false) { throw new RuntimeException('Renewal project selector unavailable'); }
        while ($binding = $latest->fetch_assoc()) {
            $transitionBindings[(string) $binding['redcap_pid']] = array_intersect_key($binding, array_flip(['provider_id', 'identity_id', 'pending_provider_id', 'transition_id']));
            if (($binding['provider_id'] ?? null) === $providers::BUILTIN_CA
                && is_string($binding['identity_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $binding['identity_id']) === 1) {
                $revocationPids[] = (string) $binding['redcap_pid'];
            }
            if (($binding['provider_id'] ?? null) === $providers::BUILTIN_CA && !$builtinRetired
                && is_string($binding['identity_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $binding['identity_id']) === 1
                && ($binding['pending_provider_id'] ?? null) === null) {
                $renewalPids[] = (string) $binding['redcap_pid'];
            }
        }
    }
    // Public enrollment audit metadata is enough for the table; actions still obtain a locked service preview.
    $enrollments = (new \DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader($framework))->query(
        'SELECT MAX(log_id) AS latest_id WHERE message = ? AND ISNULL(project_id) GROUP BY redcap_pid', ['project_enrollment']);
    if ($enrollments === false) { throw new RuntimeException('Enrollment list unavailable'); }
    $enrollmentIds = [];
    while ($enrollment = $enrollments->fetch_assoc()) { $enrollmentIds[] = $enrollment['latest_id']; }
    foreach (array_chunk($enrollmentIds, 200) as $batch) {
        $latest = (new \DE\RUB\PDFSealerExternalModule\Pki\PrimaryLogReader($framework))->query(
            'SELECT redcap_pid, action, provider_id, enrollment_id WHERE message = ? AND ISNULL(project_id) AND log_id IN ('
                . implode(',', array_fill(0, count($batch), '?')) . ')', ['project_enrollment', ...$batch]);
        if ($latest === false) { throw new RuntimeException('Enrollment list unavailable'); }
        while ($enrollment = $latest->fetch_assoc()) {
            $binding = $transitionBindings[(string) $enrollment['redcap_pid']] ?? null;
            if ($binding !== null && $enrollment['action'] === 'generate'
                && $enrollment['provider_id'] === ($binding['pending_provider_id'] ?? $binding['provider_id'])) {
                $transitionEnrollments[(string) $enrollment['redcap_pid']] = $enrollment['enrollment_id'];
            }
        }
    }
    // Keep assignment/transition/renewal choices enabled-only; revocation also includes retained disabled bindings.
    $assignedPids = array_map('strval', $assignedPids);
    $selectorPids = array_values(array_unique([...$enabledPids, ...$revocationPids]));
    if ($selectorPids !== []) {
        $rows = $framework->query('SELECT project_id, app_title, status, completed_time FROM redcap_projects WHERE project_id IN ('
            . implode(',', array_fill(0, count($selectorPids), '?')) . ') ORDER BY app_title, project_id', $selectorPids);
        if ($rows === false) { throw new RuntimeException('Project selector unavailable'); }
        while ($row = $rows->fetch_assoc()) {
            if (in_array((int) $row['project_id'], array_map('intval', $enabledPids), true)) {
                if (in_array((string) $row['project_id'], $assignedPids, true)) {
                    $transitionProjects[] = $row + ['provider' => ($transitionBindings[(string) $row['project_id']] ?? [])
                        + ['identity_id' => null, 'pending_provider_id' => null, 'transition_id' => null,
                            'enrollment_id' => $transitionEnrollments[(string) $row['project_id']] ?? null]];
                }
                else { $assignmentProjects[] = $row; }
                if (in_array((string) $row['project_id'], $renewalPids, true)) { $renewalProjects[] = $row; }
            }
            if (in_array((string) $row['project_id'], $revocationPids, true)) { $revocationProjects[] = $row; }
        }
    }
} catch (Throwable) { $assignmentProjectsUnavailable = true; }
$recipients = $framework->getSystemSetting('admin-alert-recipients');
$recipients = is_array($recipients) ? implode(', ', array_filter($recipients, 'is_string')) : (is_string($recipients) ? $recipients : '');
$snapshot = null;
$snapshotUnavailable = false;
try { $snapshot = (new DiagnosticSnapshot($framework))->load(); }
catch (Throwable) { $snapshotUnavailable = true; }
if ($snapshot !== null) {
    try { $snapshot['versions_changed'] = DiagnosticSnapshot::versionsChanged($snapshot, DiagnosticSnapshot::currentVersions($identities)); }
    catch (Throwable) { $snapshot['versions_changed'] = true; }
}
$maintenanceSnapshot = null;
$maintenanceUnavailable = false;
try { $maintenanceSnapshot = \DE\RUB\PDFSealerExternalModule\Pki\BuiltinMaintenanceService::load($settings); }
catch (Throwable) { $maintenanceUnavailable = true; }
$expirySnapshot = null;
$expiryUnavailable = false;
try { $expirySnapshot = ExpiryMonitor::load($framework); }
catch (Throwable) { $expiryUnavailable = true; }
$certificates = [];
foreach (['root', 'tsa'] as $role) {
    try {
        $id = $identities->activeId($role);
        $identity = $id === null ? null : $identities->find($id);
        $details = $identity === null ? false : openssl_x509_parse(Certificate::derToPem($identity->certificateDer));
        if (is_array($details)) {
            $certificates[$role] = ['details' => $details, 'fingerprint' => hash('sha256', $identity->certificateDer),
                'thumbprint' => hash('sha1', $identity->certificateDer)];
        }
    } catch (Throwable) { /* The tab will show an explicit unavailable state. */ }
}
$renderCertificate = static function (string $role) use ($certificates, $framework, $escape, $module): void {
    $certificate = $certificates[$role] ?? null;
    if ($certificate === null) {
        ?><p class="alert alert-warning"><?= $escape($framework->tt('pki_certificate_unavailable')) ?></p><?php
        return;
    }
    $details = $certificate['details'];
    ?>
    <dl class="pdf-sealer-certificate">
        <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd><?= $module::certificateSubjectHtml($details['name'] ?? '') ?></dd>
        <dt><?= $escape($framework->tt('trust_valid_from')) ?></dt><dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $details['validFrom_time_t'] ?? 0)) ?></dd>
        <dt><?= $escape($framework->tt('pki_valid_until')) ?></dt><dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $details['validTo_time_t'] ?? 0)) ?></dd>
        <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code class="pdf-sealer-fingerprint"><?= $escape($certificate['fingerprint']) ?></code></dd>
        <dt><?= $escape($framework->tt('pki_thumbprint')) ?></dt><dd><code class="pdf-sealer-fingerprint"><?= $escape($certificate['thumbprint']) ?></code></dd>
    </dl>
    <?php
};
require_once APP_PATH_DOCROOT . 'ControlCenter/header.php';
$framework->initializeJavascriptModuleObject();
foreach (['external_tsa_failed', 'external_tsa_passed', 'external_tsa_test_failed', 'external_tsa_testing', 'timestamp_order_invalid', 'diagnostic_never', 'pki_fingerprint', 'pki_thumbprint'] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
foreach (['provider_assignment_done', 'provider_assignment_partial', 'provider_assignment_mark', 'provider_selection_count', 'provider_selection_one', 'provider_no_unassigned_projects'] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
foreach ([
    'assignment_policy_change', 'assignment_policy_label', 'assignment_policy_help', 'assignment_policy_delivery',
    'assignment_policy_save', 'assignment_policy_saved', 'assignment_policy_failed', 'assignment_policy_explicit',
    'assignment_policy_automatic', 'provider_builtin', 'provider_details', 'provider_usage',
    'provider_manage_title', 'provider_active', 'provider_retired', 'provider_retire',
    'provider_reactivate', 'provider_pid', 'provider_active_signer', 'provider_pending_enrollment',
    'transition_pending_label', 'provider_retirement_counts', 'provider_retirement_help', 'provider_reactivation_help',
    'provider_retirement_gate', 'provider_lifecycle_failed', 'provider_lifecycle_saved', 'provider_review_failed',
    'provider_dialog_unavailable', 'provider_workflow_failed', 'provider_register', 'provider_request_failed', 'provider_usage_empty', 'table_search', 'table_length',
    'table_info', 'table_info_empty', 'table_info_filtered', 'table_zero',
    'table_first', 'table_last', 'table_next', 'table_previous',
] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
foreach (['root_lifecycle_title', 'root_lifecycle_review', 'root_lifecycle_action', 'root_lifecycle_confirm_button', 'root_lifecycle_compromise_ack', 'root_lifecycle_dialog_unavailable', 'root_lifecycle_already', 'root_lifecycle_renew', 'root_lifecycle_superseded', 'root_lifecycle_compromise', 'pki_subject', 'root_lifecycle_failed', 'root_lifecycle_saved', 'root_lifecycle_renewed', 'root_lifecycle_replaced', 'root_lifecycle_pending', 'root_lifecycle_trust', 'root_lifecycle_projects_done', 'root_lifecycle_projects_pending', 'root_lifecycle_renew_help', 'root_lifecycle_superseded_help', 'root_lifecycle_compromise_help', 'root_lifecycle_dependents', 'tsa_lifecycle_failed', 'tsa_lifecycle_confirm_prompt', 'tsa_lifecycle_saved', 'tsa_lifecycle_replaced', 'tsa_lifecycle_changed', 'tsa_lifecycle_pending', 'tsa_lifecycle_replace_help', 'tsa_lifecycle_superseded_help', 'tsa_lifecycle_compromise_help', 'revocation_title', 'revocation_confirm', 'revocation_failed', 'revocation_confirm_prompt', 'revocation_saved', 'revocation_crl_published', 'revocation_crl_pending', 'revocation_replaced', 'revocation_signer_changed', 'revocation_replacement_pending', 'revocation_already', 'renewal_failed', 'renewal_saved', 'renewal_issuer_expiry'] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
foreach (['transition_current', 'transition_waiting_provider', 'transition_has_signer', 'transition_no_signer', 'transition_cancel_csr_first', 'transition_builtin_help', 'transition_external_help', 'transition_cancel_help', 'transition_bulk_selection', 'transition_bulk_activated', 'transition_bulk_prepared', 'transition_bulk_canceled', 'transition_bulk_failed', 'transition_bulk_refresh_failed', 'transition_info_unavailable', 'transition_choose_project', 'provider_select_project'] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
?>
<link rel="stylesheet" href="<?= $escape($framework->getUrl('assets/admin.css')) ?>">
<div class="pdf-sealer-admin">
    <p class="pdf-sealer-brand text-muted"><em><?= $escape($framework->tt('pki_brand')) ?></em></p>
    <h4 class="mb-2"><i class="fas fa-file-signature" aria-hidden="true"></i> <?= $escape($framework->tt('pki_page_title')) ?></h4>
    <p><?= $escape($framework->tt('pki_page_intro')) ?></p>
    <?php require __DIR__ . '/views/sealing-support.php'; ?>
    <div class="pdf-sealer-summary">
        <div class="pdf-sealer-card pdf-sealer-health-<?= $escape(strtolower($report->status->value)) ?>">
            <div class="small text-muted"><?= $escape($framework->tt('pki_status')) ?></div>
            <strong><?= $escape($framework->tt('pki_state_' . strtolower($report->status->value))) ?></strong>
        </div>
        <div class="pdf-sealer-card"><div class="small text-muted"><?= $escape($framework->tt('pki_organization')) ?></div><strong><?= $escape(is_string($organization) && $organization !== '' ? $organization : $framework->tt('pki_not_configured')) ?></strong></div>
        <div class="pdf-sealer-card"><div class="small text-muted"><?= $escape($framework->tt('timestamp_mode_label')) ?></div><strong id="pdf-sealer-mode-summary"><?= $escape($timestampSettings === null ? $framework->tt('pki_not_configured') : $framework->tt('timestamp_summary_' . $timestampSettings->mode)) ?></strong></div>
    </div>
    <p class="pdf-sealer-trust-link"><a href="<?= $escape($module::publicTrustUrl()) ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt" aria-hidden="true"></i> <?= $framework->tt('pki_public_trust_page') ?></a></p>
    <div class="nav nav-tabs" role="tablist" aria-label="<?= $escape($framework->tt('pki_page_title')) ?>">
        <?php foreach (['root' => 'fa-certificate', 'providers' => 'fa-building', 'tsa' => 'fa-clock', 'diagnostic' => 'fa-stethoscope', 'alarms' => 'fa-bell'] as $tab => $icon): ?>
            <button type="button" class="nav-link<?= $tab === 'root' ? ' active' : '' ?>" role="tab" id="pki-tab-<?= $tab ?>" aria-controls="pki-panel-<?= $tab ?>" aria-selected="<?= $tab === 'root' ? 'true' : 'false' ?>" tabindex="<?= $tab === 'root' ? '0' : '-1' ?>" data-pki-tab="<?= $tab ?>"><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= $escape($framework->tt('pki_tab_' . $tab)) ?></button>
        <?php endforeach; ?>
    </div>
    <section class="pdf-sealer-panel" id="pki-panel-root" role="tabpanel" aria-labelledby="pki-tab-root" tabindex="0">
        <h5><?= $escape($framework->tt('pki_root')) ?></h5>
        <p class="text-muted"><?= $escape($framework->tt('pki_root_help')) ?></p>
        <?php if ($builtinRetired): ?><p class="alert alert-warning"><?= $escape($framework->tt('provider_retired_public')) ?></p><?php endif; ?>
    <?php if ($report->status === PkiHealth::Uninitialized): ?>
        <form method="post">
            <input type="hidden" name="redcap_external_module_csrf_token" value="<?= $escape($framework->getCSRFToken()) ?>">
            <input type="hidden" name="action" value="initialize">
            <div class="mb-3">
                <label for="pdf-sealer-organization"><?= $escape($framework->tt('pki_organization')) ?></label>
                <input id="pdf-sealer-organization" class="form-control" type="text" name="organization" maxlength="128" required value="<?= $escape(is_string($organization) ? $organization : '') ?>">
            </div>
            <button type="submit" class="btn btn-primaryrc btn-sm"><?= $escape($framework->tt('pki_initialize')) ?></button>
        </form>
    <?php else: ?>
        <?php if ($report->status === PkiHealth::Broken): ?><p class="alert alert-danger"><?= $escape($framework->tt('pki_broken')) ?></p><?php endif; ?>
        <?php $renderCertificate('root'); ?>
        <?php if (isset($certificates['root'])): ?>
            <div class="pdf-sealer-actions">
                <button type="button" class="btn btn-primaryrc btn-sm" data-pki-root-download="pem"><i class="fas fa-download" aria-hidden="true"></i> <?= $escape($framework->tt('pki_download_root_pem')) ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-pki-root-download="der"><i class="fas fa-download" aria-hidden="true"></i> <?= $escape($framework->tt('pki_download_root_der')) ?></button>
            </div>
            <p class="small text-muted mt-2"><?= $escape($framework->tt('pki_download_root_help')) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <hr>
    <?php require __DIR__ . '/views/root-lifecycle.php'; ?>
    </section>
    <section class="pdf-sealer-panel" id="pki-panel-providers" role="tabpanel" aria-labelledby="pki-tab-providers" tabindex="0" hidden>
        <h5><?= $escape($framework->tt('pki_tab_providers')) ?></h5>
        <p><?= $escape($framework->tt('provider_intro')) ?></p>
        <?php if ($providersUnavailable): ?>
            <p class="alert alert-warning"><?= $escape($framework->tt('provider_unavailable')) ?></p>
        <?php else: ?>
            <?php require __DIR__ . '/views/providers.php'; ?>
            <?php require __DIR__ . '/views/provider-workflows.php'; ?>
        <?php endif; ?>
    </section>
    <section class="pdf-sealer-panel" id="pki-panel-tsa" role="tabpanel" aria-labelledby="pki-tab-tsa" tabindex="0" hidden>
        <h5><?= $escape($framework->tt('pki_tsa')) ?></h5>
        <p class="text-muted"><?= $escape($framework->tt('pki_tsa_help')) ?></p>
        <?php $renderCertificate('tsa'); ?>
        <hr>
        <?php require __DIR__ . '/views/tsa-lifecycle.php'; ?>
        <hr>
        <?php require __DIR__ . '/views/timestamp-admin.php'; ?>
    </section>
    <section class="pdf-sealer-panel" id="pki-panel-diagnostic" role="tabpanel" aria-labelledby="pki-tab-diagnostic" tabindex="0" hidden>
        <?php if ($builtinRetired): ?><p class="alert alert-warning"><?= $escape($framework->tt('provider_retired_diagnostic')) ?></p><?php endif; ?>
    <h5><?= $escape($framework->tt('diagnostic_title')) ?></h5>
    <p><?= $escape($framework->tt('diagnostic_help')) ?></p>
    <p class="text-muted"><?= $escape($framework->tt('diagnostic_limits')) ?></p>
    <div id="pdf-sealer-diagnostic-snapshot" class="pdf-sealer-card pdf-sealer-snapshot" aria-live="polite">
        <div class="small text-muted"><?= $escape($framework->tt('diagnostic_last_run')) ?></div>
        <strong id="pdf-sealer-diagnostic-age"><?= $escape($framework->tt('diagnostic_never')) ?></strong>
        <time id="pdf-sealer-diagnostic-time" class="d-block small"></time>
        <div id="pdf-sealer-diagnostic-outcome" class="mt-2"></div>
    </div>
    <div id="pdf-sealer-diagnostic-versions" class="alert alert-warning mt-2" role="status" hidden><?= $escape($framework->tt('diagnostic_versions_changed')) ?></div>
    <p class="small text-muted"><?= $escape($framework->tt('diagnostic_snapshot_help')) ?></p>
    <div id="pdf-sealer-diagnostic-cache-message" class="alert alert-warning" role="status" hidden></div>
    <button id="pdf-sealer-diagnostic" type="button" class="btn btn-primaryrc btn-sm"><?= $escape($framework->tt('diagnostic_run')) ?></button>
    <table id="pdf-sealer-diagnostic-results" class="table table-sm" hidden>
        <thead><tr><th scope="col"><?= $escape($framework->tt('diagnostic_check')) ?></th><th scope="col"><?= $escape($framework->tt('diagnostic_result')) ?></th></tr></thead>
        <tbody>
        <?php foreach (DiagnosticSnapshot::CHECKS as $check): ?>
            <tr><th scope="row"><?= $escape($framework->tt('diagnostic_' . $check)) ?></th><td data-diagnostic-check="<?= $escape($check) ?>"></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </section>
    <section class="pdf-sealer-panel" id="pki-panel-alarms" role="tabpanel" aria-labelledby="pki-tab-alarms" tabindex="0" hidden>
    <h5><?= $escape($framework->tt('maintenance_title')) ?></h5>
    <p class="text-muted"><?= $escape($framework->tt('maintenance_help')) ?></p>
    <?php if ($maintenanceUnavailable): ?>
        <p class="alert alert-warning"><?= $escape($framework->tt('maintenance_unavailable')) ?></p>
    <?php elseif ($maintenanceSnapshot === null): ?>
        <p class="text-muted"><?= $escape($framework->tt('maintenance_never')) ?></p>
    <?php else: ?>
        <?php if (time() - $maintenanceSnapshot['completed_at'] > 7200): ?>
            <p class="alert alert-warning"><?= $escape($framework->tt('maintenance_stale')) ?></p>
        <?php endif; ?>
        <div class="pdf-sealer-card mb-3">
            <strong class="<?= $maintenanceSnapshot['status'] === 'failed' ? 'text-danger' : ($maintenanceSnapshot['status'] === 'pending' ? 'text-warning' : 'text-success') ?>"><?= $escape($framework->tt('maintenance_status_' . $maintenanceSnapshot['status'])) ?></strong>
            <time class="d-block small text-muted" data-expiry-epoch="<?= $maintenanceSnapshot['completed_at'] ?>"><?= $escape(gmdate('c', $maintenanceSnapshot['completed_at'])) ?></time>
            <div><?= $escape($framework->tt('maintenance_counts', [
                'renewed' => $maintenanceSnapshot['renewed'], 'deferred' => $maintenanceSnapshot['deferred'],
                'failed' => $maintenanceSnapshot['failed'], 'remaining' => $maintenanceSnapshot['remaining'],
            ])) ?></div>
        </div>
    <?php endif; ?>
    <h5><?= $escape($framework->tt('expiry_title')) ?></h5>
    <p class="text-muted"><?= $escape($framework->tt('expiry_help')) ?></p>
    <?php if ($expiryUnavailable || $expirySnapshot === null): ?>
        <p class="alert alert-warning"><?= $escape($framework->tt($expiryUnavailable ? 'expiry_unavailable' : 'expiry_never')) ?></p>
    <?php else: ?>
        <p><strong><?= $escape($framework->tt('expiry_last_check')) ?>:</strong>
            <time data-expiry-epoch="<?= $escape($expirySnapshot['completed_at']) ?>"><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $expirySnapshot['completed_at'])) ?></time></p>
        <?php if (time() - $expirySnapshot['completed_at'] > 172800 || $expirySnapshot['completed_at'] > time() + 60): ?>
            <p class="alert alert-warning"><?= $escape($framework->tt('expiry_stale')) ?></p>
        <?php endif; ?>
        <?php if ($expirySnapshot['status'] !== 'ok'): ?>
            <p class="alert alert-warning"><?= $escape($framework->tt('expiry_status_' . $expirySnapshot['status'])) ?></p>
        <?php else: ?>
            <table class="table table-sm">
                <thead><tr><th><?= $escape($framework->tt('expiry_condition')) ?></th><th><?= $escape($framework->tt('expiry_count')) ?></th></tr></thead>
                <tbody><?php foreach (ExpiryMonitor::BANDS as $band): ?>
                    <tr class="<?= $expirySnapshot['counts'][$band] === 0 ? '' : (in_array($band, ['invalid', 'expired', '7d'], true) ? 'table-danger' : ($band === 'healthy' ? 'table-success' : 'table-warning')) ?>">
                        <td><?= $escape($framework->tt('expiry_band_' . $band)) ?></td><td><?= $escape($expirySnapshot['counts'][$band]) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
            <?php if ($expirySnapshot['nearest_expiry'] !== null): ?>
                <p><?= $escape($framework->tt('expiry_nearest')) ?>: <?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $expirySnapshot['nearest_expiry'])) ?></p>
            <?php endif; ?>
            <?php if ($expirySnapshot['items'] !== []): ?>
                <p class="small text-muted"><?= $escape($framework->tt('expiry_details_help')) ?></p>
                <table class="table table-sm">
                    <thead><tr><th><?= $escape($framework->tt('expiry_identity')) ?></th><th><?= $escape($framework->tt('expiry_condition')) ?></th><th><?= $escape($framework->tt('pki_valid_until')) ?></th></tr></thead>
                    <tbody><?php foreach ($expirySnapshot['items'] as $item): ?>
                        <tr><td><?= $escape($framework->tt('expiry_role_' . $item['role'])) ?><?= $item['pid'] === null ? '' : ' (PID ' . $escape($item['pid']) . ')' ?><br><code><?= $escape($item['id']) ?></code></td>
                            <td><?= $escape($framework->tt('expiry_band_' . $item['band'])) ?></td>
                            <td><?= $item['expires'] === null ? '—' : $escape(gmdate('Y-m-d H:i:s \U\T\C', $item['expires'])) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
        <p><?= $escape($framework->tt('expiry_mail_' . $expirySnapshot['mail_status'])) ?></p>
    <?php endif; ?>
    <hr>
    <h5><?= $escape($framework->tt('admin_alert_recipients')) ?></h5>
    <p><?= $escape($framework->tt('admin_alert_recipients_help')) ?></p>
    <div class="mb-3">
        <label for="pdf-sealer-recipients"><?= $escape($framework->tt('admin_alert_recipients')) ?></label>
        <textarea id="pdf-sealer-recipients" class="form-control form-control-sm" rows="3" maxlength="4096"><?= $escape($recipients) ?></textarea>
    </div>
    <button id="pdf-sealer-save-recipients" type="button" class="btn btn-primaryrc btn-sm"><?= $escape($framework->tt('admin_alert_recipients_save')) ?></button>

    <hr>
    <h5><?= $escape($framework->tt('alarm_test_title')) ?></h5>
    <p class="text-muted"><?= $escape($framework->tt('alarm_test_help')) ?></p>
    <button id="pdf-sealer-test-alarm" type="button" class="btn btn-outline-secondary btn-sm"><i class="fas fa-paper-plane" aria-hidden="true"></i> <?= $escape($framework->tt('alarm_test_button')) ?></button>
    </section>
</div>
<script src="<?= $escape($framework->getUrl('assets/admin-notifications.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/timestamp-admin.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/project-renewal.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/project-revocation.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/tsa-lifecycle.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/root-lifecycle.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/providers-admin.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/provider-transition.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/provider-assignment.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/provider-workflows.js')) ?>"></script>
<script>
(() => {
    const module = <?= $framework->getJavascriptModuleObjectName() ?>;
    const notify = window.PDFSealerNotify;
    const notices = <?= json_encode(array_values(array_filter([
        $error === null ? null : ['text' => $error, 'tone' => 'error'],
        $success ? ['text' => $framework->tt('pki_init_success'), 'tone' => 'success'] : null,
        ($_GET['tsa_notice'] ?? null) === 'saved' ? ['text' => $framework->tt('external_tsa_saved'), 'tone' => 'success'] : null,
        ($_GET['tsa_notice'] ?? null) === 'registered' ? ['text' => $framework->tt('external_tsa_registered'), 'tone' => 'success'] : null,
        ($_GET['provider_notice'] ?? null) === 'registered' ? ['text' => $framework->tt('provider_registered'), 'tone' => 'success'] : null,
    ])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    notices.forEach(notice => notify(notice.text, notice.tone));
    if (notices.length) {
        const url = new URL(location.href);
        ['pki_notice', 'tsa_notice', 'provider_notice'].forEach(key => url.searchParams.delete(key));
        history.replaceState(null, '', url.href);
    }
    const tabs = [...document.querySelectorAll('[data-pki-tab]')];
    const selectTab = (name, focus = false) => {
        if (!tabs.some(tab => tab.dataset.pkiTab === name)) name = 'root';
        tabs.forEach(tab => {
            const active = tab.dataset.pkiTab === name;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
            if (active && focus) tab.focus();
        });
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => {
            selectTab(tab.dataset.pkiTab);
            history.replaceState(null, '', '#' + tab.dataset.pkiTab);
        });
        tab.addEventListener('keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            selectTab(tabs[next].dataset.pkiTab, true);
            history.replaceState(null, '', '#' + tabs[next].dataset.pkiTab);
        });
    });
    selectTab(location.hash.slice(1));
    window.addEventListener('hashchange', () => selectTab(location.hash.slice(1)));
    if (document.getElementById('pdf-sealer-provider-register')) {
        const source = document.getElementById('provider-source');
        const fallback = document.getElementById('provider-fallback');
        const updateFallback = () => { fallback.disabled = source.value === 'none' || source.value === ''; if (fallback.disabled) fallback.checked = false; };
        source.addEventListener('change', updateFallback);
        updateFallback();
    }

    const input = document.getElementById('pdf-sealer-recipients');
    const button = document.getElementById('pdf-sealer-save-recipients');
    let savedRecipients = input.value;
    const testAlarmButton = document.getElementById('pdf-sealer-test-alarm');
    const savedMessage = <?= json_encode($framework->tt('admin_alert_recipients_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const failedMessage = <?= json_encode($framework->tt('admin_alert_recipients_save_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const showMessage = (success, text) => notify(text, success ? 'success' : 'error');
    button.addEventListener('click', () => {
        button.disabled = true;
        input.disabled = true;
        testAlarmButton.disabled = true;

        module.ajax('save_alert_recipients', input.value).then(response => {
            if (response && response.ok) {
                input.value = response.recipients;
                savedRecipients = response.recipients;
                showMessage(true, savedMessage);
            } else {
                showMessage(false, response && response.message ? response.message : failedMessage);
            }
        }).catch(() => showMessage(false, failedMessage)).finally(() => {
            button.disabled = false;
            input.disabled = false;
            testAlarmButton.disabled = false;
        });
    });
    const alarmText = <?= json_encode(array_combine(
        ['confirm', 'unsaved', 'unconfigured', 'sending', 'failed'],
        array_map(static fn(string $key): string => $framework->tt('alarm_test_' . $key),
            ['confirm', 'unsaved', 'unconfigured', 'sending', 'failed'])
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const showAlarmMessage = (style, text) => notify(text, style);
    testAlarmButton.addEventListener('click', () => {
        if (testAlarmButton.disabled) return;
        if (input.value !== savedRecipients) { showAlarmMessage('warning', alarmText.unsaved); return; }
        if (!savedRecipients.trim()) { showAlarmMessage('warning', alarmText.unconfigured); return; }
        if (!window.confirm(alarmText.confirm + '\n\n' + savedRecipients)) return;
        testAlarmButton.disabled = button.disabled = input.disabled = true;
        showAlarmMessage('info', alarmText.sending);
        module.ajax('send_test_alarm', {confirmed: true}).then(response => {
            showAlarmMessage(response && response.ok ? 'success' : 'warning',
                response && response.message ? response.message : alarmText.failed);
        }).catch(() => showAlarmMessage('danger', alarmText.failed)).finally(() => {
            testAlarmButton.disabled = button.disabled = input.disabled = false;
        });
    });
    const diagnosticButton = document.getElementById('pdf-sealer-diagnostic');
    const diagnosticResults = document.getElementById('pdf-sealer-diagnostic-results');
    const diagnosticText = <?= json_encode(array_combine(
        ['running', 'complete', 'incomplete', 'unavailable', 'passed', 'failed', 'skipped'],
        array_map(static fn(string $key): string => $framework->tt('diagnostic_' . $key),
            ['running', 'complete', 'incomplete', 'unavailable', 'passed', 'failed', 'skipped'])
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const snapshotText = <?= json_encode(array_combine(
        ['recent', 'one_day', 'days_ago', 'clock_warning', 'cache_failed', 'cache_unavailable'],
        array_map(static fn(string $key): string => $framework->tt('diagnostic_' . $key),
            ['recent', 'one_day', 'days_ago', 'clock_warning', 'cache_failed', 'cache_unavailable'])
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let snapshot = <?= json_encode($snapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const loadedAt = Date.now();
    const serverEpoch = <?= time() ?>;
    const snapshotCard = document.getElementById('pdf-sealer-diagnostic-snapshot');
    const cacheMessage = document.getElementById('pdf-sealer-diagnostic-cache-message');
    const diagnosticDateTimeFormat = <?= json_encode(\DateTimeRC::get_user_format_full(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const formatDiagnosticTime = date => {
        const [dateFormat, clockFormat] = diagnosticDateTimeFormat.split('_');
        const pad = value => String(value).padStart(2, '0');
        const parts = {Y: String(date.getFullYear()).padStart(4, '0'), M: pad(date.getMonth() + 1), D: pad(date.getDate())};
        const hours = date.getHours();
        const clock = (clockFormat === '12' ? hours % 12 || 12 : pad(hours))
            + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds())
            + (clockFormat === '12' ? (hours >= 12 ? 'pm' : 'am') : '');
        const zone = new Intl.DateTimeFormat(undefined, {timeZoneName: 'short'})
            .formatToParts(date).find(part => part.type === 'timeZoneName').value;
        return dateFormat.replace(/[YMD]/g, part => parts[part]) + ' ' + clock + ' ' + zone;
    };
    const renderSnapshot = () => {
        if (!snapshot) return;
        document.getElementById('pdf-sealer-diagnostic-versions').hidden = snapshot.versions_changed !== true;
        const seconds = serverEpoch + (Date.now() - loadedAt) / 1000 - snapshot.completed_at;
        const days = Math.floor(Math.max(0, seconds) / 86400);
        const future = seconds < -60;
        const tint = !snapshot.passed ? 'is-failed' : future ? '' : days < 1 ? 'is-fresh'
            : days < 7 ? '' : days < 14 ? 'is-aging' : days < 30 ? 'is-stale' : 'is-old';
        snapshotCard.className = 'pdf-sealer-card pdf-sealer-snapshot ' + tint;
        document.getElementById('pdf-sealer-diagnostic-age').textContent = future ? snapshotText.clock_warning
            : days === 0 ? snapshotText.recent : days === 1 ? snapshotText.one_day : snapshotText.days_ago.replace('{days}', days);
        const date = new Date(snapshot.completed_at * 1000);
        const timeElement = document.getElementById('pdf-sealer-diagnostic-time');
        timeElement.dateTime = date.toISOString();
        timeElement.textContent = formatDiagnosticTime(date);
        document.getElementById('pdf-sealer-diagnostic-outcome').textContent = snapshot.passed ? diagnosticText.complete : diagnosticText.incomplete;
        diagnosticResults.querySelectorAll('[data-diagnostic-check]').forEach(cell => {
            const status = snapshot.checks[cell.dataset.diagnosticCheck];
            cell.textContent = diagnosticText[status];
            cell.className = status === 'passed' ? 'text-success' : status === 'failed' ? 'text-danger' : 'text-muted';
        });
        diagnosticResults.hidden = false;
    };
    renderSnapshot();
    document.querySelectorAll('[data-expiry-epoch]').forEach(element => {
        const date = new Date(Number(element.dataset.expiryEpoch) * 1000);
        element.dateTime = date.toISOString();
        element.textContent = formatDiagnosticTime(date);
    });
    setInterval(renderSnapshot, 60000);
    if (<?= $snapshotUnavailable ? 'true' : 'false' ?>) {
        cacheMessage.textContent = snapshotText.cache_unavailable;
        cacheMessage.hidden = false;
    }
    const showDiagnosticMessage = (style, text) => notify(text, style);
    diagnosticButton.addEventListener('click', () => {
        if (diagnosticButton.disabled) return;
        diagnosticButton.disabled = true;
        showDiagnosticMessage('info', diagnosticText.running);
        module.ajax('run_diagnostic', null).then(response => {
            if (!response || response.ok !== true || typeof response.passed !== 'boolean' || !response.checks
                || !Number.isSafeInteger(response.completed_at) || response.completed_at < 1) {
                throw new Error('Diagnostic unavailable');
            }
            diagnosticResults.querySelectorAll('[data-diagnostic-check]').forEach(cell => {
                const status = response.checks[cell.dataset.diagnosticCheck];
                if (!['passed', 'failed', 'skipped'].includes(status)) throw new Error('Invalid diagnostic result');
            });
            snapshot = response;
            renderSnapshot();
            cacheMessage.hidden = true;
            if (response.saved !== true) notify(snapshotText.cache_failed, 'warning');
            showDiagnosticMessage(response.passed ? 'success' : 'warning',
                response.passed ? diagnosticText.complete : diagnosticText.incomplete);
        }).catch(() => showDiagnosticMessage('danger', diagnosticText.unavailable)).finally(() => {
            diagnosticButton.disabled = false;
        });
    });
    const downloadFailedMessage = <?= json_encode($framework->tt('pki_root_download_unavailable'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.querySelectorAll('[data-pki-root-download]').forEach(downloadButton => {
        downloadButton.addEventListener('click', () => {
            downloadButton.disabled = true;
            module.ajax('download_root_certificate', downloadButton.dataset.pkiRootDownload).then(response => {
                if (!response || !response.ok) {
                    notify(response && response.message ? response.message : downloadFailedMessage, 'error');
                    return;
                }
                const binary = atob(response.base64);
                const bytes = new Uint8Array(binary.length);
                for (let index = 0; index < binary.length; index++) {
                    bytes[index] = binary.charCodeAt(index);
                }
                const url = URL.createObjectURL(new Blob([bytes], {type: response.content_type}));
                const link = document.createElement('a');
                link.href = url;
                link.download = response.filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
            }).catch(() => {
                notify(downloadFailedMessage, 'error');
            }).finally(() => {
                downloadButton.disabled = false;
            });
        });
    });
    window.PDFSealerProjectRenewal(module);
    window.PDFSealerProjectRevocation(module);
    window.PDFSealerTsaLifecycle(module);
    window.PDFSealerRootLifecycle(module);
    window.PDFSealerProvidersAdmin(module);
    window.PDFSealerProviderWorkflows(module);
    window.PDFSealerTimestampAdmin(module, <?= json_encode($timestampPolicies, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        <?= json_encode($sourceSummaries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, formatDiagnosticTime,
        <?= json_encode($framework->getUrl('pki-admin.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
})();
</script>
<?php require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php'; ?>
