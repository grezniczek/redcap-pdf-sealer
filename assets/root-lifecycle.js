/* Current built-in Root CA only; accepted blocks and pending recovery survive the reload. */
window.PDFSealerRootLifecycle = module => {
    const launcher = document.getElementById('pdf-sealer-root-lifecycle');
    if (!launcher) return;
    const message = document.getElementById('root-lifecycle-message');
    const receiptKey = 'pdf-sealer-root-lifecycle:' + location.pathname + location.search;
    const showMessage = (text, tone) => {
        message.textContent = text;
        message.className = 'alert alert-' + tone;
        message.hidden = false;
    };
    try {
        const stored = sessionStorage.getItem(receiptKey);
        sessionStorage.removeItem(receiptKey);
        const receipt = stored ? JSON.parse(stored) : null;
        if (typeof receipt?.text === 'string' && ['success', 'warning'].includes(receipt.tone)) {
            showMessage(receipt.text, receipt.tone);
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
        message.hidden = true;
        if (typeof window.rcDialog?.wizard !== 'function') {
            showMessage(module.tt('root_lifecycle_dialog_unavailable'), 'warning');
            return;
        }
        launcher.disabled = true;
        launcher.setAttribute('aria-busy', 'true');
        try {
            const preview = await module.ajax('preview_root_lifecycle', {});
            if (!preview?.ok) throw new Error('Root CA review failed');
            let busy = false;
            const result = await window.rcDialog.wizard({
                title: module.tt('root_lifecycle_title'),
                size: 'lg',
                draggable: true,
                closeButton: 'cancel',
                focusAfterClose: launcher,
                pageLabelTemplate: module.tt('root_lifecycle_steps'),
                state: {reason: 'renew', acknowledged: false, invalid: false, completed: false},
                buttons: ['cancel',
                    {id: 'back', label: module.tt('root_lifecycle_back'), intent: 'secondary'},
                    {id: 'advance', label: module.tt('root_lifecycle_next'), intent: 'primary'},
                    {id: 'confirm', label: module.tt('root_lifecycle_confirm_button'), intent: 'primary'}],
                pages: [
                    {id: 'review', subtitle: module.tt('root_lifecycle_review'), body(ctx) {
                        ctx.buttons.hide('back'); ctx.buttons.hide('confirm'); ctx.buttons.show('advance');
                        if (!preview.revoked && !ctx.state.invalid) ctx.buttons.enable('advance');
                        const body = element('div', '');
                        body.append(certificateDetails(preview), element('p', 'small text-muted',
                            module.tt('root_lifecycle_dependents', preview.known_dependent_certificates)));
                        if (preview.revoked) body.append(element('p', 'alert alert-warning', module.tt('root_lifecycle_already')));
                        const fields = element('fieldset', '');
                        fields.disabled = preview.revoked;
                        fields.append(element('legend', 'h6', module.tt('root_lifecycle_action')));
                        ['renew', 'superseded', 'compromise'].forEach(reason => {
                            const row = element('div', 'form-check mb-3');
                            const radio = element('input', 'form-check-input');
                            radio.type = 'radio'; radio.name = 'root-lifecycle-action'; radio.value = reason;
                            radio.id = 'root-lifecycle-' + reason; radio.checked = ctx.state.reason === reason;
                            radio.setAttribute('aria-describedby', radio.id + '-help');
                            const label = element('label', 'form-check-label', module.tt('root_lifecycle_' + reason));
                            label.htmlFor = radio.id;
                            const help = element('div', 'small text-muted', module.tt('root_lifecycle_' + reason + '_help'));
                            help.id = radio.id + '-help';
                            radio.addEventListener('change', () => { ctx.state.reason = reason; ctx.state.acknowledged = false; });
                            row.append(radio, label, help); fields.append(row);
                        });
                        body.append(fields);
                        if (preview.revoked) ctx.buttons.disable('advance');
                        return body;
                    }},
                    {id: 'confirmation', subtitle: module.tt('root_lifecycle_confirm'), body(ctx) {
                        ctx.buttons.hide('advance'); ctx.buttons.show('back'); ctx.buttons.show('confirm');
                        if (!ctx.state.invalid) ctx.buttons.enable('confirm');
                        const body = element('div', '');
                        const reason = ctx.state.reason;
                        body.append(element('p', 'fw-bold', module.tt('root_lifecycle_' + reason)), certificateDetails(preview),
                            element('p', 'alert ' + (reason === 'renew' ? 'alert-info' : 'alert-warning'),
                                module.tt('root_lifecycle_' + reason + '_help')));
                        if (reason === 'compromise') {
                            const row = element('div', 'form-check');
                            const check = element('input', 'form-check-input');
                            check.type = 'checkbox'; check.id = 'root-lifecycle-compromise-ack'; check.required = true;
                            check.checked = ctx.state.acknowledged;
                            const label = element('label', 'form-check-label', module.tt('root_lifecycle_compromise_ack'));
                            label.htmlFor = check.id;
                            check.addEventListener('change', () => {
                                ctx.state.acknowledged = check.checked;
                                if (check.checked && !busy && !ctx.state.invalid) ctx.buttons.enable('confirm');
                                else ctx.buttons.disable('confirm');
                            });
                            row.append(check, label); body.append(row);
                            ctx.buttons.disable('confirm');
                        }
                        ctx.buttons.update('confirm', {intent: reason === 'renew' ? 'primary' : 'danger'});
                        return body;
                    }},
                ],
                setup(ctx) {
                    ctx.on('dialog:beforeClose', () => !busy);
                    ctx.on('wizard:beforePageChange', () => !busy && !preview.revoked && !ctx.state.invalid);
                    ctx.on('button:advance', async () => {
                        if (!busy && !ctx.state.invalid && ctx.wizard.currentPage.id === 'review') await ctx.wizard.next();
                        return false;
                    });
                    ctx.on('button:back', async () => {
                        if (!busy && !ctx.state.invalid && ctx.wizard.currentPage.id === 'confirmation') {
                            ctx.state.acknowledged = false;
                            await ctx.wizard.previous();
                        }
                        return false;
                    });
                    ctx.on('button:confirm', async () => {
                        if (busy || preview.revoked || ctx.state.invalid || ctx.state.completed
                            || ctx.wizard.currentPage.id !== 'confirmation') return false;
                        if (ctx.state.reason === 'compromise' && !ctx.state.acknowledged) return false;
                        busy = true;
                        ctx.buttons.disable('confirm'); ctx.buttons.disable('cancel'); ctx.buttons.disable('back');
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
                            ctx.setFooterStatus(module.tt('root_lifecycle_failed'));
                            return false;
                        } finally {
                            busy = false;
                            ctx.buttons.setLoading('confirm', false); ctx.buttons.enable('cancel'); ctx.setCloseButton('cancel');
                        }
                    });
                },
            });
            if (result?.applied) location.reload();
        } catch (_) { showMessage(module.tt('root_lifecycle_failed'), 'danger'); }
        finally { launcher.disabled = false; launcher.removeAttribute('aria-busy'); }
    });
};
