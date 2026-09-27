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
    CertificateIssuer::forFramework($framework), $health, new ProjectIssueLock(),
))->inspect((int) $pid);
$certificate = $identity['certificate'];
$providerName = $framework->tt('pki_not_configured');
try {
    $binding = (new ProjectBindingRepository($framework))->find((int) $pid);
    if ($binding === null && $identities->providers()->requiresAssignment()) {
        $providerName = $framework->tt('project_identity_summary_assignment_required');
    } else {
        $provider = $identities->providers()->provider($binding?->providerId ?? $identities->providers()->defaultId());
        $providerName = $provider['name'] ?? $framework->tt('provider_builtin');
    }
} catch (Throwable) { /* Keep the explicit unavailable label. */ }

$pipelineTone = $pipeline['state'] === 'assigned' ? 'ready' : 'degraded';
$identityTone = match ($identity['state']) {
    'ready' => 'ready',
    'unusable', 'expired', 'not_yet_valid' => 'broken',
    'pending', 'awaiting_certificate', 'assignment_required', 'unavailable' => 'degraded',
    default => 'uninitialized',
};
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>
<link rel="stylesheet" href="<?= $escape($framework->getUrl('assets/admin.css')) ?>">
<div class="pdf-sealer-admin pdf-sealer-status">
    <p class="pdf-sealer-brand text-muted"><em><?= $escape($framework->tt('pki_brand')) ?></em></p>
    <h4 class="mb-2"><i class="fas fa-file-signature" aria-hidden="true"></i> <?= $escape($framework->tt('project_status_page_title')) ?></h4>
    <p><?= $escape($framework->tt('project_status_intro')) ?></p>
    <div class="pdf-sealer-summary">
        <div class="pdf-sealer-card pdf-sealer-health-<?= $escape($pipelineTone) ?>">
            <div class="small text-muted"><?= $escape($framework->tt('project_status_pipeline')) ?></div>
            <strong><?= $escape($framework->tt('project_pipeline_summary_' . $pipeline['state'])) ?></strong>
        </div>
        <div class="pdf-sealer-card pdf-sealer-health-<?= $escape(strtolower($healthReport->status->value)) ?>">
            <div class="small text-muted"><?= $escape($framework->tt('pki_status')) ?></div>
            <strong><?= $escape($framework->tt('pki_state_' . strtolower($healthReport->status->value))) ?></strong>
        </div>
        <div class="pdf-sealer-card pdf-sealer-health-<?= $escape($identityTone) ?>">
            <div class="small text-muted"><?= $escape($framework->tt('project_status_certificate')) ?></div>
            <strong><?= $escape($framework->tt('project_identity_summary_' . $identity['state'])) ?></strong>
        </div>
    </div>
    <p class="pdf-sealer-trust-link"><a href="<?= $escape($module::publicTrustUrl()) ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt" aria-hidden="true"></i> <?= $escape($framework->tt('project_trust_link_name')) ?></a></p>
    <section class="pdf-sealer-panel pdf-sealer-section" aria-labelledby="pdf-sealer-project-pipeline">
        <h5 id="pdf-sealer-project-pipeline"><i class="fas fa-stream" aria-hidden="true"></i> <?= $escape($framework->tt('project_status_pipeline')) ?></h5>
        <p><?= $escape($framework->tt('project_pipeline_' . $pipeline['state'])) ?></p>
        <?php if ($pipeline['positions'] !== []): ?>
            <p><strong><?= $escape($framework->tt('project_status_positions')) ?>:</strong>
                <?= $escape(implode(', ', $pipeline['positions'])) ?></p>
        <?php endif; ?>
        <p class="small text-muted mb-0"><?= $escape($framework->tt('project_status_pipeline_help')) ?></p>
    </section>
    <section class="pdf-sealer-panel pdf-sealer-section" aria-labelledby="pdf-sealer-project-pki">
        <h5 id="pdf-sealer-project-pki"><i class="fas fa-shield-alt" aria-hidden="true"></i> <?= $escape($framework->tt('pki_status')) ?></h5>
        <p class="mb-0"><?= $escape($framework->tt('project_pki_' . strtolower($healthReport->status->value))) ?></p>
    </section>
    <section class="pdf-sealer-panel pdf-sealer-section" aria-labelledby="pdf-sealer-project-certificate">
        <h5 id="pdf-sealer-project-certificate"><i class="fas fa-certificate" aria-hidden="true"></i> <?= $escape($framework->tt('project_status_certificate')) ?></h5>
        <p><?= $escape($framework->tt('project_identity_' . $identity['state'])) ?></p>
        <dl class="pdf-sealer-certificate">
            <dt><?= $escape($framework->tt('provider_label')) ?></dt><dd><?= $escape($providerName) ?></dd>
            <dt><?= $escape($framework->tt('project_status_uuid')) ?></dt>
            <dd><?php if ($identity['uuid'] !== null): ?><code class="pdf-sealer-fingerprint"><?= $escape($identity['uuid']) ?></code><?php else: ?><?= $escape($framework->tt('project_status_uuid_pending')) ?><?php endif; ?></dd>
            <?php if ($certificate !== null): ?>
                <dt><?= $escape($framework->tt('pki_subject')) ?></dt>
                <dd><?= $module::certificateSubjectHtml($certificate['subject']) ?></dd>
                <dt><?= $escape($framework->tt('trust_valid_from')) ?></dt>
                <dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $certificate['valid_from'])) ?></dd>
                <dt><?= $escape($framework->tt('trust_valid_until')) ?></dt>
                <dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $certificate['valid_until'])) ?></dd>
                <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt>
                <dd><code class="pdf-sealer-fingerprint"><?= $escape($certificate['fingerprint']) ?></code></dd>
            <?php endif; ?>
        </dl>
        <p class="small text-muted mb-0"><?= $escape($framework->tt('project_status_read_only')) ?></p>
    </section>
    <p class="small text-muted"><?= $escape($framework->tt('project_status_logging')) ?></p>
</div>
<?php require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php'; ?>
