/* Confirmed CC revocation; block acceptance is distinct from publication/replacement completion. */
window.PDFSealerProjectRevocation = module => {
    const form = document.getElementById('pdf-sealer-revocation');
    if (!form) return;
    const fields = form.querySelector('fieldset');
    const project = $('#revocation-pid');
    project.prop('disabled', fields.disabled).select2({width: '100%', minimumResultsForSearch: 0});
    const review = document.getElementById('revocation-review');
    const choice = document.getElementById('revocation-choice');
    const reason = document.getElementById('revocation-reason');
    const message = form.querySelector('[role="status"]');
    let preview = null;
    const busy = value => {
        fields.disabled = value;
        project.prop('disabled', value);
        form.setAttribute('aria-busy', String(value));
    };
    const fail = () => {
        preview = null;
        review.hidden = true;
        message.textContent = module.tt('revocation_failed');
        message.className = 'alert alert-danger mt-3';
        message.hidden = false;
    };
    project.on('change', () => { preview = null; review.hidden = true; message.hidden = true; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (fields.disabled || !project.val()) return;
        preview = null;
        review.hidden = true;
        message.hidden = true;
        busy(true);
        try {
            const result = await module.ajax('preview_project_revocation', {pid: Number(project.val())});
            if (!result?.ok) throw new Error('Revocation review failed');
            document.getElementById('revocation-subject').textContent = result.certificate.subject.replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n').trim();
            document.getElementById('revocation-fingerprint').textContent = result.certificate.fingerprint;
            document.getElementById('revocation-already').hidden = !result.revoked;
            choice.hidden = result.revoked;
            reason.value = 'superseded';
            preview = result;
            review.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
    document.getElementById('revocation-confirm').addEventListener('click', async () => {
        if (fields.disabled || !preview || preview.revoked) return;
        const projectName = project[0].selectedOptions[0].textContent;
        const selectedReason = reason.selectedOptions[0].textContent;
        if (!window.confirm(module.tt('revocation_confirm_prompt', projectName, selectedReason, preview.certificate.fingerprint))) return;
        const payload = {pid: preview.pid, review_hash: preview.review_hash, reason: reason.value};
        busy(true);
        message.hidden = true;
        try {
            const result = await module.ajax('revoke_project_certificate', payload);
            if (!result?.ok) throw new Error('Revocation failed');
            form.reset();
            project.trigger('change');
            message.textContent = [
                module.tt('revocation_saved', projectName),
                module.tt(result.crl_published ? 'revocation_crl_published' : 'revocation_crl_pending'),
                module.tt(result.replacement === 'renewed' ? 'revocation_replaced'
                    : (result.replacement === 'skipped' ? 'revocation_signer_changed' : 'revocation_replacement_pending')),
            ].join(' ');
            message.className = 'alert mt-3 ' + (result.crl_published && result.replacement === 'renewed' ? 'alert-success' : 'alert-warning');
            message.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
};
