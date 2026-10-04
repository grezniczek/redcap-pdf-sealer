<?php
// Retained form nodes are moved into rcDialog; hidden hosts keep their state and event handlers reusable.
?>
<hr>
<h5><?= $escape($framework->tt('pki_admin_workflows')) ?></h5>
<div class="pdf-sealer-workflow-links">
    <div class="mb-1">
        <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-provider-workflow="register" aria-haspopup="dialog" aria-describedby="provider-workflow-register-help"><?= $escape($framework->tt('provider_register')) ?></button>
        <p id="provider-workflow-register-help" class="small text-muted"><?= $escape($framework->tt('provider_register_summary')) ?></p>
    </div>
    <div class="mb-1">
        <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-provider-workflow="assign" aria-haspopup="dialog" aria-describedby="provider-workflow-assign-help"><?= $escape($framework->tt('provider_assign')) ?></button>
        <p id="provider-workflow-assign-help" class="small text-muted"><?= $escape($framework->tt('provider_assign_summary')) ?></p>
    </div>
    <div class="mb-1">
        <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-provider-workflow="transition" aria-haspopup="dialog" aria-describedby="provider-workflow-transition-help"><?= $escape($framework->tt('transition_title')) ?></button>
        <p id="provider-workflow-transition-help" class="small text-muted"><?= $escape($framework->tt('provider_transition_summary')) ?></p>
    </div>
    <div class="mb-1">
        <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-provider-workflow="renewal" aria-haspopup="dialog" aria-describedby="provider-workflow-renewal-help"><?= $escape($framework->tt('renewal_title')) ?></button>
        <p id="provider-workflow-renewal-help" class="small text-muted"><?= $escape($framework->tt('provider_renewal_summary')) ?></p>
    </div>
    <div class="mb-1">
        <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-provider-workflow="revocation" aria-haspopup="dialog" aria-describedby="provider-workflow-revocation-help"><?= $escape($framework->tt('revocation_title')) ?></button>
        <p id="provider-workflow-revocation-help" class="small text-muted"><?= $escape($framework->tt('provider_revocation_summary')) ?></p>
    </div>
</div>
<div data-provider-workflow-host="register" hidden>
    <div class="pdf-sealer-dialog-body">
        <p class="small text-muted"><?= $escape($framework->tt('provider_upload_help')) ?></p>
        <form id="pdf-sealer-provider-register">
            <fieldset>
                <label for="provider-name"><?= $escape($framework->tt('provider_name')) ?></label>
                <input class="form-control form-control-sm mb-3" id="provider-name" maxlength="128" required>
                <label for="provider-chain"><?= $escape($framework->tt('provider_chain')) ?></label>
                <input class="form-control form-control-sm mb-3" id="provider-chain" type="file" accept=".pem,.crt,.cer" required>
                <label for="provider-source"><?= $escape($framework->tt('timestamp_mode_label')) ?></label>
                <select class="form-select form-select-sm mb-3" id="provider-source" data-workflow-reset-change required>
                    <option value="" selected disabled><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                    <?php foreach ($sourceChoices as $id => $name): ?><option value="<?= $escape($id) ?>"><?= $escape($name) ?></option><?php endforeach; ?>
                </select>
                <label class="mb-3"><input type="checkbox" id="provider-fallback" disabled> <?= $escape($framework->tt('timestamp_fallback_allow')) ?></label><br>
            </fieldset>
        </form>
    </div>
</div>
<div data-provider-workflow-host="assign" hidden>
    <div class="pdf-sealer-dialog-body">
        <p class="small text-muted"><?= $escape($framework->tt('provider_assign_help')) ?></p>
        <form id="pdf-sealer-provider-assign">
            <?php if ($assignmentProjectsUnavailable): ?><p class="alert alert-warning"><?= $escape($framework->tt('provider_projects_unavailable')) ?></p><?php endif; ?>
            <fieldset <?= $assignableProviders === [] || $assignmentProjectsUnavailable || $assignmentProjects === [] ? 'disabled' : '' ?>>
                <label for="provider-pid"><?= $escape($framework->tt('provider_pid')) ?></label>
                <div class="mb-3">
                    <select class="form-select form-select-sm" id="provider-pid" data-workflow-project required>
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
        </form>
    </div>
</div>
<div data-provider-workflow-host="transition" hidden>
    <div class="pdf-sealer-dialog-body">
        <p class="small text-muted"><?= $escape($framework->tt('transition_intro')) ?></p>
        <form id="pdf-sealer-transition">
            <fieldset <?= $assignmentProjectsUnavailable || $transitionProjects === [] ? 'disabled' : '' ?>>
                <label for="transition-pid"><?= $escape($framework->tt('provider_pid')) ?></label>
                <div class="mb-3"><select class="form-select form-select-sm" id="transition-pid" data-workflow-project required>
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
        </form>
    </div>
</div>
<div data-provider-workflow-host="renewal" hidden>
    <div class="pdf-sealer-dialog-body">
        <?php require __DIR__ . '/project-renewal.php'; ?>
    </div>
</div>
<div data-provider-workflow-host="revocation" hidden>
    <div class="pdf-sealer-dialog-body">
        <?php require __DIR__ . '/project-revocation.php'; ?>
    </div>
</div>
