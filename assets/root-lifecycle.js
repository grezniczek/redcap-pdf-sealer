/* Current built-in Root CA only; distinguish an accepted block from completed recovery. */
window.PDFSealerRootLifecycle = module => {
    const form = document.getElementById('pdf-sealer-root-lifecycle');
    if (!form) return;
    const fields = form.querySelector('fieldset');
    const review = document.getElementById('root-lifecycle-review');
    const action = document.getElementById('root-lifecycle-action');
    const help = document.getElementById('root-lifecycle-help');
    const confirm = document.getElementById('root-lifecycle-confirm');
    const message = form.querySelector('[role="status"]');
    let preview = null;
    const update = () => {
        help.textContent = module.tt('root_lifecycle_' + action.value + '_help');
        help.className = 'alert ' + (action.value === 'renew' ? 'alert-info' : 'alert-warning');
        confirm.className = 'btn btn-sm ' + (action.value === 'renew' ? 'btn-warning' : 'btn-danger');
    };
    const busy = value => { fields.disabled = value; form.setAttribute('aria-busy', String(value)); };
    const fail = () => {
        preview = null;
        review.hidden = true;
        message.textContent = module.tt('root_lifecycle_failed');
        message.className = 'alert alert-danger mt-3';
        message.hidden = false;
    };
    action.addEventListener('change', update);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (fields.disabled) return;
        preview = null;
        review.hidden = true;
        message.hidden = true;
        busy(true);
        try {
            const result = await module.ajax('preview_root_lifecycle', {});
            if (!result?.ok) throw new Error('Root CA review failed');
            document.getElementById('root-lifecycle-subject').textContent = result.certificate.subject.replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n').trim();
            document.getElementById('root-lifecycle-fingerprint').textContent = result.certificate.fingerprint;
            document.getElementById('root-lifecycle-thumbprint').textContent = result.certificate.thumbprint;
            document.getElementById('root-lifecycle-already').hidden = !result.revoked;
            [...action.options].forEach(option => { option.disabled = result.revoked; });
            confirm.disabled = result.revoked;
            document.getElementById('root-lifecycle-dependents').textContent = module.tt('root_lifecycle_dependents', result.known_dependent_certificates);
            action.value = 'renew';
            update();
            preview = result;
            review.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
    confirm.addEventListener('click', async () => {
        if (fields.disabled || !preview || preview.revoked) return;
        const selected = action.value;
        if (!window.confirm(module.tt('root_lifecycle_confirm_prompt', action.selectedOptions[0].textContent,
            preview.certificate.fingerprint, help.textContent))) return;
        busy(true);
        message.hidden = true;
        try {
            const result = await module.ajax(selected === 'renew' ? 'renew_root_certificate' : 'revoke_root_certificate',
                {review_hash: preview.review_hash, reason: selected});
            if (!result?.ok) throw new Error('Root CA action failed');
            preview = null;
            review.hidden = true;
            const details = [];
            if (selected !== 'renew') {
                details.push(module.tt('root_lifecycle_saved'));
                details.push(module.tt(result.crl_published ? 'revocation_crl_published' : 'revocation_crl_pending'));
            }
            details.push(module.tt(result.replacement === 'renewed'
                ? (selected === 'renew' ? 'root_lifecycle_renewed' : 'root_lifecycle_replaced') : 'root_lifecycle_pending'));
            if (selected !== 'renew') {
                details.push(module.tt('root_lifecycle_trust'));
                details.push(module.tt(result.maintenance_status === 'ok' ? 'root_lifecycle_projects_done' : 'root_lifecycle_projects_pending'));
            }
            message.textContent = details.join(' ');
            message.className = 'alert mt-3 ' + (selected === 'renew' && result.replacement === 'renewed' ? 'alert-success' : 'alert-warning');
            message.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
};
