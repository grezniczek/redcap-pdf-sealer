<?php
/** Shared installation notice; certificate management does not depend on sealing support. */
if (!$sealingSupport['supported']):
    $missingSupport = [];
    foreach (['core', 'framework'] as $component) {
        if (!$sealingSupport[$component]) { $missingSupport[] = $framework->tt('sealing_support_' . $component); }
    }
?>
<div class="alert alert-warning" role="status">
    <strong><?= $escape($framework->tt('sealing_support_unavailable')) ?></strong>
    <p class="mt-2"><?= $escape($framework->tt('sealing_support_help')) ?></p>
    <p class="mb-0"><?= $escape($framework->tt('sealing_support_missing')) ?> <?= $escape(implode(', ', $missingSupport)) ?></p>
</div>
<?php endif; ?>
