<?php

declare(strict_types=1);

use DE\RUB\PDFSealerExternalModule\Pdf\ProjectPipelineStatus;
use DE\RUB\PDFSealerExternalModule\Pki\CertificateIssuer;
use DE\RUB\PDFSealerExternalModule\Pki\IdentityRepository;
use DE\RUB\PDFSealerExternalModule\Pki\PkiHealthService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectBindingRepository;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIdentityService;
use DE\RUB\PDFSealerExternalModule\Pki\ProjectIssueLock;
use DE\RUB\PDFSealerExternalModule\Pki\SecretProtector;

/** @var \DE\RUB\PDFSealerExternalModule\PDFSealerExternalModule $module */
$framework = $module->framework;
$pid = $framework->getProjectId();
if ($pid === null || \ExternalModules\ExternalModules::getUsername() === null
    || !$framework->getUser()->hasDesignRights($pid)) {
    http_response_code(403);
    exit($framework->tt('project_status_access_denied'));
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pipeline = ProjectPipelineStatus::inspect((int) $pid, $module->PREFIX);
$protector = new SecretProtector();
$identities = new IdentityRepository($framework, $protector);
$health = new PkiHealthService($identities, $protector);
$healthReport = $health->inspect(time());
$identity = (new ProjectIdentityService(
    new ProjectBindingRepository($framework), $identities, $protector,
    new CertificateIssuer([$framework, 'createTempFile']), $health, new ProjectIssueLock(),
))->inspect((int) $pid);
$certificate = $identity['certificate'];
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>
<div class="pdf-sealer-status" style="max-width: 850px; margin: 20px 0;">
    <h2><?= $escape($framework->tt('project_status_title')) ?></h2>
    <p><?= $escape($framework->tt('project_status_intro')) ?></p>
    <h3><?= $escape($framework->tt('project_status_pipeline')) ?></h3>
    <p class="alert <?= $pipeline['state'] === 'assigned' ? 'alert-success' : 'alert-warning' ?>">
        <?= $escape($framework->tt('project_pipeline_' . $pipeline['state'])) ?>
    </p>
    <?php if ($pipeline['positions'] !== []): ?>
        <p><strong><?= $escape($framework->tt('project_status_positions')) ?>:</strong>
            <?= $escape(implode(', ', $pipeline['positions'])) ?></p>
    <?php endif; ?>
    <p><?= $escape($framework->tt('project_status_pipeline_help')) ?></p>
    <h3><?= $escape($framework->tt('pki_status')) ?></h3>
    <p><?= $escape($framework->tt('project_pki_' . strtolower($healthReport->status->value))) ?></p>
    <h3><?= $escape($framework->tt('project_status_certificate')) ?></h3>
    <p><?= $escape($framework->tt('project_identity_' . $identity['state'])) ?></p>
    <dl>
        <dt><?= $escape($framework->tt('project_status_uuid')) ?></dt>
        <dd><code><?= $escape($identity['uuid'] ?? $framework->tt('project_status_uuid_pending')) ?></code></dd>
        <?php if ($certificate !== null): ?>
            <dt><?= $escape($framework->tt('pki_subject')) ?></dt>
            <dd style="overflow-wrap: anywhere;"><?= $escape($certificate['subject']) ?></dd>
            <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt>
            <dd><code style="overflow-wrap: anywhere;"><?= $escape($certificate['fingerprint']) ?></code></dd>
            <dt><?= $escape($framework->tt('trust_valid_from')) ?></dt>
            <dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $certificate['valid_from'])) ?></dd>
            <dt><?= $escape($framework->tt('trust_valid_until')) ?></dt>
            <dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $certificate['valid_until'])) ?></dd>
        <?php endif; ?>
    </dl>
    <p><?= $escape($framework->tt('project_status_read_only')) ?></p>
    <p><?= $escape($framework->tt('project_status_logging')) ?></p>
    <p><a href="<?= $escape($module::publicTrustUrl()) ?>" target="_blank" rel="noopener"><?= $escape($framework->tt('project_trust_link_name')) ?></a></p>
</div>
<?php require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php'; ?>
