<?php
/** CC public review; disabled projects and pending work remain eligible for a signing block. */
?>
<h5><?= $escape($framework->tt('revocation_title')) ?></h5>
<p class="small text-muted"><?= $escape($framework->tt('revocation_intro')) ?></p>
<form id="pdf-sealer-revocation">
    <fieldset <?= $assignmentProjectsUnavailable || $providersUnavailable || $revocationProjects === [] ? 'disabled' : '' ?>>
        <label for="revocation-pid"><?= $escape($framework->tt('provider_pid')) ?></label>
        <div class="mb-3"><select class="form-select form-select-sm" id="revocation-pid" required>
            <option value="" selected><?= $escape($framework->tt('revocation_choose_project')) ?></option>
            <?php foreach ($revocationProjects as $project): ?>
                <option value="<?= $escape($project['project_id']) ?>"><?= $escape('(' . $project['project_id'] . ') ' . $project['app_title']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><?= $escape($framework->tt('revocation_review')) ?></button>
        <div id="revocation-review" class="mt-3" hidden>
            <dl class="pdf-sealer-certificate">
                <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd id="revocation-subject" style="white-space: pre-line"></dd>
                <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code class="pdf-sealer-fingerprint" id="revocation-fingerprint"></code></dd>
                <dt><?= $escape($framework->tt('pki_thumbprint')) ?></dt><dd><code class="pdf-sealer-fingerprint" id="revocation-thumbprint"></code></dd>
            </dl>
            <p id="revocation-already" class="alert alert-warning" hidden><?= $escape($framework->tt('revocation_already')) ?></p>
            <div id="revocation-choice">
                <label for="revocation-reason"><?= $escape($framework->tt('revocation_reason')) ?></label>
                <select class="form-select form-select-sm mb-3" id="revocation-reason">
                    <option value="superseded"><?= $escape($framework->tt('revocation_superseded')) ?></option>
                    <option value="compromise"><?= $escape($framework->tt('revocation_compromise')) ?></option>
                </select>
                <p class="alert alert-warning"><?= $escape($framework->tt('revocation_warning')) ?></p>
                <button type="button" class="btn btn-danger btn-sm" id="revocation-confirm"><?= $escape($framework->tt('revocation_confirm')) ?></button>
            </div>
        </div>
    </fieldset>
</form>
