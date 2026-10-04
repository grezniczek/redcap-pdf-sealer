<?php // External CA registration remains on the provider administration tab. ?>
<hr>
<h5><?= $escape($framework->tt('pki_admin_workflows')) ?></h5>
<div class="mb-1">
    <button type="button" class="btn btn-link btn-sm pdf-sealer-workflow-link" data-provider-workflow="register" aria-haspopup="dialog" aria-describedby="provider-workflow-register-help"><?= $escape($framework->tt('provider_register')) ?></button>
    <p id="provider-workflow-register-help" class="small text-muted"><?= $escape($framework->tt('provider_register_summary')) ?></p>
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
