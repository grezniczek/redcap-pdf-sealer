<?php
/** CC entry point; the read-only review and confirmation are rendered by rcDialog. */
?>
<h5><?= $escape($framework->tt('pki_admin_workflows')) ?></h5>
<button type="button" id="pdf-sealer-root-lifecycle" class="btn btn-link btn-sm pdf-sealer-workflow-link" aria-haspopup="dialog">
    <?= $escape($framework->tt('root_lifecycle_title')) ?>
</button>
<p class="small text-muted"><?= $escape($framework->tt('root_lifecycle_intro')) ?></p>
