<?php

declare(strict_types=1);

use Com\Tecnick\Pdf\Sign\Cms\Certificate;
use DE\RUB\PDFSealerExternalModule\Alerts\AdminAlarmService;
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
$error = null;
$success = false;
$recipientsSaved = false;
$recipientInput = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? null;
    if ($action === 'initialize' && is_string($_POST['organization'] ?? null)) {
        try {
            (new PkiInitializationService(
                $framework,
                $identities,
                new ProjectBindingRepository($framework),
                new CertificateIssuer([$framework, 'createTempFile']),
                $health,
                new PkiInitializationLock(),
            ))->initialize($_POST['organization']);
            $success = true;
        } catch (Throwable $e) {
            error_log('PDF Sealer PKI initialization failed (' . get_class($e) . ')');
            $error = $framework->tt('pki_init_failed');
        }
    } elseif ($action === 'save_recipients' && is_string($_POST['recipients'] ?? null)) {
        $recipientInput = $_POST['recipients'];
        $parsed = strlen($recipientInput) <= 4096 ? AdminAlarmService::parseRecipients($recipientInput) : null;
        if ($parsed === null) {
            http_response_code(400);
            $error = $framework->tt('admin_alert_recipients_invalid');
        } else {
            try {
                $framework->setSystemSetting('admin-alert-recipients', $parsed);
                $recipientsSaved = true;
                $recipientInput = null;
            } catch (Throwable $e) {
                error_log('PDF Sealer alarm recipient update failed (' . get_class($e) . ')');
                $error = $framework->tt('admin_alert_recipients_save_failed');
            }
        }
    } else {
        http_response_code(400);
        $error = $framework->tt('pki_invalid_request');
    }
}
$report = $health->inspect(time());
$organization = (new PrimarySystemSettingReader($framework))->get('organization');
$recipients = $framework->getSystemSetting('admin-alert-recipients');
$recipients = is_array($recipients) ? implode(', ', array_filter($recipients, 'is_string')) : (is_string($recipients) ? $recipients : '');
?>
<div style="max-width: 820px; margin: 24px auto;">
    <h2><?= $escape($framework->tt('pki_page_title')) ?></h2>
    <?php if ($error !== null): ?><div class="alert alert-danger"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $escape($framework->tt('pki_init_success')) ?></div><?php endif; ?>
    <?php if ($recipientsSaved): ?><div class="alert alert-success"><?= $escape($framework->tt('admin_alert_recipients_saved')) ?></div><?php endif; ?>
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
    <form method="post">
        <input type="hidden" name="redcap_external_module_csrf_token" value="<?= $escape($framework->getCSRFToken()) ?>">
        <input type="hidden" name="action" value="save_recipients">
        <div class="form-group">
            <label for="pdf-sealer-recipients"><?= $escape($framework->tt('admin_alert_recipients')) ?></label>
            <textarea id="pdf-sealer-recipients" class="form-control" name="recipients" rows="3" maxlength="4096"><?= $escape($recipientInput ?? $recipients) ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?= $escape($framework->tt('admin_alert_recipients_save')) ?></button>
    </form>
</div>
