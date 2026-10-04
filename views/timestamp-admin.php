<?php
// Included only by the authenticated Control Center page.
if (!isset($framework) || !$framework->isSuperUser() || $framework->getProjectId() !== null) { http_response_code(403); exit; }
?>
<h5><?= $escape($framework->tt('external_tsa_title')) ?></h5>
<p><?= $escape($framework->tt('external_tsa_help')) ?></p>
<?php if ($sourcesUnavailable): ?><p class="alert alert-warning"><?= $escape($framework->tt('external_tsa_unavailable')) ?></p><?php endif; ?>
<?php foreach ($sourceSummaries as $source): ?>
<div class="pdf-sealer-card mb-3" data-tsa-card="<?= $escape($source['id']) ?>">
    <h6><?= $escape($source['name']) ?></h6>
    <p><code><?= $escape($source['id']) ?></code><br>
        <?= $escape($framework->tt('external_tsa_policy')) ?>: <?= $escape($source['policy_oid'] ?: $framework->tt('external_tsa_default_policy')) ?><br>
        <?= $escape($framework->tt($source['authenticated'] ? 'external_tsa_basic' : 'external_tsa_anonymous')) ?></p>
    <p data-tsa-observation class="small" role="status"></p>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-tsa-test="<?= $escape($source['id']) ?>"><?= $escape($framework->tt('external_tsa_test')) ?></button>
</div>
<?php endforeach; ?>
<form id="tsa-register" class="pdf-sealer-card mb-3" autocomplete="off">
<fieldset <?= $sourcesUnavailable ? 'disabled' : '' ?>>
    <h6><?= $escape($framework->tt('external_tsa_register')) ?></h6>
    <?php foreach (['name' => 'text', 'endpoint' => 'url', 'policy' => 'text', 'username' => 'text', 'password' => 'password'] as $field => $type): ?>
    <label for="tsa-<?= $field ?>"><?= $escape($framework->tt('external_tsa_' . $field)) ?></label>
    <input id="tsa-<?= $field ?>" name="<?= $field ?>" type="<?= $type ?>" class="form-control form-control-sm mb-2"
        maxlength="<?= ['name' => 128, 'endpoint' => 2048, 'policy' => 256, 'username' => 256, 'password' => 4096][$field] ?>"
        <?= in_array($field, ['name', 'endpoint'], true) ? 'required' : '' ?> autocomplete="<?= $field === 'password' ? 'new-password' : 'off' ?>">
    <?php endforeach; ?>
    <label for="tsa-pem"><?= $escape($framework->tt('external_tsa_chain')) ?></label>
    <textarea id="tsa-pem" name="pem" class="form-control form-control-sm mb-2" rows="5" maxlength="131072" required></textarea>
    <p class="small text-muted"><?= $escape($framework->tt('external_tsa_registration_help')) ?></p>
    <button type="submit" class="btn btn-primaryrc btn-sm"><?= $escape($framework->tt('external_tsa_register')) ?></button>
</fieldset>
</form>
<hr>
<h5><?= $escape($framework->tt('timestamp_settings_title')) ?></h5>
<p><?= $escape($framework->tt('timestamp_settings_scope')) ?></p>
<div id="tsa-policy">
<fieldset <?= $providersUnavailable || $sourcesUnavailable ? 'disabled' : '' ?>>
    <label for="tsa-provider"><?= $escape($framework->tt('provider_label')) ?></label>
    <select id="tsa-provider" class="form-select form-select-sm mb-3" required>
        <option value=""><?= $escape($framework->tt('timestamp_settings_choose')) ?></option>
        <?php foreach ($providerCatalog as $provider): ?><option value="<?= $escape($provider['id']) ?>"><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?></option><?php endforeach; ?>
    </select>
    <label for="tsa-source"><?= $escape($framework->tt('timestamp_primary_source')) ?></label>
    <select id="tsa-source" class="form-select form-select-sm mb-3" required>
        <?php foreach ($sourceChoices as $id => $name): ?><option value="<?= $escape($id) ?>"><?= $escape($name) ?></option><?php endforeach; ?>
    </select>
    <?php foreach ([1, 2] as $position): ?>
    <label for="tsa-alternative-<?= $position ?>"><?= $escape($framework->tt('timestamp_alternative_' . $position)) ?></label>
    <select id="tsa-alternative-<?= $position ?>" class="form-select form-select-sm mb-3">
        <option value=""><?= $escape($framework->tt('timestamp_alternative_none')) ?></option>
        <?php foreach ($sourceChoices as $id => $name): ?>
        <?php if ($id !== 'none'): ?><option value="<?= $escape($id) ?>"><?= $escape($name) ?></option><?php endif; ?>
        <?php endforeach; ?>
    </select>
    <?php endforeach; ?>
    <label class="mb-3"><input type="checkbox" id="tsa-fallback"> <?= $escape($framework->tt('timestamp_fallback_allow')) ?></label>
    <p class="small text-muted"><?= $escape($framework->tt('external_tsa_fallback_help')) ?></p>
    <p><?= $escape($framework->tt('timestamp_failure_help')) ?></p>
    <button type="button" id="tsa-policy-save" class="btn btn-primaryrc btn-sm" <?= $providersUnavailable || $sourcesUnavailable ? 'disabled' : '' ?>><?= $escape($framework->tt('timestamp_settings_save')) ?></button>
</fieldset>
</div>
