<?php
/** CC review of the current internal TSA; no private key is rendered. */
?>
<h5><?= $escape($framework->tt('tsa_lifecycle_title')) ?></h5>
<p class="small text-muted"><?= $escape($framework->tt('tsa_lifecycle_intro')) ?></p>
<form id="pdf-sealer-tsa-lifecycle">
    <fieldset>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><?= $escape($framework->tt('tsa_lifecycle_review')) ?></button>
        <div id="tsa-lifecycle-review" class="mt-3" hidden>
            <dl class="pdf-sealer-certificate">
                <dt><?= $escape($framework->tt('pki_subject')) ?></dt><dd id="tsa-lifecycle-subject" style="white-space: pre-line"></dd>
                <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code class="pdf-sealer-fingerprint" id="tsa-lifecycle-fingerprint"></code></dd>
            </dl>
            <p id="tsa-lifecycle-already" class="alert alert-warning" hidden><?= $escape($framework->tt('tsa_lifecycle_already')) ?></p>
            <label for="tsa-lifecycle-action"><?= $escape($framework->tt('tsa_lifecycle_action')) ?></label>
            <select class="form-select form-select-sm mb-3" id="tsa-lifecycle-action">
                <option value="replace"><?= $escape($framework->tt('tsa_lifecycle_replace')) ?></option>
                <option value="superseded"><?= $escape($framework->tt('tsa_lifecycle_superseded')) ?></option>
                <option value="compromise"><?= $escape($framework->tt('tsa_lifecycle_compromise')) ?></option>
            </select>
            <p id="tsa-lifecycle-help" class="alert alert-info"></p>
            <button type="button" class="btn btn-warning btn-sm" id="tsa-lifecycle-confirm"><?= $escape($framework->tt('tsa_lifecycle_confirm')) ?></button>
        </div>
    </fieldset>
    <p class="alert mt-3" role="status" hidden></p>
</form>
