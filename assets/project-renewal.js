/* Explicit Control Center renewal; the server rechecks the review under both PKI locks. */
window.PDFSealerProjectRenewal = module => {
    const form = document.getElementById('pdf-sealer-renewal');
    if (!form) return;
    const fields = form.querySelector('fieldset');
    const project = $('#renewal-pid');
    project.prop('disabled', fields.disabled).select2({width: '100%', minimumResultsForSearch: 0});
    const review = document.getElementById('renewal-review');
    const confirm = document.getElementById('renewal-confirm');
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
        message.textContent = module.tt('renewal_failed');
        message.className = 'alert alert-danger mt-3';
        message.hidden = false;
    };
    const utc = seconds => new Date(seconds * 1000).toISOString().replace('T', ' ').replace('.000Z', ' UTC');
    project.on('change', () => { preview = null; review.hidden = true; message.hidden = true; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (fields.disabled || !project.val()) return;
        preview = null;
        review.hidden = true;
        message.hidden = true;
        busy(true);
        try {
            const result = await module.ajax('preview_project_renewal', {pid: Number(project.val())});
            if (!result?.ok) throw new Error('Renewal review failed');
            document.getElementById('renewal-subject').textContent = result.certificate.subject.replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n').trim();
            document.getElementById('renewal-valid-from').textContent = utc(result.certificate.valid_from);
            document.getElementById('renewal-valid-until').textContent = utc(result.certificate.valid_until);
            document.getElementById('renewal-fingerprint').textContent = result.certificate.fingerprint;
            document.getElementById('renewal-thumbprint').textContent = result.certificate.thumbprint;
            document.getElementById('renewal-issuer-expiry').textContent = module.tt('renewal_issuer_expiry', utc(result.issuer_valid_until));
            document.getElementById('renewal-expired').hidden = result.certificate.valid_until >= Date.now() / 1000;
            preview = result;
            review.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
    confirm.addEventListener('click', async () => {
        if (fields.disabled || !preview) return;
        const projectName = project[0].selectedOptions[0].textContent;
        const payload = {pid: preview.pid, review_hash: preview.review_hash};
        busy(true);
        message.hidden = true;
        try {
            const result = await module.ajax('renew_project_certificate', payload);
            if (!result?.ok) throw new Error('Renewal failed');
            form.reset();
            project.trigger('change');
            message.textContent = module.tt('renewal_saved', projectName, result.fingerprint);
            message.className = 'alert alert-success mt-3';
            message.style.overflowWrap = 'anywhere';
            message.hidden = false;
        } catch (_) { fail(); }
        finally { busy(false); }
    });
};
