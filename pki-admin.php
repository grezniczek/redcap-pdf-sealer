<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Diagnostics\DiagnosticSnapshot;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealth;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationLock;
use DE\RUB\PDFSealerExternalModule\Pki\PkiInitializationService;
use DE\RUB\PDFSealerExternalModule\Pki\PrimarySystemSettingReader;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;
use DE\RUB\PDFSealerExternalModule\Timestamp\TimestampSettings;

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
                new CertificateIssuer([$framework, 'createTempFile']),
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
    $timestampSettings = TimestampSettings::fromStored($settings->get('timestamp_mode'), $settings->get('bb_fallback'));
} catch (Throwable) {
    // Show an explicit unknown state; do not silently replace invalid stored settings with defaults.
}
$recipients = $framework->getSystemSetting('admin-alert-recipients');
$recipients = is_array($recipients) ? implode(', ', array_filter($recipients, 'is_string')) : (is_string($recipients) ? $recipients : '');
$snapshot = null;
$snapshotUnavailable = false;
try { $snapshot = (new DiagnosticSnapshot($framework))->load(); }
catch (Throwable) { $snapshotUnavailable = true; }
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
        <?php foreach (['root' => 'fa-certificate', 'tsa' => 'fa-clock', 'diagnostic' => 'fa-stethoscope', 'alarms' => 'fa-bell'] as $tab => $icon): ?>
            <button type="button" class="nav-link<?= $tab === 'root' ? ' active' : '' ?>" role="tab" id="pki-tab-<?= $tab ?>" aria-controls="pki-panel-<?= $tab ?>" aria-selected="<?= $tab === 'root' ? 'true' : 'false' ?>" tabindex="<?= $tab === 'root' ? '0' : '-1' ?>" data-pki-tab="<?= $tab ?>"><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= $escape($framework->tt('pki_tab_' . $tab)) ?></button>
        <?php endforeach; ?>
    </div>
    <section class="pdf-sealer-panel" id="pki-panel-root" role="tabpanel" aria-labelledby="pki-tab-root" tabindex="0">
        <h5><?= $escape($framework->tt('pki_root')) ?></h5>
        <p class="text-muted"><?= $escape($framework->tt('pki_root_help')) ?></p>
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
    <section class="pdf-sealer-panel" id="pki-panel-tsa" role="tabpanel" aria-labelledby="pki-tab-tsa" tabindex="0" hidden>
        <h5><?= $escape($framework->tt('pki_tsa')) ?></h5>
        <p class="text-muted"><?= $escape($framework->tt('pki_tsa_help')) ?></p>
        <?php $renderCertificate('tsa'); ?>
        <hr>
    <h5><?= $escape($framework->tt('timestamp_settings_title')) ?></h5>
    <p><?= $escape($framework->tt('timestamp_settings_scope')) ?></p>
    <?php if ($timestampSettings === null): ?>
        <p id="pdf-sealer-timestamp-warning" class="alert alert-warning"><?= $escape($framework->tt('timestamp_settings_unavailable')) ?></p>
    <?php endif; ?>
    <form id="pdf-sealer-timestamp-form">
        <fieldset id="pdf-sealer-timestamp-fields">
            <div class="mb-3">
                <label for="pdf-sealer-timestamp-mode"><?= $escape($framework->tt('timestamp_mode_label')) ?></label>
                <select id="pdf-sealer-timestamp-mode" class="form-select form-select-sm" required aria-describedby="pdf-sealer-timestamp-help">
                    <option value="" disabled <?= $timestampSettings === null ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                    <option value="internal" <?= $timestampSettings?->mode === 'internal' ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_mode_internal')) ?></option>
                    <option value="none" <?= $timestampSettings?->mode === 'none' ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_mode_none')) ?></option>
                </select>
                <p id="pdf-sealer-timestamp-help" class="text-muted"><?= $escape($framework->tt('timestamp_mode_help')) ?></p>
            </div>
            <div class="mb-3">
                <label for="pdf-sealer-timestamp-fallback"><?= $escape($framework->tt('timestamp_fallback_label')) ?></label>
                <select id="pdf-sealer-timestamp-fallback" class="form-select form-select-sm" required aria-describedby="pdf-sealer-fallback-help">
                    <option value="" disabled <?= $timestampSettings === null ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                    <option value="1" <?= $timestampSettings?->fallback === true ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_fallback_allow')) ?></option>
                    <option value="0" <?= $timestampSettings?->fallback === false ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_fallback_fail')) ?></option>
                </select>
                <p id="pdf-sealer-fallback-help" class="text-muted"><?= $escape($framework->tt('timestamp_fallback_help')) ?></p>
            </div>
            <p><?= $escape($framework->tt('timestamp_failure_help')) ?></p>
            <button type="submit" class="btn btn-primaryrc btn-sm"><?= $escape($framework->tt('timestamp_settings_save')) ?></button>
        </fieldset>
    </form>
    <div id="pdf-sealer-timestamp-message" role="status" hidden></div>
    </section>
    <section class="pdf-sealer-panel" id="pki-panel-diagnostic" role="tabpanel" aria-labelledby="pki-tab-diagnostic" tabindex="0" hidden>
    <h5><?= $escape($framework->tt('diagnostic_title')) ?></h5>
    <p><?= $escape($framework->tt('diagnostic_help')) ?></p>
    <p class="text-muted"><?= $escape($framework->tt('diagnostic_limits')) ?></p>
    <div id="pdf-sealer-diagnostic-snapshot" class="pdf-sealer-card pdf-sealer-snapshot" aria-live="polite">
        <div class="small text-muted"><?= $escape($framework->tt('diagnostic_last_run')) ?></div>
        <strong id="pdf-sealer-diagnostic-age"><?= $escape($framework->tt('diagnostic_never')) ?></strong>
        <time id="pdf-sealer-diagnostic-time" class="d-block small"></time>
        <div id="pdf-sealer-diagnostic-outcome" class="mt-2"></div>
    </div>
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
    const timestampForm = document.getElementById('pdf-sealer-timestamp-form');
    const timestampFields = document.getElementById('pdf-sealer-timestamp-fields');
    const timestampMode = document.getElementById('pdf-sealer-timestamp-mode');
    const timestampFallback = document.getElementById('pdf-sealer-timestamp-fallback');
    const timestampMessage = document.getElementById('pdf-sealer-timestamp-message');
    const timestampSaved = <?= json_encode($framework->tt('timestamp_settings_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const timestampFailed = <?= json_encode($framework->tt('timestamp_settings_save_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const modeSummary = <?= json_encode(['internal' => $framework->tt('timestamp_summary_internal'), 'none' => $framework->tt('timestamp_summary_none')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const showTimestampMessage = (success, text) => {
        timestampMessage.className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
        timestampMessage.textContent = text;
        timestampMessage.hidden = false;
    };
    timestampForm.addEventListener('submit', event => {
        event.preventDefault();
        if (timestampFields.disabled) return;
        const payload = {timestamp_mode: timestampMode.value, bb_fallback: timestampFallback.value === '1'};
        timestampFields.disabled = true;
        timestampMessage.hidden = true;
        module.ajax('save_timestamp_settings', payload).then(response => {
            if (response && response.ok) {
                timestampMode.value = response.timestamp_mode;
                timestampFallback.value = response.bb_fallback ? '1' : '0';
                document.getElementById('pdf-sealer-mode-summary').textContent = modeSummary[response.timestamp_mode];
                const warning = document.getElementById('pdf-sealer-timestamp-warning');
                if (warning) warning.hidden = true;
                showTimestampMessage(true, timestampSaved);
            } else {
                showTimestampMessage(false, response && response.message ? response.message : timestampFailed);
            }
        }).catch(() => showTimestampMessage(false, timestampFailed)).finally(() => {
            timestampFields.disabled = false;
        });
    });
    [timestampMode, timestampFallback].forEach(control => control.addEventListener('change', () => {
        timestampMessage.hidden = true;
    }));
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
    const renderSnapshot = () => {
        if (!snapshot) return;
        const seconds = serverEpoch + (Date.now() - loadedAt) / 1000 - snapshot.completed_at;
        const days = Math.floor(Math.max(0, seconds) / 86400);
        const future = seconds < -60;
        const tint = !snapshot.passed ? 'is-failed' : future ? '' : days < 1 ? 'is-fresh'
            : days < 7 ? '' : days < 14 ? 'is-aging' : days < 30 ? 'is-stale' : 'is-old';
        snapshotCard.className = 'pdf-sealer-card pdf-sealer-snapshot ' + tint;
        document.getElementById('pdf-sealer-diagnostic-age').textContent = future ? snapshotText.clock_warning
            : days === 0 ? snapshotText.recent : days === 1 ? snapshotText.one_day : snapshotText.days_ago.replace('{days}', days);
        const date = new Date(snapshot.completed_at * 1000).toISOString();
        const timeElement = document.getElementById('pdf-sealer-diagnostic-time');
        timeElement.dateTime = date;
        timeElement.textContent = date.replace('T', ' ').replace('.000Z', ' UTC');
        document.getElementById('pdf-sealer-diagnostic-outcome').textContent = snapshot.passed ? diagnosticText.complete : diagnosticText.incomplete;
        diagnosticResults.querySelectorAll('[data-diagnostic-check]').forEach(cell => {
            const status = snapshot.checks[cell.dataset.diagnosticCheck];
            cell.textContent = diagnosticText[status];
            cell.className = status === 'passed' ? 'text-success' : status === 'failed' ? 'text-danger' : 'text-muted';
        });
        diagnosticResults.hidden = false;
    };
    renderSnapshot();
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
})();
</script>
<?php require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php'; ?>
