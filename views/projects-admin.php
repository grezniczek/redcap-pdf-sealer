<?php
/** Shared CC project selection; dialogs contain only action settings and reviewed public details. */
$projectProviders = array_map(static fn(array $p): array => array_intersect_key($p, array_flip(['id', 'name', 'kind', 'retired'])) + ['name' => $framework->tt('provider_builtin')], $providerCatalog);
?>
<div id="pdf-sealer-project-admin" data-unavailable="<?= $projectsUnavailable ? '1' : '0' ?>"
     data-projects="<?= $escape(json_encode($projectOverview, JSON_THROW_ON_ERROR)) ?>"
     data-providers="<?= $escape(json_encode($projectProviders, JSON_THROW_ON_ERROR)) ?>">
    <h5><?= $escape($framework->tt('projects_title')) ?></h5>
    <p class="small text-muted"><?= $escape($framework->tt('projects_intro')) ?></p>
    <?php if ($projectsUnavailable): ?><p class="text-danger"><?= $escape($framework->tt('projects_refresh_failed')) ?></p><?php endif; ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <label for="pdf-sealer-project-filter"><?= $escape($framework->tt('projects_filter')) ?></label>
        <select id="pdf-sealer-project-filter" class="form-select form-select-sm w-auto">
            <?php foreach (['all', 'unassigned', 'csr', 'transition', 'builtin', 'external'] as $filter): ?>
                <option value="<?= $filter ?>"><?= $escape($framework->tt('projects_filter_' . $filter)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-link btn-sm" id="pdf-sealer-project-refresh"><?= $escape($framework->tt('projects_refresh')) ?></button>
    </div>
    <div class="pdf-sealer-table-wrap">
        <table id="pdf-sealer-projects" class="table table-sm hover w-100">
            <thead><tr><?php foreach (['projects_select', 'provider_project_pid', 'projects_name', 'provider_project_status', 'projects_provider', 'projects_certificate'] as $key): ?><th><?= $escape($framework->tt($key)) ?></th><?php endforeach; ?></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <p class="small mb-1" id="pdf-sealer-project-selection" aria-live="polite"></p>
    <p class="small text-muted"><?= $escape($framework->tt('projects_selection_help')) ?></p>
    <hr>
    <h5><?= $escape($framework->tt('pki_admin_workflows')) ?></h5>
    <?php foreach (['assign' => ['provider_assign', 'provider_assign_summary'], 'change' => ['transition_title', 'provider_transition_summary'],
        'cancel' => ['transition_cancel', 'projects_cancel_summary'], 'renew' => ['renewal_title', 'provider_renewal_summary'],
        'revoke' => ['revocation_title', 'provider_revocation_summary']] as $action => [$label, $description]): ?>
        <div class="mb-2">
            <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-project-action="<?= $action ?>" disabled
                    aria-haspopup="dialog" aria-describedby="project-action-<?= $action ?>-help"><?= $escape($framework->tt($label)) ?></button>
            <p class="small text-muted"><?= $escape($framework->tt($description)) ?></p>
            <p class="small text-muted mb-0" id="project-action-<?= $action ?>-help" data-project-action-help="<?= $action ?>"></p>
        </div>
    <?php endforeach; ?>
</div>
