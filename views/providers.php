<?php
// Public certificate metadata only. Private keys never enter the view or dialog templates.
?>
<div id="pdf-sealer-assignment-policy" data-required="<?= $assignmentRequired ? '1' : '0' ?>" data-projects-unavailable="<?= $assignmentProjectsUnavailable ? '1' : '0' ?>" class="mb-3">
    <p data-policy-summary class="mb-1"><?= $escape($framework->tt($assignmentRequired ? 'assignment_policy_explicit' : 'assignment_policy_automatic')) ?></p>
    <button type="button" class="btn btn-link btn-sm p-0" id="pdf-sealer-policy-change"><?= $escape($framework->tt('assignment_policy_change')) ?></button>
</div>
<hr>
<div class="pdf-sealer-table-wrap mb-3">
    <table id="pdf-sealer-providers" class="table table-sm w-100 hover">
        <thead><tr>
            <th><?= $escape($framework->tt('provider_status')) ?></th>
            <th><?= $escape($framework->tt('provider_table_name')) ?></th>
            <th><?= $escape($framework->tt('timestamp_mode_label')) ?></th>
            <th><?= $escape($framework->tt('pki_valid_until')) ?></th>
            <th><?= $escape($framework->tt('provider_table_actions')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($providerCatalog as $provider):
            $chain = array_values(array_filter($providerCertificates, static fn(array $cert): bool => $cert['provider_id'] === $provider['id']));
            $expiry = $provider['id'] === 'builtin-ca'
                ? ($certificates['root']['details']['validTo_time_t'] ?? null)
                : ($chain === [] ? null : min(array_column($chain, 'valid_until')));
            $source = $provider['timestamp_source'];
            $mode = $source === null ? $framework->tt('provider_timestamp_none')
                : ($source === 'builtin-tsa' ? $framework->tt('provider_timestamp_internal')
                    : ($sourceChoices[$source] ?? $framework->tt('external_tsa_unavailable')));
        ?>
            <tr data-provider-id="<?= $escape($provider['id']) ?>" data-retired="<?= $provider['retired'] ? '1' : '0' ?>">
                <td><span class="badge <?= $provider['retired'] ? 'bg-secondary' : 'bg-success' ?>" data-provider-status><?= $escape($framework->tt($provider['retired'] ? 'provider_retired' : 'provider_active')) ?></span></td>
                <td data-provider-name><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?></td>
                <td><?= $escape($mode) ?></td>
                <td data-order="<?= $escape($expiry ?? 0) ?>"><?= $expiry === null ? '—' : $escape(gmdate('Y-m-d H:i:s \U\T\C', $expiry)) ?></td>
                <td><button type="button" class="btn btn-link btn-sm p-0" data-provider-manage><?= $escape($framework->tt('provider_manage')) ?></button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php foreach ($providerCatalog as $provider): ?>
    <template id="pdf-sealer-provider-details-<?= $escape($provider['id']) ?>">
        <div class="pdf-sealer-dialog-body">
            <h6><?= $escape($provider['name'] ?? $framework->tt('provider_builtin')) ?> <span data-details-status></span></h6>
            <p class="small"><code><?= $escape($provider['id']) ?></code><br>
                <?= $escape($framework->tt('timestamp_mode_label')) ?>: <?= $escape($sourceChoices[$provider['timestamp_source'] ?? 'none'] ?? $framework->tt('external_tsa_unavailable')) ?><br>
                <?php foreach ($provider['timestamp_alternatives'] as $index => $alternativeId): ?>
                <?= $escape($framework->tt('timestamp_alternative_' . ($index + 1))) ?>: <?= $escape($sourceChoices[$alternativeId] ?? $framework->tt('external_tsa_unavailable')) ?><br>
                <?php endforeach; ?>
                <?php if ($provider['timestamp_source'] !== null): ?><?= $escape($framework->tt($provider['bb_fallback'] ? 'timestamp_fallback_allow' : 'timestamp_fallback_fail')) ?><?php endif; ?></p>
            <?php if ($provider['id'] === 'builtin-ca'): $renderCertificate('root'); endif; ?>
            <?php foreach ($providerCertificates as $cert): if ($cert['provider_id'] !== $provider['id']) { continue; } ?>
                <dl class="pdf-sealer-certificate">
                    <dt><?= $escape($framework->tt($cert['trust_anchor'] ? 'provider_anchor' : 'provider_intermediate')) ?></dt><dd><?= $module::certificateSubjectHtml($cert['subject']) ?></dd>
                    <dt><?= $escape($framework->tt('pki_fingerprint')) ?></dt><dd><code class="pdf-sealer-fingerprint"><?= $escape($cert['fingerprint']) ?></code></dd>
                    <dt><?= $escape($framework->tt('pki_thumbprint')) ?></dt><dd><code class="pdf-sealer-fingerprint"><?= $escape($cert['thumbprint']) ?></code></dd>
                    <dt><?= $escape($framework->tt('pki_valid_until')) ?></dt><dd><?= $escape(gmdate('Y-m-d H:i:s \U\T\C', $cert['valid_until'])) ?></dd>
                </dl>
            <?php endforeach; ?>
        </div>
    </template>
<?php endforeach; ?>
