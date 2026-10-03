/* Current built-in TSA only; distinguish an accepted block from completed recovery. */
window.PDFSealerTsaLifecycle = module => {
    const form = document.getElementById('pdf-sealer-tsa-lifecycle');
    if (!form) return;
    const fields = form.querySelector('fieldset');
    const review = document.getElementById('tsa-lifecycle-review');
    const action = document.getElementById('tsa-lifecycle-action');
    const help = document.getElementById('tsa-lifecycle-help');
    const confirm = document.getElementById('tsa-lifecycle-confirm');
    const message = form.querySelector('[role="status"]');
    let preview = null;
    const update = () => {
        help.textContent = module.tt('tsa_lifecycle_' + action.value + '_help');
        help.className = 'alert ' + (action.value === 'replace' ? 'alert-info' : 'alert-warning');
        confirm.className = 'btn btn-sm ' + (action.value === 'replace' ? 'btn-warning' : 'btn-danger');
    };
    const busy = value => { fields.disabled = value; form.setAttribute('aria-busy', String(value)); };
    const fail = () => {
        preview = null;
        review.hidden = true;
        message.textContent = module.tt('tsa_lifecycle_failed');
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
            const result = await module.ajax('preview_tsa_lifecycle', {});
            if (!result?.ok) throw new Error('TSA review failed');
            document.getElementById('tsa-lifecycle-subject').textContent = result.certificate.subject.replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n').trim();
            document.getElementById('tsa-lifecycle-fingerprint').textContent = result.certificate.fingerprint;
            document.getElementById('tsa-lifecycle-thumbprint').textContent = result.certificate.thumbprint;
            document.getElementById('tsa-lifecycle-already').hidden = !result.revoked;
            [...action.options].forEach(option => { option.disabled = result.revoked && option.value !== 'replace'; });
            action.value = 'replace';
            update();
            preview = result;
            review.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
    confirm.addEventListener('click', async () => {
        if (fields.disabled || !preview || (preview.revoked && action.value !== 'replace')) return;
        const selected = action.value;
        if (!window.confirm(module.tt('tsa_lifecycle_confirm_prompt', action.selectedOptions[0].textContent,
            preview.certificate.fingerprint, help.textContent))) return;
        busy(true);
        message.hidden = true;
        try {
            const result = await module.ajax(selected === 'replace' ? 'replace_tsa_certificate' : 'revoke_tsa_certificate',
                {review_hash: preview.review_hash, reason: selected});
            if (!result?.ok) throw new Error('TSA action failed');
            preview = null;
            review.hidden = true;
            const details = [];
            if (selected !== 'replace') {
                details.push(module.tt('tsa_lifecycle_saved'));
                details.push(module.tt(result.crl_published ? 'revocation_crl_published' : 'revocation_crl_pending'));
            }
            details.push(module.tt(result.replacement === 'renewed' ? 'tsa_lifecycle_replaced'
                : (result.replacement === 'skipped' ? 'tsa_lifecycle_changed' : 'tsa_lifecycle_pending')));
            message.textContent = details.join(' ');
            message.className = 'alert mt-3 ' + (result.replacement === 'renewed' && result.crl_published !== false ? 'alert-success' : 'alert-warning');
            message.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
};
