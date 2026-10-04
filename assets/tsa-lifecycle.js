/* Fresh locked review of the current built-in TSA; never replay an ambiguous mutation. */
window.PDFSealerTsaLifecycle = async (module, launcher) => {
    const element = (tag, className, value) => {
        const node = document.createElement(tag); node.className = className;
        if (value !== undefined) node.textContent = value;
        return node;
    };
    const preview = await module.ajax('preview_tsa_lifecycle', {});
    if (!preview?.ok) throw new Error('TSA review failed');
    let busy = false, invalid = false, selected = 'replace', fields;
    const receipt = response => {
        const details = [];
        if (selected !== 'replace') {
            details.push(module.tt('tsa_lifecycle_saved'));
            details.push(module.tt(response.crl_published ? 'revocation_crl_published' : 'revocation_crl_pending'));
        }
        details.push(module.tt(response.replacement === 'renewed' ? 'tsa_lifecycle_replaced'
            : (response.replacement === 'skipped' ? 'tsa_lifecycle_changed' : 'tsa_lifecycle_pending')));
        return {text: details.join(' '), tone: response.replacement === 'renewed' && response.crl_published !== false ? 'success' : 'warning'};
    };
    return window.rcDialog({title: module.tt('tsa_lifecycle_title'), size: 'lg', draggable: true,
        closeButton: 'cancel', focusAfterClose: launcher,
        buttons: ['cancel', {id: 'confirm', label: module.tt('root_lifecycle_confirm_button'), intent: 'warning'}],
        body(ctx) {
            const body = element('div', 'pdf-sealer-dialog-body');
            body.append(element('h6', '', module.tt('tsa_lifecycle_review')));
            const list = element('dl', 'pdf-sealer-certificate');
            [['pki_subject', preview.certificate.subject], ['pki_fingerprint', preview.certificate.fingerprint],
                ['pki_thumbprint', preview.certificate.thumbprint]].forEach(([key, value]) => {
                const description = element('dd', '');
                if (key === 'pki_subject') {
                    description.style.whiteSpace = 'pre-line'; description.textContent = value.replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n').trim();
                } else description.append(element('code', 'pdf-sealer-fingerprint', value));
                list.append(element('dt', '', module.tt(key)), description);
            });
            body.append(list);
            if (preview.revoked) body.append(element('p', 'alert alert-warning', module.tt('tsa_lifecycle_already')));
            fields = element('fieldset', ''); fields.append(element('legend', 'h6', module.tt('tsa_lifecycle_action')));
            ['replace', 'superseded', 'compromise'].forEach(reason => {
                const option = element('div', 'form-check mb-3'), radio = element('input', 'form-check-input');
                radio.type = 'radio'; radio.name = 'tsa-lifecycle-action'; radio.value = reason;
                radio.id = 'tsa-lifecycle-' + reason; radio.checked = reason === selected;
                radio.disabled = preview.revoked && reason !== 'replace';
                const label = element('label', 'form-check-label', module.tt('tsa_lifecycle_' + reason)); label.htmlFor = radio.id;
                const help = element('div', 'small text-muted', module.tt('tsa_lifecycle_' + reason + '_help'));
                help.id = radio.id + '-help'; radio.setAttribute('aria-describedby', help.id);
                radio.addEventListener('change', () => {
                    if (fields.disabled || radio.disabled) return;
                    selected = reason; ctx.buttons.update('confirm', {intent: reason === 'replace' ? 'warning' : 'danger'});
                });
                option.append(radio, label, help); fields.append(option);
            });
            body.append(fields); return body;
        },
        setup(ctx) {
            ctx.on('dialog:beforeClose', () => !busy);
            ctx.on('button:confirm', async () => {
                if (busy || invalid || (preview.revoked && selected !== 'replace')) return false;
                busy = true; fields.disabled = true;
                ctx.buttons.disable('confirm'); ctx.buttons.disable('cancel'); ctx.buttons.setLoading('confirm', true); ctx.setCloseButton(false);
                try {
                    const response = await module.ajax(selected === 'replace' ? 'replace_tsa_certificate' : 'revoke_tsa_certificate',
                        {review_hash: preview.review_hash, reason: selected});
                    if (!response?.ok) throw new Error('TSA action failed');
                    return {applied: true, receipt: receipt(response)};
                } catch (_) {
                    invalid = true; window.PDFSealerNotify(module.tt('tsa_lifecycle_failed'), 'error'); return false;
                } finally {
                    busy = false; ctx.buttons.setLoading('confirm', false); ctx.buttons.enable('cancel'); ctx.setCloseButton('cancel');
                }
            });
        },
    });
};
