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
        <form id="pdf-sealer-provider-assign" data-remaining-projects="<?= count($assignmentProjects) ?>" data-projects-unavailable="<?= $assignmentProjectsUnavailable ? '1' : '0' ?>">
            <?php if ($assignmentProjectsUnavailable): ?><p class="alert alert-warning"><?= $escape($framework->tt('provider_projects_unavailable')) ?></p><?php endif; ?>
            <fieldset <?= $assignableProviders === [] || $assignmentProjectsUnavailable || $assignmentProjects === [] ? 'disabled' : '' ?>>
                <div class="pdf-sealer-table-wrap mb-3">
                    <table id="pdf-sealer-assignment-projects" class="table table-sm hover">
                        <thead><tr>
                            <th><?= $escape($framework->tt('provider_select')) ?></th>
                            <th><?= $escape($framework->tt('provider_project_pid')) ?></th>
                            <th><?= $escape($framework->tt('provider_project_name')) ?></th>
                            <th><?= $escape($framework->tt('provider_project_status')) ?></th>
                        </tr></thead>
                        <tbody><?php foreach ($assignmentProjects as $project): ?>
                            <?php $statusKey = match ((int) $project['status']) {
                                0 => 'provider_project_development',
                                1 => 'provider_project_production',
                                2 => empty($project['completed_time']) ? 'provider_project_analysis' : 'provider_project_completed',
                                default => 'provider_project_unknown',
                            }; ?>
                            <tr data-assignment-pid="<?= $escape($project['project_id']) ?>">
                                <td><input type="checkbox" data-assignment-select aria-label="<?= $escape($framework->tt('provider_select_project', $project['project_id'])) ?>"></td>
                                <td><?= $escape($project['project_id']) ?></td>
                                <td data-assignment-name><?= $escape($project['app_title']) ?></td>
                                <td data-assignment-status><?= $escape($framework->tt($statusKey)) ?></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
                <p class="small text-muted" id="pdf-sealer-assignment-count" aria-live="polite"><?= $escape($framework->tt('provider_selection_count', 0)) ?></p>
                <p class="small text-muted"><?= $escape($framework->tt('provider_selection_help')) ?></p>
                <label for="provider-selection"><?= $escape($framework->tt('provider_label')) ?></label>
                <select class="form-select form-select-sm mb-3" id="provider-selection" required>
                    <option value="" selected disabled><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                    <?php foreach ($assignableProviders as $provider): ?><option value="<?= $escape($provider['id']) ?>"><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?></option><?php endforeach; ?>
                </select>
                <button id="pdf-sealer-assign-projects" class="btn btn-primaryrc btn-sm" type="submit" disabled><?= $escape($framework->tt('provider_assign_selected')) ?></button>
            </fieldset>
        </form>
    </div>
</div>
<div data-provider-workflow-host="transition" hidden>
    <div class="pdf-sealer-dialog-body">
        <p class="small text-muted"><?= $escape($framework->tt('transition_intro')) ?></p>
        <?php $providerPresentation = array_column(array_map(static fn(array $p): array =>
            ['id' => $p['id'], 'name' => $p['name'] ?? $framework->tt('provider_builtin'), 'kind' => $p['kind']], $providerCatalog), null, 'id');
        $providerNames = array_column($providerPresentation, 'name', 'id'); ?>
        <form id="pdf-sealer-transition" data-projects-unavailable="<?= $assignmentProjectsUnavailable ? '1' : '0' ?>"
            data-providers="<?= $escape(json_encode($providerPresentation, JSON_THROW_ON_ERROR)) ?>">
            <fieldset <?= $assignmentProjectsUnavailable || $transitionProjects === [] ? 'disabled' : '' ?>>
                <div class="pdf-sealer-table-wrap mb-3">
                    <table id="pdf-sealer-transition-projects" class="table table-sm hover">
                        <thead><tr>
                            <th><?= $escape($framework->tt('provider_select')) ?></th>
                            <th><?= $escape($framework->tt('provider_project_pid')) ?></th>
                            <th><?= $escape($framework->tt('provider_project_name')) ?></th>
                            <th><?= $escape($framework->tt('provider_project_status')) ?></th>
                            <th><?= $escape($framework->tt('provider_label')) ?></th>
                        </tr></thead>
                        <tbody><?php foreach ($transitionProjects as $project): ?>
                            <?php $state = $project['provider'];
                            $statusKey = match ((int) $project['status']) {
                                0 => 'provider_project_development', 1 => 'provider_project_production',
                                2 => empty($project['completed_time']) ? 'provider_project_analysis' : 'provider_project_completed',
                                default => 'provider_project_unknown',
                            }; ?>
                            <tr data-transition-pid="<?= $escape($project['project_id']) ?>" data-transition-state="<?= $escape(json_encode($state, JSON_THROW_ON_ERROR)) ?>">
                                <td><input type="checkbox" data-transition-select aria-label="<?= $escape($framework->tt('provider_select_project', $project['project_id'])) ?>"></td>
                                <td><?= $escape($project['project_id']) ?></td>
                                <td><?= $escape($project['app_title']) ?></td>
                                <td><?= $escape($framework->tt($statusKey)) ?></td>
                                <td data-transition-info>
                                    <div><?= $escape($providerNames[$state['provider_id']] ?? $state['provider_id']) ?></div>
                                    <div class="small text-muted"><?= $escape($framework->tt($state['identity_id'] ? 'transition_has_signer' : 'transition_no_signer')) ?></div>
                                    <?php if ($state['pending_provider_id']): ?><div class="small"><?= $framework->tt('transition_waiting_provider', $providerNames[$state['pending_provider_id']] ?? $state['pending_provider_id']) ?></div><?php endif; ?>
                                    <?php if ($state['enrollment_id'] && !$state['transition_id']): ?><div class="small text-warning"><?= $escape($framework->tt('transition_cancel_csr_first')) ?></div><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table>
                </div>
                <p class="small text-muted" id="pdf-sealer-transition-count" aria-live="polite"><?= $escape($framework->tt('provider_selection_count', 0)) ?></p>
                <p class="small text-muted"><?= $escape($framework->tt('transition_bulk_selection')) ?></p>
                <label for="transition-provider"><?= $escape($framework->tt('transition_target_label')) ?></label>
                <select class="form-select form-select-sm mb-3" id="transition-provider">
                    <option value="" selected><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
                    <?php foreach ($assignableProviders as $provider): ?><option value="<?= $escape($provider['id']) ?>"><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?></option><?php endforeach; ?>
                </select>
                <p id="transition-action-help" class="small text-muted"></p>
                <button type="submit" class="btn btn-warning btn-sm" id="transition-confirm" disabled><?= $escape($framework->tt('transition_bulk_change')) ?></button>
                <hr>
                <p class="small text-muted"><?= $escape($framework->tt('transition_cancel_help')) ?></p>
                <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" id="transition-cancel-selected" disabled><?= $escape($framework->tt('transition_bulk_cancel')) ?></button>
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
