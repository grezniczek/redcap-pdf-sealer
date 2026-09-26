<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
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
require_once APP_PATH_DOCROOT . 'ControlCenter/header.php';
$framework->initializeJavascriptModuleObject();
?>
<div style="max-width: 820px; margin: 24px auto;">
    <h2><?= $escape($framework->tt('pki_page_title')) ?></h2>
    <?php if ($error !== null): ?><div class="alert alert-danger"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $escape($framework->tt('pki_init_success')) ?></div><?php endif; ?>
    <p><strong><?= $escape($framework->tt('pki_status')) ?>:</strong> <?= $escape($report->status->value) ?></p>
    <?php if ($report->status === PkiHealth::Uninitialized): ?>
        <form method="post">
            <input type="hidden" name="redcap_external_module_csrf_token" value="<?= $escape($framework->getCSRFToken()) ?>">
            <input type="hidden" name="action" value="initialize">
            <div class="form-group">
                <label for="pdf-sealer-organization"><?= $escape($framework->tt('pki_organization')) ?></label>
                <input id="pdf-sealer-organization" class="form-control" type="text" name="organization" maxlength="128" required value="<?= $escape(is_string($organization) ? $organization : '') ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?= $escape($framework->tt('pki_initialize')) ?></button>
        </form>
    <?php elseif ($report->status === PkiHealth::Broken): ?>
        <p class="alert alert-danger"><?= $escape($framework->tt('pki_broken')) ?></p>
    <?php else: ?>
        <p><strong><?= $escape($framework->tt('pki_organization')) ?>:</strong> <?= $escape($organization) ?></p>
        <?php foreach (['root', 'tsa'] as $role):
            try {
                $id = $identities->activeId($role);
                $identity = $id === null ? null : $identities->find($id);
                $details = $identity === null ? false : openssl_x509_parse(Certificate::derToPem($identity->certificateDer));
            } catch (Throwable $e) {
                $details = false;
            }
            if (!is_array($details)) { continue; }
        ?>
            <h3><?= $escape($framework->tt($role === 'root' ? 'pki_root' : 'pki_tsa')) ?></h3>
            <dl>
                <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd><?= $escape($details['name'] ?? '') ?></dd>
                <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code><?= $escape(hash('sha256', $identity->certificateDer)) ?></code></dd>
                <dt><?= $escape($framework->tt('pki_valid_until')) ?></dt><dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $details['validTo_time_t'] ?? 0)) ?></dd>
            </dl>
            <?php if ($role === 'root'): ?>
                <p>
                    <button type="button" class="btn btn-default" data-pki-root-download="pem"><?= $escape($framework->tt('pki_download_root_pem')) ?></button>
                    <button type="button" class="btn btn-default" data-pki-root-download="der"><?= $escape($framework->tt('pki_download_root_der')) ?></button>
                </p>
                <p class="text-muted"><?= $escape($framework->tt('pki_download_root_help')) ?></p>
                <div id="pki-root-download-message" role="status" hidden></div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="<?= $escape($module::publicTrustUrl()) ?>"><?= $escape($framework->tt('pki_public_trust_page')) ?></a></p>
    <h3><?= $escape($framework->tt('timestamp_settings_title')) ?></h3>
    <p><?= $escape($framework->tt('timestamp_settings_scope')) ?></p>
    <?php if ($timestampSettings === null): ?>
        <p id="pdf-sealer-timestamp-warning" class="alert alert-warning"><?= $escape($framework->tt('timestamp_settings_unavailable')) ?></p>
    <?php endif; ?>
    <form id="pdf-sealer-timestamp-form">
        <fieldset id="pdf-sealer-timestamp-fields">
            <div class="form-group">
                <label for="pdf-sealer-timestamp-mode"><?= $escape($framework->tt('timestamp_mode_label')) ?></label>
                <select id="pdf-sealer-timestamp-mode" class="form-control" required aria-describedby="pdf-sealer-timestamp-help">
                    <option value="" disabled <?= $timestampSettings === null ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                    <option value="internal" <?= $timestampSettings?->mode === 'internal' ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_mode_internal')) ?></option>
                    <option value="none" <?= $timestampSettings?->mode === 'none' ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_mode_none')) ?></option>
                </select>
                <p id="pdf-sealer-timestamp-help" class="text-muted"><?= $escape($framework->tt('timestamp_mode_help')) ?></p>
            </div>
            <div class="form-group">
                <label for="pdf-sealer-timestamp-fallback"><?= $escape($framework->tt('timestamp_fallback_label')) ?></label>
                <select id="pdf-sealer-timestamp-fallback" class="form-control" required aria-describedby="pdf-sealer-fallback-help">
                    <option value="" disabled <?= $timestampSettings === null ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                    <option value="1" <?= $timestampSettings?->fallback === true ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_fallback_allow')) ?></option>
                    <option value="0" <?= $timestampSettings?->fallback === false ? 'selected' : '' ?>><?= $escape($framework->tt('timestamp_fallback_fail')) ?></option>
                </select>
                <p id="pdf-sealer-fallback-help" class="text-muted"><?= $escape($framework->tt('timestamp_fallback_help')) ?></p>
            </div>
            <p><?= $escape($framework->tt('timestamp_failure_help')) ?></p>
            <button type="submit" class="btn btn-primary"><?= $escape($framework->tt('timestamp_settings_save')) ?></button>
        </fieldset>
    </form>
    <div id="pdf-sealer-timestamp-message" role="status" hidden></div>
    <h3><?= $escape($framework->tt('diagnostic_title')) ?></h3>
    <p><?= $escape($framework->tt('diagnostic_help')) ?></p>
    <p class="text-muted"><?= $escape($framework->tt('diagnostic_limits')) ?></p>
    <button id="pdf-sealer-diagnostic" type="button" class="btn btn-default"><?= $escape($framework->tt('diagnostic_run')) ?></button>
    <div id="pdf-sealer-diagnostic-message" role="status" hidden></div>
    <table id="pdf-sealer-diagnostic-results" class="table table-sm" hidden>
        <thead><tr><th scope="col"><?= $escape($framework->tt('diagnostic_check')) ?></th><th scope="col"><?= $escape($framework->tt('diagnostic_result')) ?></th></tr></thead>
        <tbody>
        <?php foreach (['encryption', 'root', 'tsa', 'signer', 'bb', 'timestamp', 'bt'] as $check): ?>
            <tr><th scope="row"><?= $escape($framework->tt('diagnostic_' . $check)) ?></th><td data-diagnostic-check="<?= $escape($check) ?>"></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <h3><?= $escape($framework->tt('admin_alert_recipients')) ?></h3>
    <p><?= $escape($framework->tt('admin_alert_recipients_help')) ?></p>
    <div id="pdf-sealer-recipient-message" role="status" hidden></div>
    <div class="form-group">
        <label for="pdf-sealer-recipients"><?= $escape($framework->tt('admin_alert_recipients')) ?></label>
        <textarea id="pdf-sealer-recipients" class="form-control" rows="3" maxlength="4096"><?= $escape($recipients) ?></textarea>
    </div>
    <button id="pdf-sealer-save-recipients" type="button" class="btn btn-primary"><?= $escape($framework->tt('admin_alert_recipients_save')) ?></button>
</div>
<script>
(() => {
    const module = <?= $framework->getJavascriptModuleObjectName() ?>;
    const input = document.getElementById('pdf-sealer-recipients');
    const button = document.getElementById('pdf-sealer-save-recipients');
    const message = document.getElementById('pdf-sealer-recipient-message');
    const savedMessage = <?= json_encode($framework->tt('admin_alert_recipients_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const failedMessage = <?= json_encode($framework->tt('admin_alert_recipients_save_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const showMessage = (success, text) => {
        message.className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
        message.textContent = text;
        message.hidden = false;
    };
    button.addEventListener('click', () => {
        button.disabled = true;
        message.hidden = true;
        module.ajax('save_alert_recipients', input.value).then(response => {
            if (response && response.ok) {
                input.value = response.recipients;
                showMessage(true, savedMessage);
            } else {
                showMessage(false, response && response.message ? response.message : failedMessage);
            }
        }).catch(() => showMessage(false, failedMessage)).finally(() => {
            button.disabled = false;
        });
    });
    const timestampForm = document.getElementById('pdf-sealer-timestamp-form');
    const timestampFields = document.getElementById('pdf-sealer-timestamp-fields');
    const timestampMode = document.getElementById('pdf-sealer-timestamp-mode');
    const timestampFallback = document.getElementById('pdf-sealer-timestamp-fallback');
    const timestampMessage = document.getElementById('pdf-sealer-timestamp-message');
    const timestampSaved = <?= json_encode($framework->tt('timestamp_settings_saved'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const timestampFailed = <?= json_encode($framework->tt('timestamp_settings_save_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
    const showDiagnosticMessage = (style, text) => {
        diagnosticMessage.className = 'alert alert-' + style;
        diagnosticMessage.textContent = text;
        diagnosticMessage.hidden = false;
    };
    diagnosticButton.addEventListener('click', () => {
        if (diagnosticButton.disabled) return;
        diagnosticButton.disabled = true;
        diagnosticResults.hidden = true;
        showDiagnosticMessage('info', diagnosticText.running);
        module.ajax('run_diagnostic', null).then(response => {
            if (!response || response.ok !== true || typeof response.passed !== 'boolean' || !response.checks) {
                throw new Error('Diagnostic unavailable');
            }
            diagnosticResults.querySelectorAll('[data-diagnostic-check]').forEach(cell => {
                const status = response.checks[cell.dataset.diagnosticCheck];
                if (!['passed', 'failed', 'skipped'].includes(status)) throw new Error('Invalid diagnostic result');
                cell.textContent = diagnosticText[status];
                cell.className = status === 'passed' ? 'text-success' : status === 'failed' ? 'text-danger' : 'text-muted';
            });
            diagnosticResults.hidden = false;
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
