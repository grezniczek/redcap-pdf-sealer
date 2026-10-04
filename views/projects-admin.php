<?php
/** Shared CC project selection; dialogs contain only action settings and reviewed public details. */
$projectProviders = array_map(static fn(array $p): array => array_intersect_key($p, array_flip(['id', 'name', 'kind', 'retired'])) + ['name' => $framework->tt('provider_builtin')], $providerCatalog);
?>
<div id="pdf-sealer-project-admin" data-status-url="<?= $escape($framework->getUrl('project-status.php')) ?>" data-unavailable="<?= $projectsUnavailable ? '1' : '0' ?>"
     data-projects="<?= $escape(json_encode($projectOverview, JSON_THROW_ON_ERROR)) ?>"
     data-providers="<?= $escape(json_encode($projectProviders, JSON_THROW_ON_ERROR)) ?>">
    <h5><?= $escape($framework->tt('projects_title')) ?></h5>
    <p class="small text-muted"><?= $escape($framework->tt('projects_intro')) ?></p>
    <?php if ($projectsUnavailable): ?><p class="text-danger"><?= $escape($framework->tt('projects_refresh_failed')) ?></p><?php endif; ?>
    <div id="pdf-sealer-project-controls" class="d-flex align-items-center gap-2">
        <select id="pdf-sealer-project-filter" class="form-select form-select-sm w-auto" aria-label="<?= $escape($framework->tt('projects_filter')) ?>">
            <?php foreach (['all', 'unassigned', 'csr', 'transition', 'builtin', 'external'] as $filter): ?>
                <option value="<?= $filter ?>"><?= $escape($framework->tt('projects_filter_' . $filter)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-link btn-xs p-0" id="pdf-sealer-project-refresh" title="<?= $escape($framework->tt('projects_refresh')) ?>" aria-label="<?= $escape($framework->tt('projects_refresh')) ?>"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
    </div>
    <div class="pdf-sealer-table-wrap">
        <table id="pdf-sealer-projects" class="table table-sm hover w-100">
            <colgroup>
                <col class="pdf-sealer-col-select"><col class="pdf-sealer-col-pid"><col>
                <col class="pdf-sealer-col-status"><col><col class="pdf-sealer-col-certificate">
            </colgroup>
            <thead><tr>
                <th><input type="checkbox" id="pdf-sealer-project-select-page" title="<?= $escape($framework->tt('projects_select_page')) ?>" aria-label="<?= $escape($framework->tt('projects_select_page')) ?>"></th>
                <th><?= $escape($framework->tt('provider_project_pid')) ?></th>
                <th><?= $escape($framework->tt('projects_name')) ?></th>
                <th aria-label="<?= $escape($framework->tt('provider_project_status')) ?>"></th>
                <th><?= $escape($framework->tt('projects_provider')) ?></th>
                <th><?= $escape($framework->tt('projects_certificate')) ?></th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <p class="small mb-1" id="pdf-sealer-project-selection" aria-live="polite"></p>
    <p class="small text-muted"><?= $escape($framework->tt('projects_selection_help')) ?></p>
    <hr>
    <h5><?= $escape($framework->tt('pki_admin_workflows')) ?></h5>
    <p class="small text-muted"><?= $escape($framework->tt('projects_workflow_eligibility')) ?></p>
    <?php foreach (['assign' => ['provider_assign', 'provider_assign_summary'], 'change' => ['transition_title', 'provider_transition_summary'],
        'cancel' => ['transition_cancel', 'projects_cancel_summary'], 'renew' => ['renewal_title', 'provider_renewal_summary'],
        'revoke' => ['revocation_title', 'provider_revocation_summary']] as $action => [$label, $description]): ?>
        <div class="mb-2">
            <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-project-action="<?= $action ?>" disabled
                    aria-haspopup="dialog"><?= $escape($framework->tt($label)) ?></button>
            <p class="small text-muted"><?= $escape($framework->tt($description)) ?></p>
        </div>
    <?php endforeach; ?>
</div>
