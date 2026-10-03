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
$provider = null;
$binding = null;
$providerName = $framework->tt('pki_not_configured');
$providerRetired = false;
try {
    $binding = (new ProjectBindingRepository($framework))->find((int) $pid);
    if ($binding === null && $identities->providers()->requiresAssignment()) {
        $providerName = $framework->tt('project_identity_summary_assignment_required');
    } else {
        $provider = $identities->providers()->provider($binding?->providerId ?? $identities->providers()->defaultId());
        $providerRetired = $identities->providers()->isRetired($provider['id']);
        $providerName = $provider['name'] ?? $framework->tt('provider_builtin');
    }
} catch (Throwable) { $provider = null; $binding = null; /* Keep the explicit unavailable label. */ }
$timestampName = $framework->tt('pki_not_configured');
$timestampAlternatives = [];
try {
    if ($provider !== null) {
        foreach ($provider['timestamp_alternatives'] as $alternativeId) {
            $timestampAlternatives[] = $alternativeId === 'builtin-tsa' ? $framework->tt('timestamp_mode_internal')
                : $identities->providers()->source($alternativeId)['name'];
        }
        $sourceId = $provider['timestamp_source'];
        $timestampName = $sourceId === null ? $framework->tt('timestamp_mode_none')
            : ($sourceId === 'builtin-tsa' ? $framework->tt('timestamp_mode_internal')
                : $identities->providers()->source($sourceId)['name']);
    }
} catch (Throwable) { $timestampName = $framework->tt('external_tsa_unavailable'); }
$enrollmentProvider = $provider;
$enrollmentProviderName = $providerName;
$enrollmentRetired = $providerRetired;
$transitionPending = ($binding?->pendingProviderId ?? null) !== null;
if ($transitionPending) {
    try {
        $enrollmentProvider = $identities->providers()->provider($binding->pendingProviderId);
        $enrollmentProviderName = $enrollmentProvider['name'] ?? $framework->tt('provider_builtin');
        $enrollmentRetired = $identities->providers()->isRetired($binding->pendingProviderId);
    } catch (Throwable) { $enrollmentProvider = null; $enrollmentProviderName = $framework->tt('provider_unavailable'); }
}
$enrollment = null;
$enrollmentAvailable = ($enrollmentProvider['kind'] ?? null) === 'external';
$enrollmentFailed = false;
if ($enrollmentAvailable) {
    try {
        $enrollment = (new \DE\RUB\PDFSealerExternalModule\Pki\ProjectEnrollmentService(
            $framework, new ProjectBindingRepository($framework), $identities->providers(), $protector, new ProjectIssueLock(),
        ))->inspect((int) $pid);
    } catch (Throwable) { $enrollmentFailed = true; }
}

$pipelineTone = $pipeline['state'] === 'assigned' ? 'ready' : 'degraded';
$identityTone = match ($identity['state']) {
    'ready' => 'ready',
    'unusable', 'expired', 'not_yet_valid', 'revoked' => 'broken',
    'pending', 'transition_pending', 'ca_retired', 'awaiting_certificate', 'assignment_required', 'unavailable' => 'degraded',
    default => 'uninitialized',
};
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
if ($enrollmentAvailable) { $framework->initializeJavascriptModuleObject(); }
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
        <?php if ($providerRetired): ?><p class="alert alert-warning"><?= $escape($framework->tt('provider_retired_project')) ?></p><?php endif; ?>
        <?php if ($transitionPending): ?>
            <p class="alert alert-info"><?= $escape($framework->tt('transition_project_help')) ?></p>
            <p><strong><?= $escape($framework->tt('transition_target_label')) ?>:</strong> <?= $escape($enrollmentProviderName) ?></p>
        <?php endif; ?>
        <dl class="pdf-sealer-certificate">
            <dt><?= $escape($framework->tt('provider_label')) ?></dt><dd><?= $escape($providerName) ?></dd>
            <dt><?= $escape($framework->tt('timestamp_mode_label')) ?></dt><dd><?= $escape($timestampName) ?>
                <?php foreach ($timestampAlternatives as $index => $name): ?><br><?= $escape($framework->tt('timestamp_alternative_' . ($index + 1))) ?>: <?= $escape($name) ?><?php endforeach; ?>
                <?php if (($provider['timestamp_source'] ?? null) !== null): ?><br><?= $escape($framework->tt($provider['bb_fallback'] ? 'timestamp_fallback_allow' : 'timestamp_fallback_fail')) ?><?php endif; ?>
            </dd>
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
                <dt><?= $escape($framework->tt('pki_thumbprint')) ?></dt>
                <dd><code class="pdf-sealer-fingerprint"><?= $escape($certificate['thumbprint']) ?></code></dd>
            <?php endif; ?>
        </dl>
        <p class="small text-muted mb-0"><?= $escape($framework->tt('project_status_read_only')) ?></p>
    </section>
    <?php if ($enrollmentAvailable): ?>
    <section class="pdf-sealer-panel pdf-sealer-section" aria-labelledby="enrollment-title">
        <h5 id="enrollment-title"><i class="fas fa-file-signature" aria-hidden="true"></i> <?= $escape($framework->tt('enrollment_title')) ?></h5>
        <p><?= $escape($framework->tt('enrollment_help')) ?></p>
        <p><strong><?= $escape($framework->tt('provider_label')) ?>:</strong> <?= $escape($enrollmentProviderName) ?></p>
        <?php if ($enrollmentRetired): ?><p class="alert alert-warning"><?= $escape($framework->tt('enrollment_provider_retired')) ?></p><?php endif; ?>
        <?php if ($enrollmentFailed): ?>
            <p class="alert alert-warning"><?= $escape($framework->tt('enrollment_failed')) ?></p>
        <?php else: ?>
            <dl id="enrollment-details" class="pdf-sealer-certificate" <?= $enrollment === null ? 'hidden' : '' ?>>
                <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd id="enrollment-subject"><?= $module::certificateSubjectHtml($enrollment['subject'] ?? '') ?></dd>
                <dt><?= $escape($framework->tt('enrollment_created')) ?></dt><dd id="enrollment-created"><?= $enrollment === null ? '' : $escape(gmdate('Y-m-d H:i:s \U\T\C', $enrollment['created_at'])) ?></dd>
                <dt><?= $escape($framework->tt('enrollment_digest')) ?></dt><dd><code id="enrollment-digest" class="pdf-sealer-fingerprint"><?= $escape($enrollment['csr_sha256'] ?? '') ?></code></dd>
            </dl>
            <div class="pdf-sealer-actions">
                <button type="button" class="btn btn-primaryrc btn-sm" id="enrollment-generate" <?= $enrollmentRetired ? 'disabled' : '' ?> <?= $enrollment === null ? '' : 'hidden' ?>><?= $escape($framework->tt('enrollment_generate')) ?></button>
                <button type="button" class="btn btn-primaryrc btn-sm" id="enrollment-download" <?= $enrollment === null ? 'hidden' : '' ?>><?= $escape($framework->tt('enrollment_download')) ?></button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="enrollment-cancel" <?= $enrollment === null ? 'hidden' : '' ?>><?= $escape($framework->tt('enrollment_cancel')) ?></button>
            </div>
            <form id="enrollment-certificate-form" class="mt-3" <?= $enrollmentRetired || $enrollment === null ? 'hidden' : '' ?>>
                <fieldset>
                    <label for="enrollment-certificate-file"><?= $escape($framework->tt('enrollment_certificate_label')) ?></label>
                    <input class="form-control form-control-sm mb-2" type="file" accept=".pem,.crt,.cer" id="enrollment-certificate-file" required>
                    <p class="small text-muted"><?= $escape($framework->tt('enrollment_certificate_help')) ?></p>
                    <button type="submit" class="btn btn-primaryrc btn-sm"><?= $escape($framework->tt('enrollment_certificate_review')) ?></button>
                    <div id="enrollment-certificate-review" class="mt-3" hidden>
                        <dl class="pdf-sealer-certificate">
                            <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd id="certificate-review-subject" style="white-space:pre-line"></dd>
                            <dt><?= $escape($framework->tt('enrollment_certificate_issuer')) ?></dt><dd id="certificate-review-issuer" style="white-space:pre-line"></dd>
                            <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code id="certificate-review-fingerprint" class="pdf-sealer-fingerprint"></code></dd>
                            <dt><?= $escape($framework->tt('pki_thumbprint')) ?></dt><dd><code id="certificate-review-thumbprint" class="pdf-sealer-fingerprint"></code></dd>
                            <dt><?= $escape($framework->tt('trust_valid_from')) ?></dt><dd id="certificate-review-from"></dd>
                            <dt><?= $escape($framework->tt('trust_valid_until')) ?></dt><dd id="certificate-review-until"></dd>
                        </dl>
                        <p><?= $escape($framework->tt('enrollment_certificate_activation_help')) ?></p>
                        <button type="button" class="btn btn-primaryrc btn-sm" id="enrollment-certificate-activate"><?= $escape($framework->tt('enrollment_certificate_activate')) ?></button>
                    </div>
                </fieldset>
            </form>
            <p id="enrollment-message" class="alert mt-3" role="status" hidden></p>
        <?php endif; ?>
        <p class="small text-muted mt-3"><?= $escape($framework->tt('enrollment_activation_pending')) ?></p>
    </section>
    <?php endif; ?>
    <p class="small text-muted"><?= $escape($framework->tt('project_status_logging')) ?></p>
</div>
<?php if ($enrollmentAvailable && !$enrollmentFailed): ?>
<script>
(() => {
    const module = <?= $framework->getJavascriptModuleObjectName() ?>;
    const providerRetired = <?= json_encode($enrollmentRetired) ?>;
    let pending = <?= json_encode($enrollment, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const enrollmentDateTimeFormat = <?= json_encode(\DateTimeRC::get_user_format_full(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const formatEnrollmentTime = date => {
        const [dateFormat, clockFormat] = enrollmentDateTimeFormat.split('_');
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
    if (pending) document.getElementById('enrollment-created').textContent = formatEnrollmentTime(new Date(pending.created_at * 1000));
    const message = document.getElementById('enrollment-message');
    const certificateForm = document.getElementById('enrollment-certificate-form');
    const certificateFields = certificateForm.querySelector('fieldset');
    const certificateFile = document.getElementById('enrollment-certificate-file');
    const certificateReview = document.getElementById('enrollment-certificate-review');
    let reviewed = null;
    let certificatePem = null;
    certificateFile.addEventListener('change', () => { reviewed = null; certificateReview.hidden = true; });
    const buttons = ['generate', 'download', 'cancel'].map(action => document.getElementById('enrollment-' + action));
    buttons.forEach((button, index) => button.addEventListener('click', async () => {
        const action = ['generate', 'download', 'cancel'][index];
        if (action === 'cancel' && !window.confirm(<?= json_encode($framework->tt('enrollment_cancel_confirm'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)) return;
        buttons.forEach(b => b.disabled = true);
        certificateFields.disabled = true;
        message.hidden = true;
        let serverError = null;
        try {
            const response = await module.ajax(action + '_project_csr', action === 'generate' ? null : {id: pending.id});
            if (!response?.ok) { serverError = response?.message; throw new Error('Enrollment failed'); }
            if (action === 'cancel') { location.reload(); return; }
            pending = response.pending;
            certificateForm.hidden = providerRetired;
            document.getElementById('enrollment-subject').textContent = pending.subject;
            document.getElementById('enrollment-created').textContent = formatEnrollmentTime(new Date(pending.created_at * 1000));
            document.getElementById('enrollment-digest').textContent = pending.csr_sha256;
            document.getElementById('enrollment-details').hidden = false;
            buttons[0].hidden = true; buttons[1].hidden = buttons[2].hidden = false;
            const bytes = Uint8Array.from(atob(response.base64), char => char.charCodeAt(0));
            const url = URL.createObjectURL(new Blob([bytes], {type: response.content_type}));
            const link = document.createElement('a');
            link.href = url; link.download = response.filename;
            document.body.appendChild(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (error) {
            message.textContent = serverError || <?= json_encode($framework->tt('enrollment_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            message.className = 'alert alert-danger mt-3'; message.hidden = false;
        } finally { buttons.forEach(b => b.disabled = providerRetired && b.id === 'enrollment-generate'); certificateFields.disabled = providerRetired; }
    }));
    const certificateAction = async activate => {
        buttons.forEach(b => b.disabled = true);
        certificateFields.disabled = true;
        message.hidden = true;
        let serverError = null;
        try {
            if (activate) {
                if (!reviewed) throw new Error('Review required');
                const response = await module.ajax('activate_project_certificate', {id: pending.id, pem: certificatePem,
                    review_hash: reviewed.review_hash, active_identity_id: reviewed.active_identity_id});
                if (!response?.ok) { serverError = response?.message; throw new Error('Certificate request failed'); }
                location.reload();
            } else {
                reviewed = null; certificateReview.hidden = true;
                const file = certificateFile.files[0];
                if (!file || file.size > 65536) throw new Error('Invalid certificate upload');
                certificatePem = await file.text();
                const response = await module.ajax('review_project_certificate', {id: pending.id, pem: certificatePem});
                if (!response?.ok) { serverError = response?.message; throw new Error('Certificate request failed'); }
                reviewed = response;
                const cert = response.certificate;
                ['subject', 'issuer'].forEach(field => {
                    document.getElementById('certificate-review-' + field).textContent = cert[field].replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n');
                });
                document.getElementById('certificate-review-fingerprint').textContent = cert.fingerprint;
                document.getElementById('certificate-review-thumbprint').textContent = cert.thumbprint;
                document.getElementById('certificate-review-from').textContent = new Date(cert.valid_from * 1000).toISOString().replace('T',' ').replace('.000Z',' UTC');
                document.getElementById('certificate-review-until').textContent = new Date(cert.valid_until * 1000).toISOString().replace('T',' ').replace('.000Z',' UTC');
                certificateReview.hidden = false;
            }
        } catch (error) {
            reviewed = null; certificateReview.hidden = true;
            message.textContent = serverError || <?= json_encode($framework->tt('enrollment_certificate_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            message.className = 'alert alert-danger mt-3'; message.hidden = false;
        } finally { buttons.forEach(b => b.disabled = providerRetired && b.id === 'enrollment-generate'); certificateFields.disabled = providerRetired; }
    };
    certificateForm.addEventListener('submit', event => { event.preventDefault(); certificateAction(false); });
    document.getElementById('enrollment-certificate-activate').addEventListener('click', () => certificateAction(true));
})();
</script>
<?php endif; ?>
<?php require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php'; ?>
