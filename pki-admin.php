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
$organization = (new PrimarySystemSettingReader($framework))->get('organization');
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
        <?php endforeach; ?>
    <?php endif; ?>
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
})();
</script>
<?php require_once APP_PATH_DOCROOT . 'ControlCenter/footer.php'; ?>
