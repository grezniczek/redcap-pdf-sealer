/* Current built-in Root CA only; accepted blocks and pending recovery survive the reload. */
window.PDFSealerRootLifecycle = module => {
    const launcher = document.getElementById('pdf-sealer-root-lifecycle');
    if (!launcher) return;
    const receiptKey = 'pdf-sealer-root-lifecycle:' + location.pathname + location.search;
    try {
        const stored = sessionStorage.getItem(receiptKey);
        sessionStorage.removeItem(receiptKey);
        const receipt = stored ? JSON.parse(stored) : null;
        if (typeof receipt?.text === 'string' && ['success', 'warning'].includes(receipt.tone)) {
            window.PDFSealerNotify(receipt.text, receipt.tone);
        }
    } catch (_) { /* Storage restrictions must not prevent certificate management. */ }
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const certificateDetails = preview => {
        const list = element('dl', 'pdf-sealer-certificate');
        const values = [
            ['pki_subject', preview.certificate.subject.replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n').trim()],
            ['pki_fingerprint', preview.certificate.fingerprint],
            ['pki_thumbprint', preview.certificate.thumbprint],
        ];
        values.forEach(([key, value]) => {
            const description = element('dd', '');
            if (key === 'pki_subject') {
                description.style.whiteSpace = 'pre-line';
                description.textContent = value;
            } else description.append(element('code', 'pdf-sealer-fingerprint', value));
            list.append(element('dt', '', module.tt(key)), description);
        });
        return list;
    };
    const outcome = (selected, result) => {
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
        return {text: details.join(' '), tone: selected === 'renew' && result.replacement === 'renewed' ? 'success' : 'warning'};
    };
    launcher.addEventListener('click', async () => {
        if (launcher.disabled) return;
        if (typeof window.rcDialog !== 'function') {
            window.PDFSealerNotify(module.tt('root_lifecycle_dialog_unavailable'), 'warning');
            return;
        }
        launcher.disabled = true;
        launcher.setAttribute('aria-busy', 'true');
        try {
            const preview = await module.ajax('preview_root_lifecycle', {});
            if (!preview?.ok) throw new Error('Root CA review failed');
            let busy = false;
            let fields;
            const result = await window.rcDialog({
                title: module.tt('root_lifecycle_title'),
                size: 'lg',
                draggable: true,
                closeButton: 'cancel',
                focusAfterClose: launcher,
                state: {reason: 'renew', acknowledged: false, invalid: false, completed: false},
                buttons: ['cancel', {id: 'confirm', label: module.tt('root_lifecycle_confirm_button'), intent: 'primary'}],
                body(ctx) {
                    const body = element('div', 'pdf-sealer-dialog-body');
                    body.append(element('h6', 'h6', module.tt('root_lifecycle_review')),
                        certificateDetails(preview), element('p', 'small text-muted',
                        module.tt('root_lifecycle_dependents', preview.known_dependent_certificates)));
                    if (preview.revoked) body.append(element('p', 'alert alert-warning', module.tt('root_lifecycle_already')));
                    fields = element('fieldset', '');
                    fields.disabled = preview.revoked;
                    fields.append(element('legend', 'h6', module.tt('root_lifecycle_action')));
                    const acknowledgment = element('div', 'red');
                    const row = element('div', 'form-check');
                    const check = element('input', 'form-check-input');
                    check.type = 'checkbox'; check.id = 'root-lifecycle-compromise-ack';
                    const checkLabel = element('label', 'form-check-label', module.tt('root_lifecycle_compromise_ack'));
                    checkLabel.htmlFor = check.id;
                    row.append(check, checkLabel); acknowledgment.append(row);
                    const updateChoice = () => {
                        const compromise = ctx.state.reason === 'compromise';
                        acknowledgment.hidden = !compromise;
                        check.required = compromise; check.checked = ctx.state.acknowledged;
                        ctx.buttons.update('confirm', {intent: ctx.state.reason === 'renew' ? 'primary' : 'danger'});
                        if (busy || preview.revoked || ctx.state.invalid || ctx.state.completed
                            || (compromise && !ctx.state.acknowledged)) ctx.buttons.disable('confirm');
                        else ctx.buttons.enable('confirm');
                    };
                    ['renew', 'superseded', 'compromise'].forEach(reason => {
                        const option = element('div', 'form-check mb-3');
                        const radio = element('input', 'form-check-input');
                        radio.type = 'radio'; radio.name = 'root-lifecycle-action'; radio.value = reason;
                        radio.id = 'root-lifecycle-' + reason; radio.checked = ctx.state.reason === reason;
                        radio.setAttribute('aria-describedby', radio.id + '-help');
                        const label = element('label', 'form-check-label', module.tt('root_lifecycle_' + reason));
                        label.htmlFor = radio.id;
                        const help = element('div', 'small text-muted', module.tt('root_lifecycle_' + reason + '_help'));
                        help.id = radio.id + '-help';
                        radio.addEventListener('change', () => {
                            if (fields.disabled) return;
                            ctx.state.reason = reason; ctx.state.acknowledged = false;
                            updateChoice();
                        });
                        option.append(radio, label, help); fields.append(option);
                    });
                    check.addEventListener('change', () => {
                        if (fields.disabled) return;
                        ctx.state.acknowledged = check.checked;
                        updateChoice();
                    });
                    fields.append(acknowledgment); body.append(fields);
                    updateChoice();
                    return body;
                },
                setup(ctx) {
                    ctx.on('dialog:beforeClose', () => !busy);
                    ctx.on('button:confirm', async () => {
                        if (busy || preview.revoked || ctx.state.invalid || ctx.state.completed) return false;
                        if (ctx.state.reason === 'compromise' && !ctx.state.acknowledged) return false;
                        busy = true; fields.disabled = true;
                        ctx.buttons.disable('confirm'); ctx.buttons.disable('cancel');
                        ctx.buttons.setLoading('confirm', true); ctx.setCloseButton(false);
                        ctx.clearFooterStatus();
                        try {
                            const selected = ctx.state.reason;
                            const response = await module.ajax(selected === 'renew' ? 'renew_root_certificate' : 'revoke_root_certificate',
                                {review_hash: preview.review_hash, reason: selected});
                            if (!response?.ok) throw new Error('Root CA action failed');
                            ctx.state.completed = true;
                            try { sessionStorage.setItem(receiptKey, JSON.stringify(outcome(selected, response))); }
                            catch (_) { /* The page will still refresh to the current saved state. */ }
                            return {applied: true};
                        } catch (_) {
                            ctx.state.invalid = true; // Do not retry a possibly accepted mutation with the same review.
                            window.PDFSealerNotify(module.tt('root_lifecycle_failed'), 'error');
                            return false;
                        } finally {
                            busy = false;
                            ctx.buttons.setLoading('confirm', false); ctx.buttons.enable('cancel'); ctx.setCloseButton('cancel');
                        }
                    });
                },
            });
            if (result?.applied) location.reload();
        } catch (_) { window.PDFSealerNotify(module.tt('root_lifecycle_failed'), 'danger'); }
        finally { launcher.disabled = false; launcher.removeAttribute('aria-busy'); }
    });
};
