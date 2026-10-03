<?php
/** CC review of the current built-in Root CA; no private key is rendered. */
?>
<h5><?= $escape($framework->tt('root_lifecycle_title')) ?></h5>
<p class="small text-muted"><?= $escape($framework->tt('root_lifecycle_intro')) ?></p>
<form id="pdf-sealer-root-lifecycle">
    <fieldset>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><?= $escape($framework->tt('root_lifecycle_review')) ?></button>
        <div id="root-lifecycle-review" class="mt-3" hidden>
            <dl class="pdf-sealer-certificate">
                <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd id="root-lifecycle-subject" style="white-space: pre-line"></dd>
                <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code class="pdf-sealer-fingerprint" id="root-lifecycle-fingerprint"></code></dd>
            </dl>
            <p id="root-lifecycle-dependents" class="small text-muted"></p>
            <p id="root-lifecycle-already" class="alert alert-warning" hidden><?= $escape($framework->tt('root_lifecycle_already')) ?></p>
            <label for="root-lifecycle-action"><?= $escape($framework->tt('root_lifecycle_action')) ?></label>
            <select class="form-select form-select-sm mb-3" id="root-lifecycle-action">
                <option value="renew"><?= $escape($framework->tt('root_lifecycle_renew')) ?></option>
                <option value="superseded"><?= $escape($framework->tt('root_lifecycle_superseded')) ?></option>
                <option value="compromise"><?= $escape($framework->tt('root_lifecycle_compromise')) ?></option>
            </select>
            <p id="root-lifecycle-help" class="alert alert-info"></p>
            <button type="button" class="btn btn-warning btn-sm" id="root-lifecycle-confirm"><?= $escape($framework->tt('root_lifecycle_confirm')) ?></button>
        </div>
    </fieldset>
    <p class="alert mt-3" role="status" hidden></p>
</form>
