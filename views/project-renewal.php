<?php
/** Control Center built-in renewal form; $renewalProjects contains public project labels only. */
?>
<h5><?= $escape($framework->tt('renewal_title')) ?></h5>
<p class="small text-muted"><?= $escape($framework->tt('renewal_intro')) ?></p>
<form id="pdf-sealer-renewal">
    <fieldset <?= $assignmentProjectsUnavailable || $providersUnavailable || $renewalProjects === [] ? 'disabled' : '' ?>>
        <label for="renewal-pid"><?= $escape($framework->tt('provider_pid')) ?></label>
        <div class="mb-3"><select class="form-select form-select-sm" id="renewal-pid" required>
            <option value="" selected><?= $escape($framework->tt($renewalProjects === [] ? 'renewal_no_projects' : 'renewal_choose_project')) ?></option>
            <?php foreach ($renewalProjects as $project): ?>
                <option value="<?= $escape($project['project_id']) ?>"><?= $escape('(' . $project['project_id'] . ') ' . $project['app_title']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><?= $escape($framework->tt('renewal_review')) ?></button>
        <div id="renewal-review" class="mt-3" hidden>
            <dl class="pdf-sealer-certificate">
                <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd id="renewal-subject" style="white-space: pre-line"></dd>
                <dt><?= $escape($framework->tt('trust_valid_from')) ?></dt><dd id="renewal-valid-from"></dd>
                <dt><?= $escape($framework->tt('pki_valid_until')) ?></dt><dd id="renewal-valid-until"></dd>
                <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code class="pdf-sealer-fingerprint" id="renewal-fingerprint"></code></dd>
                <dt><?= $escape($framework->tt('pki_thumbprint')) ?></dt><dd><code class="pdf-sealer-fingerprint" id="renewal-thumbprint"></code></dd>
            </dl>
            <p id="renewal-issuer-expiry" class="small text-muted"></p>
            <p id="renewal-expired" class="alert alert-warning" hidden><?= $escape($framework->tt('renewal_expired')) ?></p>
            <p class="small"><?= $escape($framework->tt('renewal_confirm_help')) ?></p>
            <button type="button" class="btn btn-warning btn-sm" id="renewal-confirm"><?= $escape($framework->tt('renewal_confirm')) ?></button>
        </div>
    </fieldset>
    <p class="alert mt-3" role="status" hidden></p>
</form>
