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
            'SELECT redcap_pid, provider_id, identity_id, pending_provider_id WHERE message = ? AND ISNULL(project_id) AND log_id IN ('
                . implode(',', array_fill(0, count($batch), '?')) . ')', ['project_identity_binding', ...$batch]);
        if ($latest === false) { throw new RuntimeException('Renewal project selector unavailable'); }
        while ($binding = $latest->fetch_assoc()) {
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
    // Keep assignment/transition/renewal choices enabled-only; revocation also includes retained disabled bindings.
    $assignedPids = array_map('strval', $assignedPids);
    $selectorPids = array_values(array_unique([...$enabledPids, ...$revocationPids]));
    if ($selectorPids !== []) {
        $rows = $framework->query('SELECT project_id, app_title FROM redcap_projects WHERE project_id IN ('
            . implode(',', array_fill(0, count($selectorPids), '?')) . ') ORDER BY app_title, project_id', $selectorPids);
        if ($rows === false) { throw new RuntimeException('Project selector unavailable'); }
        while ($row = $rows->fetch_assoc()) {
            if (in_array((int) $row['project_id'], array_map('intval', $enabledPids), true)) {
                if (in_array((string) $row['project_id'], $assignedPids, true)) { $transitionProjects[] = $row; }
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
            $certificates[$role] = ['details' => $details, 'fingerprint' => hash('sha256', $identity->certificateDer)];
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
    </dl>
    <?php
};
require_once APP_PATH_DOCROOT . 'ControlCenter/header.php';
$framework->initializeJavascriptModuleObject();
foreach (['external_tsa_failed', 'external_tsa_passed', 'external_tsa_test_failed', 'external_tsa_testing', 'timestamp_order_invalid', 'diagnostic_never', 'pki_fingerprint'] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
$framework->tt_transferToJavascriptModuleObject('provider_assigned');
$framework->tt_transferToJavascriptModuleObject('provider_retirement_counts');
foreach (['revocation_failed', 'revocation_confirm_prompt', 'revocation_saved', 'revocation_crl_published', 'revocation_crl_pending', 'revocation_replaced', 'revocation_signer_changed', 'revocation_replacement_pending', 'revocation_already', 'renewal_failed', 'renewal_saved', 'renewal_issuer_expiry'] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
foreach (['transition_current', 'transition_target', 'transition_saved_pending', 'transition_saved_activated', 'transition_saved_canceled'] as $key) { $framework->tt_transferToJavascriptModuleObject($key); }
?>
<link rel="stylesheet" href="<?= $escape($framework->getUrl('assets/admin.css')) ?>">
<div class="pdf-sealer-admin">
    <p class="pdf-sealer-brand text-muted"><em><?= $escape($framework->tt('pki_brand')) ?></em></p>
    <h4 class="mb-2"><i class="fas fa-file-signature" aria-hidden="true"></i> <?= $escape($framework->tt('pki_page_title')) ?></h4>
    <p><?= $escape($framework->tt('pki_page_intro')) ?></p>
    <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success" role="status"><?= $escape($framework->tt('pki_init_success')) ?></div><?php endif; ?>
    <div class="pdf-sealer-summary">
        <div class="pdf-sealer-card pdf-sealer-health-<?= $escape(strtolower($report->status->value)) ?>">
            <div class="small text-muted"><?= $escape($framework->tt('pki_status')) ?></div>
            <strong><?= $escape($framework->tt('pki_state_' . strtolower($report->status->value))) ?></strong>
        </div>
        <div class="pdf-sealer-card"><div class="small text-muted"><?= $escape($framework->tt('pki_organization')) ?></div><strong><?= $escape(is_string($organization) && $organization !== '' ? $organization : $framework->tt('pki_not_configured')) ?></strong></div>
        <div class="pdf-sealer-card"><div class="small text-muted"><?= $escape($framework->tt('timestamp_mode_label')) ?></div><strong id="pdf-sealer-mode-summary"><?= $escape($timestampSettings === null ? $framework->tt('pki_not_configured') : $framework->tt('timestamp_summary_' . $timestampSettings->mode)) ?></strong></div>
    </div>
    <p class="pdf-sealer-trust-link"><a href="<?= $escape($module::publicTrustUrl()) ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt" aria-hidden="true"></i> <?= $escape($framework->tt('pki_public_trust_page')) ?></a></p>
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
            <div id="pki-root-download-message" role="status" hidden></div>
        <?php endif; ?>
    <?php endif; ?>
    </section>
    <section class="pdf-sealer-panel" id="pki-panel-providers" role="tabpanel" aria-labelledby="pki-tab-providers" tabindex="0" hidden>
        <h5><?= $escape($framework->tt('pki_tab_providers')) ?></h5>
        <p><?= $escape($framework->tt('provider_intro')) ?></p>
        <?php if ($providersUnavailable): ?>
            <p class="alert alert-warning"><?= $escape($framework->tt('provider_unavailable')) ?></p>
        <?php else: ?>
            <form id="pdf-sealer-assignment-policy" class="mb-3">
                <fieldset>
                    <label><input type="checkbox" id="assignment-required" <?= $assignmentRequired ? 'checked' : '' ?> aria-describedby="assignment-policy-help">
                        <?= $escape($framework->tt('assignment_policy_label')) ?></label>
                    <p id="assignment-policy-help" class="small text-muted"><?= $escape($framework->tt('assignment_policy_help')) ?></p>
                    <p class="alert alert-warning"><?= $escape($framework->tt('assignment_policy_delivery')) ?></p>
                    <button class="btn btn-primaryrc btn-sm" type="submit"><?= $escape($framework->tt('assignment_policy_save')) ?></button>
                </fieldset>
                <p class="alert mt-3" role="status" hidden></p>
            </form>
            <hr>
            <?php foreach ($providerCatalog as $provider): ?>
                <div class="pdf-sealer-card mb-3">
                    <h6><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?>
                        <span class="badge <?= $provider['retired'] ? 'bg-secondary' : 'bg-success' ?>"><?= $escape($framework->tt($provider['retired'] ? 'provider_retired' : 'provider_active')) ?></span></h6>
                    <p class="small"><code><?= $escape($provider['id']) ?></code><br>
                        <?= $escape($framework->tt('timestamp_mode_label')) ?>: <?= $escape($sourceChoices[$provider['timestamp_source'] ?? 'none'] ?? $framework->tt('external_tsa_unavailable')) ?><br>
                        <?php foreach ($provider['timestamp_alternatives'] as $index => $alternativeId): ?>
                        <?= $escape($framework->tt('timestamp_alternative_' . ($index + 1))) ?>: <?= $escape($sourceChoices[$alternativeId] ?? $framework->tt('external_tsa_unavailable')) ?><br>
                        <?php endforeach; ?>
                        <?php if ($provider['timestamp_source'] !== null): ?><?= $escape($framework->tt($provider['bb_fallback'] ? 'timestamp_fallback_allow' : 'timestamp_fallback_fail')) ?><?php endif; ?></p>
                    <?php foreach ($providerCertificates as $cert): if ($cert['provider_id'] !== $provider['id']) { continue; } ?>
                        <dl class="pdf-sealer-certificate">
                            <dt><?= $escape($framework->tt($cert['trust_anchor'] ? 'provider_anchor' : 'provider_intermediate')) ?></dt><dd><?= $module::certificateSubjectHtml($cert['subject']) ?></dd>
                            <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code class="pdf-sealer-fingerprint"><?= $escape($cert['fingerprint']) ?></code></dd>
                            <dt><?= $escape($framework->tt('pki_valid_until')) ?></dt><dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $cert['valid_until'])) ?></dd>
                        </dl>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-provider-lifecycle="<?= $escape($provider['id']) ?>"><?= $escape($framework->tt($provider['retired'] ? 'provider_reactivate' : 'provider_retire')) ?></button>
                    <div class="provider-lifecycle-review mt-3" hidden>
                        <p class="provider-lifecycle-summary"></p>
                        <p class="provider-lifecycle-explanation"></p>
                        <div style="max-height:18rem;overflow:auto">
                            <table class="table table-sm">
                                <thead><tr><th><?= $escape($framework->tt('provider_pid')) ?></th><th><?= $escape($framework->tt('provider_active_signer')) ?></th><th><?= $escape($framework->tt('provider_pending_enrollment')) ?></th><th><?= $escape($framework->tt('transition_pending_label')) ?></th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <label class="provider-lifecycle-gate mb-3" hidden><input type="checkbox"> <?= $escape($framework->tt('provider_retirement_gate')) ?></label>
                        <div class="pdf-sealer-actions">
                            <button type="button" class="btn btn-primaryrc btn-sm provider-lifecycle-confirm"></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm provider-lifecycle-cancel"><?= $escape($framework->tt('provider_lifecycle_cancel')) ?></button>
                        </div>
                    </div>
                    <p class="provider-lifecycle-message alert alert-danger mt-3" role="status" hidden></p>
                </div>
            <?php endforeach; ?>
            <h5><?= $escape($framework->tt('provider_register')) ?></h5>
            <p class="small text-muted"><?= $escape($framework->tt('provider_upload_help')) ?></p>
            <form id="pdf-sealer-provider-register">
                <fieldset>
                    <label for="provider-name"><?= $escape($framework->tt('provider_name')) ?></label>
                    <input class="form-control form-control-sm mb-3" id="provider-name" maxlength="128" required>
                    <label for="provider-chain"><?= $escape($framework->tt('provider_chain')) ?></label>
                    <input class="form-control form-control-sm mb-3" id="provider-chain" type="file" accept=".pem,.crt,.cer" required>
                    <label for="provider-source"><?= $escape($framework->tt('timestamp_mode_label')) ?></label>
                    <select class="form-select form-select-sm mb-3" id="provider-source" required>
                        <option value="" selected disabled><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                        <?php foreach ($sourceChoices as $id => $name): ?><option value="<?= $escape($id) ?>"><?= $escape($name) ?></option><?php endforeach; ?>
                    </select>
                    <label class="mb-3"><input type="checkbox" id="provider-fallback" disabled> <?= $escape($framework->tt('timestamp_fallback_allow')) ?></label><br>
                    <button class="btn btn-primaryrc btn-sm" type="submit"><?= $escape($framework->tt('provider_register')) ?></button>
                </fieldset>
                <p class="alert mt-3" role="status" hidden></p>
            </form>
            <hr>
            <h5><?= $escape($framework->tt('provider_assign')) ?></h5>
            <p class="small text-muted"><?= $escape($framework->tt('provider_assign_help')) ?></p>
            <form id="pdf-sealer-provider-assign">
                <?php if ($assignmentProjectsUnavailable): ?><p class="alert alert-warning"><?= $escape($framework->tt('provider_projects_unavailable')) ?></p><?php endif; ?>
                <fieldset <?= $assignableProviders === [] || $assignmentProjectsUnavailable || $assignmentProjects === [] ? 'disabled' : '' ?>>
                    <label for="provider-pid"><?= $escape($framework->tt('provider_pid')) ?></label>
                    <div class="mb-3">
                        <select class="form-select form-select-sm" id="provider-pid" required>
                            <option value="" selected><?= $escape($framework->tt($assignmentProjects === [] ? 'provider_no_unassigned_projects' : 'provider_choose_project')) ?></option>
                            <?php foreach ($assignmentProjects as $project): ?>
                                <option value="<?= $escape($project['project_id']) ?>"><?= $escape('(' . $project['project_id'] . ') ' . $project['app_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label for="provider-selection"><?= $escape($framework->tt('provider_label')) ?></label>
                    <select class="form-select form-select-sm mb-3" id="provider-selection" required>
                        <option value="" selected disabled><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                        <?php foreach ($assignableProviders as $provider): ?><option value="<?= $escape($provider['id']) ?>"><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-primaryrc btn-sm" type="submit"><?= $escape($framework->tt('provider_assign')) ?></button>
                </fieldset>
                <p class="alert mt-3" role="status" hidden></p>
            </form>
            <hr>
            <h5><?= $escape($framework->tt('transition_title')) ?></h5>
            <p class="small text-muted"><?= $escape($framework->tt('transition_intro')) ?></p>
            <form id="pdf-sealer-transition">
                <fieldset <?= $assignmentProjectsUnavailable || $transitionProjects === [] ? 'disabled' : '' ?>>
                    <label for="transition-pid"><?= $escape($framework->tt('provider_pid')) ?></label>
                    <div class="mb-3"><select class="form-select form-select-sm" id="transition-pid" required>
                        <option value="" selected><?= $escape($framework->tt('transition_choose_project')) ?></option>
                        <?php foreach ($transitionProjects as $project): ?>
                            <option value="<?= $escape($project['project_id']) ?>"><?= $escape('(' . $project['project_id'] . ') ' . $project['app_title']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><?= $escape($framework->tt('transition_review')) ?></button>
                    <div id="transition-review" class="mt-3" hidden>
                        <p id="transition-current"></p>
                        <p id="transition-pending"></p>
                        <p id="transition-csr" class="alert alert-warning" hidden><?= $escape($framework->tt('transition_cancel_csr_first')) ?></p>
                        <div id="transition-target-choice">
                            <label for="transition-provider"><?= $escape($framework->tt('transition_target_label')) ?></label>
                            <select class="form-select form-select-sm mb-3" id="transition-provider">
                                <option value="" selected><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                                <?php foreach ($assignableProviders as $provider): ?><option value="<?= $escape($provider['id']) ?>"><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <p id="transition-action-help"></p>
                        <button type="button" class="btn btn-warning btn-sm" id="transition-confirm"></button>
                    </div>
                </fieldset>
                <p class="alert mt-3" role="status" hidden></p>
            </form>
            <hr>
            <?php require __DIR__ . '/views/project-renewal.php'; ?>
            <hr>
            <?php require __DIR__ . '/views/project-revocation.php'; ?>
        <?php endif; ?>
    </section>
    <section class="pdf-sealer-panel" id="pki-panel-tsa" role="tabpanel" aria-labelledby="pki-tab-tsa" tabindex="0" hidden>
        <h5><?= $escape($framework->tt('pki_tsa')) ?></h5>
        <p class="text-muted"><?= $escape($framework->tt('pki_tsa_help')) ?></p>
        <?php $renderCertificate('tsa'); ?>
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
    <div id="pdf-sealer-diagnostic-message" role="status" hidden></div>
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
    <div id="pdf-sealer-recipient-message" role="status" hidden></div>
    <div class="mb-3">
        <label for="pdf-sealer-recipients"><?= $escape($framework->tt('admin_alert_recipients')) ?></label>
        <textarea id="pdf-sealer-recipients" class="form-control form-control-sm" rows="3" maxlength="4096"><?= $escape($recipients) ?></textarea>
    </div>
    <button id="pdf-sealer-save-recipients" type="button" class="btn btn-primaryrc btn-sm"><?= $escape($framework->tt('admin_alert_recipients_save')) ?></button>

    <hr>
    <h5><?= $escape($framework->tt('alarm_test_title')) ?></h5>
    <p class="text-muted"><?= $escape($framework->tt('alarm_test_help')) ?></p>
    <button id="pdf-sealer-test-alarm" type="button" class="btn btn-outline-secondary btn-sm"><i class="fas fa-paper-plane" aria-hidden="true"></i> <?= $escape($framework->tt('alarm_test_button')) ?></button>
    <div id="pdf-sealer-test-alarm-message" role="status" hidden></div>
    </section>
</div>
<script src="<?= $escape($framework->getUrl('assets/timestamp-admin.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/project-renewal.js')) ?>"></script>
<script src="<?= $escape($framework->getUrl('assets/project-revocation.js')) ?>"></script>
<script>
(() => {
    const module = <?= $framework->getJavascriptModuleObjectName() ?>;
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
    document.querySelectorAll('[data-provider-lifecycle]').forEach(button => {
        const card = button.parentElement;
        const review = card.querySelector('.provider-lifecycle-review');
        const message = card.querySelector('.provider-lifecycle-message');
        const confirm = card.querySelector('.provider-lifecycle-confirm');
        const cancel = card.querySelector('.provider-lifecycle-cancel');
        const gateLabel = card.querySelector('.provider-lifecycle-gate');
        const gate = gateLabel.querySelector('input');
        const failed = <?= json_encode($framework->tt('provider_lifecycle_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let preview = null;
        const needsGate = () => preview && !preview.retired && preview.is_default && !preview.assignment_required;
        gate.addEventListener('change', () => { confirm.disabled = needsGate() && !gate.checked; });
        cancel.addEventListener('click', () => { review.hidden = true; preview = null; button.disabled = false; });
        button.addEventListener('click', async () => {
            message.hidden = true; review.hidden = true; button.disabled = true;
            try {
                const response = await module.ajax('preview_ca_retirement', {provider: button.dataset.providerLifecycle});
                if (!response?.ok) throw new Error('Preview failed');
                preview = response;
                const projects = response.projects;
                card.querySelector('.provider-lifecycle-summary').textContent = module.tt('provider_retirement_counts',
                    projects.length, projects.filter(p => p.identity_id !== null).length, projects.filter(p => p.enrollment_id !== null).length);
                card.querySelector('.provider-lifecycle-explanation').textContent = response.retired
                    ? <?= json_encode($framework->tt('provider_reactivation_help'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                    : <?= json_encode($framework->tt('provider_retirement_help'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const body = review.querySelector('tbody'); body.replaceChildren();
                projects.forEach(project => {
                    const row = document.createElement('tr');
                    [project.pid, project.identity_id !== null ? '✓' : '—', project.enrollment_id !== null ? '✓' : '—', project.transition_id ? '✓' : '—'].forEach(value => {
                        const cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell);
                    });
                    body.appendChild(row);
                });
                confirm.classList.toggle('btn-warning', !response.retired);
                confirm.classList.toggle('btn-primaryrc', response.retired);
                confirm.textContent = response.retired
                    ? <?= json_encode($framework->tt('provider_reactivate'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                    : <?= json_encode($framework->tt('provider_retire'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                gate.checked = false; gateLabel.hidden = !needsGate(); confirm.disabled = needsGate();
                review.hidden = false; (needsGate() ? gate : confirm).focus();
            } catch (error) { message.textContent = failed; message.hidden = false; button.disabled = false; }
        });
        confirm.addEventListener('click', async () => {
            if (!preview || (needsGate() && !gate.checked)) return;
            confirm.disabled = true; cancel.disabled = true; gate.disabled = true; message.hidden = true;
            try {
                const response = await module.ajax('set_ca_retirement', {provider: button.dataset.providerLifecycle,
                    retired: !preview.retired, review_hash: preview.review_hash, enable_assignment_gate: Boolean(needsGate() && gate.checked)});
                if (!response?.ok) throw new Error('State changed or save failed');
                location.hash = 'providers'; location.reload();
            } catch (error) {
                message.textContent = failed; message.hidden = false; review.hidden = true; preview = null; button.disabled = false;
            } finally { cancel.disabled = false; gate.disabled = false; }
        });
    });

    const assignmentPolicyForm = document.getElementById('pdf-sealer-assignment-policy');
    assignmentPolicyForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const fields = assignmentPolicyForm.querySelector('fieldset');
        const checkbox = document.getElementById('assignment-required');
        const message = assignmentPolicyForm.querySelector('[role="status"]');
        const failed = <?= json_encode($framework->tt('assignment_policy_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        fields.disabled = true;
        message.hidden = true;
        try {
            const response = await module.ajax('save_assignment_policy', {required: checkbox.checked});
            if (!response?.ok) throw new Error('Save failed');
            checkbox.checked = response.required;
            message.className = 'alert alert-success mt-3';
            message.textContent = <?= json_encode($framework->tt('assignment_policy_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        } catch (error) {
            message.className = 'alert alert-danger mt-3';
            message.textContent = failed;
        } finally { fields.disabled = false; message.hidden = false; }
    });
    const transitionForm = document.getElementById('pdf-sealer-transition');
    if (transitionForm) {
        const fields = transitionForm.querySelector('fieldset');
        const project = $('#transition-pid');
        project.prop('disabled', fields.disabled).select2({width: '100%', minimumResultsForSearch: 0});
        const target = document.getElementById('transition-provider');
        const review = document.getElementById('transition-review');
        const confirm = document.getElementById('transition-confirm');
        const message = transitionForm.querySelector('[role="status"]');
        const catalog = <?= json_encode(array_column(array_map(static fn(array $p): array => ['id' => $p['id'], 'name' => $p['name'] ?? $framework->tt('provider_builtin'), 'kind' => $p['kind']], $providerCatalog), null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const failed = <?= json_encode($framework->tt('transition_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let preview = null;
        const updateAction = () => {
            const cancel = Boolean(preview?.transition_id);
            const internal = catalog[target.value]?.kind === 'internal';
            confirm.textContent = cancel ? <?= json_encode($framework->tt('transition_cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                : internal ? <?= json_encode($framework->tt('transition_activate_builtin'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                : <?= json_encode($framework->tt('transition_prepare'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            document.getElementById('transition-action-help').textContent = cancel
                ? <?= json_encode($framework->tt('transition_cancel_help'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                : internal ? <?= json_encode($framework->tt('transition_builtin_help'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                : <?= json_encode($framework->tt('transition_external_help'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            confirm.disabled = !preview || (!cancel && (Boolean(preview.enrollment_id) || !target.value || target.value === preview.provider_id));
        };
        target.addEventListener('change', updateAction);
        project.on('change', () => { preview = null; review.hidden = true; message.hidden = true; });
        transitionForm.addEventListener('submit', async event => {
            event.preventDefault(); fields.disabled = true; project.prop('disabled', true); review.hidden = true; message.hidden = true;
            try {
                const response = await module.ajax('preview_provider_transition', {pid: Number(project.val())});
                if (!response?.ok) throw new Error('Preview failed');
                preview = response; target.value = '';
                Array.from(target.options).forEach(option => { option.disabled = option.value === response.provider_id; });
                document.getElementById('transition-current').textContent = module.tt('transition_current', catalog[response.provider_id]?.name || response.provider_id)
                    + ' ' + (response.identity_id ? <?= json_encode($framework->tt('transition_has_signer'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : <?= json_encode($framework->tt('transition_no_signer'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
                document.getElementById('transition-pending').textContent = response.pending_provider_id
                    ? module.tt('transition_target', catalog[response.pending_provider_id]?.name || response.pending_provider_id) : '';
                document.getElementById('transition-target-choice').hidden = Boolean(response.transition_id);
                document.getElementById('transition-csr').hidden = !response.enrollment_id || Boolean(response.transition_id);
                review.hidden = false; updateAction();
            } catch (error) { preview = null; message.className = 'alert alert-danger mt-3'; message.textContent = failed; message.hidden = false; }
            finally { fields.disabled = false; project.prop('disabled', false); }
        });
        confirm.addEventListener('click', async () => {
            if (!preview) return;
            const cancel = Boolean(preview.transition_id);
            const pid = preview.pid;
            const provider = cancel ? preview.pending_provider_id : target.value;
            const projectName = project[0].selectedOptions[0].textContent;
            fields.disabled = true; project.prop('disabled', true); message.hidden = true;
            try {
                const payload = {pid, review_hash: preview.review_hash};
                if (!cancel) payload.provider = provider;
                const response = await module.ajax(cancel ? 'cancel_provider_transition' : 'start_provider_transition', payload);
                if (!response?.ok) throw new Error('Transition failed');
                transitionForm.reset(); project.trigger('change'); preview = null; review.hidden = true;
                message.className = 'alert alert-success mt-3';
                message.textContent = module.tt('transition_saved_' + response.state, catalog[provider]?.name || provider, projectName);
                message.hidden = false;
            } catch (error) {
                preview = null; review.hidden = true;
                message.className = 'alert alert-danger mt-3'; message.textContent = failed; message.hidden = false;
            } finally { fields.disabled = false; project.prop('disabled', false); }
        });
    }

    const assignmentProject = $('#provider-pid');
    $(function () {
        assignmentProject.prop('disabled', assignmentProject.closest('fieldset').prop('disabled'));
        assignmentProject.select2({width: '100%', minimumResultsForSearch: 0});
    });
    ['register', 'assign'].forEach(action => {
        const form = document.getElementById('pdf-sealer-provider-' + action);
        if (!form) return;
        if (action === 'register') {
            const source = document.getElementById('provider-source');
            const fallback = document.getElementById('provider-fallback');
            const updateFallback = () => { fallback.disabled = source.value === 'none' || source.value === ''; if (fallback.disabled) fallback.checked = false; };
            source.addEventListener('change', updateFallback);
            updateFallback();
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const fields = form.querySelector('fieldset');
            const message = form.querySelector('[role="status"]');
            fields.disabled = true;
            if (action === 'assign') assignmentProject.prop('disabled', true);
            message.hidden = true;
            try {
                let payload;
                let assignedProjectName, assignedProviderName;
                if (action === 'register') {
                    const file = document.getElementById('provider-chain').files[0];
                    if (!file || file.size > 131072) throw new Error('Invalid upload');
                    payload = {name: document.getElementById('provider-name').value, pem: await file.text(),
                        source: document.getElementById('provider-source').value, fallback: document.getElementById('provider-fallback').checked};
                } else {
                    const projectSelect = document.getElementById('provider-pid');
                    const providerSelect = document.getElementById('provider-selection');
                    payload = {pid: Number(projectSelect.value), provider: providerSelect.value};
                    assignedProjectName = projectSelect.selectedOptions[0].textContent;
                    assignedProviderName = providerSelect.selectedOptions[0].textContent;
                }
                const response = await module.ajax(action + '_ca_provider', payload);
                message.className = 'alert mt-3 ' + (response?.ok ? 'alert-success' : 'alert-danger');
                message.textContent = response?.ok ? <?= json_encode($framework->tt('provider_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> : response?.message;
                if (response?.ok && action === 'assign') {
                    message.textContent = module.tt('provider_assigned', assignedProviderName, assignedProjectName);
                    const transitionProject = document.getElementById('transition-pid');
                    if (transitionProject && !Array.from(transitionProject.options).some(option => option.value === String(payload.pid))) {
                        transitionProject.add(new Option(assignedProjectName, String(payload.pid)));
                        transitionProject.closest('fieldset').disabled = false;
                        $(transitionProject).prop('disabled', false);
                    }
                    form.reset();
                    assignmentProject.find('option').filter(function () { return this.value === String(payload.pid); }).remove();
                    if (assignmentProject[0].options.length === 1) {
                        assignmentProject[0].options[0].textContent = <?= json_encode($framework->tt('provider_no_unassigned_projects'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    }
                    assignmentProject.trigger('change');
                }
                message.hidden = false;
                if (response?.ok && action === 'register') { location.hash = 'providers'; location.reload(); }
            } catch (error) {
                message.className = 'alert alert-danger mt-3';
                message.textContent = <?= json_encode($framework->tt('provider_request_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                message.hidden = false;
            } finally {
                fields.disabled = action === 'assign' && assignmentProject[0].options.length === 1;
                if (action === 'assign') assignmentProject.prop('disabled', fields.disabled);
            }
        });
    });
    const input = document.getElementById('pdf-sealer-recipients');
    const button = document.getElementById('pdf-sealer-save-recipients');
    const message = document.getElementById('pdf-sealer-recipient-message');
    let savedRecipients = input.value;
    const testAlarmButton = document.getElementById('pdf-sealer-test-alarm');
    const savedMessage = <?= json_encode($framework->tt('admin_alert_recipients_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const failedMessage = <?= json_encode($framework->tt('admin_alert_recipients_save_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const showMessage = (success, text) => {
        message.className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
        message.textContent = text;
        message.hidden = false;
    };
    button.addEventListener('click', () => {
        button.disabled = true;
        input.disabled = true;
        testAlarmButton.disabled = true;
        message.hidden = true;
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
    const alarmMessage = document.getElementById('pdf-sealer-test-alarm-message');
    const alarmText = <?= json_encode(array_combine(
        ['confirm', 'unsaved', 'unconfigured', 'sending', 'failed'],
        array_map(static fn(string $key): string => $framework->tt('alarm_test_' . $key),
            ['confirm', 'unsaved', 'unconfigured', 'sending', 'failed'])
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const showAlarmMessage = (style, text) => {
        alarmMessage.className = 'alert alert-' + style;
        alarmMessage.textContent = text;
        alarmMessage.hidden = false;
    };
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
    const diagnosticMessage = document.getElementById('pdf-sealer-diagnostic-message');
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
    const showDiagnosticMessage = (style, text) => {
        diagnosticMessage.className = 'alert alert-' + style;
        diagnosticMessage.textContent = text;
        diagnosticMessage.hidden = false;
    };
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
            cacheMessage.textContent = response.saved === true ? '' : snapshotText.cache_failed;
            cacheMessage.hidden = response.saved === true;
            showDiagnosticMessage(response.passed ? 'success' : 'warning',
                response.passed ? diagnosticText.complete : diagnosticText.incomplete);
        }).catch(() => showDiagnosticMessage('danger', diagnosticText.unavailable)).finally(() => {
            diagnosticButton.disabled = false;
        });
    });
    const downloadMessage = document.getElementById('pki-root-download-message');
    const downloadFailedMessage = <?= json_encode($framework->tt('pki_root_download_unavailable'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.querySelectorAll('[data-pki-root-download]').forEach(downloadButton => {
        downloadButton.addEventListener('click', () => {
            downloadButton.disabled = true;
            downloadMessage.hidden = true;
            module.ajax('download_root_certificate', downloadButton.dataset.pkiRootDownload).then(response => {
                if (!response || !response.ok) {
                    downloadMessage.className = 'alert alert-danger';
                    downloadMessage.textContent = response && response.message ? response.message : downloadFailedMessage;
                    downloadMessage.hidden = false;
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
                downloadMessage.className = 'alert alert-danger';
                downloadMessage.textContent = downloadFailedMessage;
                downloadMessage.hidden = false;
            }).finally(() => {
                downloadButton.disabled = false;
            });
        });
    });
    window.PDFSealerProjectRenewal(module);
    window.PDFSealerProjectRevocation(module);
    window.PDFSealerTimestampAdmin(module, <?= json_encode($timestampPolicies, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        <?= json_encode($sourceSummaries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, formatDiagnosticTime,
        <?= json_encode($framework->getUrl('pki-admin.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
})();
</script>
<?php require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php'; ?>
