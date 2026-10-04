<?php
// Included only by the authenticated Control Center page; public source metadata only.
if (!isset($framework) || !$framework->isSuperUser() || $framework->getProjectId() !== null) { http_response_code(403); exit; }
?>
<p class="text-muted"><?= $escape($framework->tt('tsa_overview_help')) ?></p>
<?php if ($sourcesUnavailable): ?><p class="alert alert-warning"><?= $escape($framework->tt('external_tsa_unavailable')) ?></p><?php endif; ?>
<div class="pdf-sealer-table-wrap mb-3">
<table id="pdf-sealer-tsa-sources" class="table table-sm w-100 hover">
    <thead><tr>
        <th><?= $escape($framework->tt('tsa_type')) ?></th>
        <th><?= $escape($framework->tt('provider_table_name')) ?></th>
        <th><?= $escape($framework->tt('pki_valid_until')) ?></th>
        <th><?= $escape($framework->tt('tsa_last_test')) ?></th>
        <th><?= $escape($framework->tt('provider_table_actions')) ?></th>
    </tr></thead>
    <tbody>
        <tr data-tsa-id="builtin-tsa">
            <td><?= $escape($framework->tt('provider_timestamp_internal')) ?></td>
            <td data-tsa-name><?= $escape($framework->tt('tsa_builtin_name')) ?></td>
            <?php $tsaExpiry = $certificates['tsa']['details']['validTo_time_t'] ?? null; ?>
            <td data-tsa-expiry data-order="<?= $escape($tsaExpiry ?? 0) ?>"><?= $tsaExpiry === null ? '—' : $escape(gmdate('Y-m-d H:i:s \U\T\C', $tsaExpiry)) ?></td>
            <td><a href="#diagnostic"><?= $escape($framework->tt('pki_tab_diagnostic')) ?></a></td>
            <td><button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-tsa-manage><?= $escape($framework->tt('provider_manage')) ?></button></td>
        </tr>
        <?php foreach ($sourceSummaries as $source): $tsaExpiry = $source['diagnostic']['valid_until'] ?? null; ?>
        <tr data-tsa-id="<?= $escape($source['id']) ?>">
            <td><?= $escape($framework->tt('tsa_external_type')) ?></td>
            <td data-tsa-name><?= $escape($source['name']) ?></td>
            <td data-tsa-expiry data-order="<?= $escape($tsaExpiry ?? 0) ?>"><?= $tsaExpiry === null ? '—' : $escape(gmdate('Y-m-d H:i:s \U\T\C', $tsaExpiry)) ?></td>
            <td data-tsa-last-test data-order="<?= $escape($source['diagnostic']['checked_at'] ?? 0) ?>"></td>
            <td><button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-tsa-manage><?= $escape($framework->tt('provider_manage')) ?></button></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<h5><?= $escape($framework->tt('pki_admin_workflows')) ?></h5>
<button type="button" id="pdf-sealer-tsa-register" class="btn btn-link btn-sm pdf-sealer-workflow-link" <?= $sourcesUnavailable ? 'disabled' : '' ?>><?= $escape($framework->tt('external_tsa_register')) ?></button>
<p class="small text-muted"><?= $escape($framework->tt('tsa_register_intro')) ?></p>
<template id="pdf-sealer-builtin-tsa-details">
    <div class="pdf-sealer-dialog-body">
        <p class="text-muted"><?= $escape($framework->tt('pki_tsa_help')) ?></p>
        <?php $renderCertificate('tsa'); ?>
        <h6><?= $escape($framework->tt('pki_admin_workflows')) ?></h6>
        <button type="button" data-tsa-lifecycle class="btn btn-link btn-sm pdf-sealer-workflow-link"><?= $escape($framework->tt('tsa_lifecycle_title')) ?></button>
        <p class="small text-muted"><?= $escape($framework->tt('tsa_lifecycle_intro')) ?></p>
    </div>
</template>
<div id="pdf-sealer-tsa-register-host" hidden>
<div class="pdf-sealer-dialog-body">
<form id="tsa-register" autocomplete="off">
<fieldset <?= $sourcesUnavailable ? 'disabled' : '' ?>>
    <?php foreach (['name' => 'text', 'endpoint' => 'url', 'policy' => 'text', 'username' => 'text', 'password' => 'password'] as $field => $type): ?>
    <label for="tsa-<?= $field ?>"><?= $escape($framework->tt('external_tsa_' . $field)) ?></label>
    <input id="tsa-<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" class="form-control form-control-sm mb-2"
        maxlength="<?= ['name' => 128, 'endpoint' => 2048, 'policy' => 256, 'username' => 256, 'password' => 4096][$field] ?>"
        <?= in_array($field, ['name', 'endpoint'], true) ? 'required' : '' ?> autocomplete="<?= $field === 'password' ? 'new-password' : 'off' ?>">
    <?php endforeach; ?>
    <label for="tsa-pem"><?= $escape($framework->tt('external_tsa_chain')) ?></label>
    <textarea id="tsa-pem" name="pem" class="form-control form-control-sm mb-2" rows="5" maxlength="131072" required></textarea>
    <p class="small text-muted"><?= $escape($framework->tt('external_tsa_registration_help')) ?></p>
</fieldset>
</form>
</div>
</div>
<template id="pdf-sealer-timestamp-policy">
<div class="pdf-sealer-dialog-body">
<p><?= $escape($framework->tt('timestamp_settings_scope')) ?></p>
<fieldset <?= $providersUnavailable || $sourcesUnavailable ? 'disabled' : '' ?>>
    <label for="tsa-source"><?= $escape($framework->tt('timestamp_primary_source')) ?></label>
    <select id="tsa-source" data-timestamp-source class="form-select form-select-sm mb-3" required>
        <?php foreach ($sourceChoices as $id => $name): ?><option value="<?= $escape($id) ?>"><?= $escape($name) ?></option><?php endforeach; ?>
    </select>
    <?php foreach ([1, 2] as $position): ?>
    <label for="tsa-alternative-<?= $position ?>"><?= $escape($framework->tt('timestamp_alternative_' . $position)) ?></label>
    <select id="tsa-alternative-<?= $position ?>" data-timestamp-alternative class="form-select form-select-sm mb-3">
        <option value=""><?= $escape($framework->tt('timestamp_alternative_none')) ?></option>
        <?php foreach ($sourceChoices as $id => $name): ?>
        <?php if ($id !== 'none'): ?><option value="<?= $escape($id) ?>"><?= $escape($name) ?></option><?php endif; ?>
        <?php endforeach; ?>
    </select>
    <?php endforeach; ?>
    <label class="mb-3"><input type="checkbox" data-timestamp-fallback> <?= $escape($framework->tt('timestamp_fallback_allow')) ?></label>
    <p class="small text-muted"><?= $escape($framework->tt('external_tsa_fallback_help')) ?></p>
    <p><?= $escape($framework->tt('timestamp_failure_help')) ?></p>
    <button type="button" data-timestamp-save class="btn btn-primaryrc btn-sm" <?= $providersUnavailable || $sourcesUnavailable ? 'disabled' : '' ?>><?= $escape($framework->tt('timestamp_settings_save')) ?></button>
</fieldset>
</div>
</template>
