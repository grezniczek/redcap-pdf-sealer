/* Reuse the existing reviewed forms; only their presentation and project-picker lifetime change. */
window.PDFSealerProviderWorkflows = module => {
    const launchers = [...document.querySelectorAll('[data-provider-workflow]')];
    launchers.forEach(launcher => launcher.addEventListener('click', async () => {
        if (launcher.disabled) return;
        if (typeof window.rcDialog !== 'function') {
            window.PDFSealerNotify(module.tt('provider_dialog_unavailable'), 'warning'); return;
        }
        const host = document.querySelector('[data-provider-workflow-host="' + launcher.dataset.providerWorkflow + '"]');
        const body = host.firstElementChild, form = body.querySelector('form');
        const projects = [...body.querySelectorAll('[data-workflow-project]')];
        const registration = launcher.dataset.providerWorkflow === 'register';
        const dismiss = registration ? 'cancel' : 'close';
        const initialized = [];
        let observer, submit, assignmentCleanup, transitionCleanup, cleaned = false;
        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            observer?.disconnect();
            assignmentCleanup?.();
            transitionCleanup?.();
            if (submit) form.removeEventListener('submit', submit);
            initialized.forEach(select => $(select).select2('destroy'));
            form.reset();
            projects.forEach(select => $(select).trigger('change'));
            body.querySelectorAll('[data-workflow-reset-change]').forEach(select => select.dispatchEvent(new Event('change')));
            host.appendChild(body);
        };
        launchers.forEach(button => { button.disabled = true; });
        try {
            const result = await window.rcDialog({title: launcher.textContent.trim(), size: 'lg', draggable: true,
                closeButton: dismiss, focusAfterClose: launcher,
                buttons: registration ? ['cancel', {use: 'save', id: 'register', label: module.tt('provider_register')}] : ['close'],
                body: () => body,
                setup(ctx) {
                    const busy = () => form.getAttribute('aria-busy') === 'true';
                    const updateBusy = () => {
                        ctx.buttons[busy() ? 'disable' : 'enable'](dismiss);
                        ctx.setCloseButton(busy() ? false : dismiss);
                        if (registration) ctx.buttons[busy() ? 'disable' : 'enable']('register');
                    };
                    if (registration) {
                        const fields = form.querySelector('fieldset');
                        ctx.on('button:register', async () => {
                            if (busy() || fields.disabled || !form.reportValidity()) return false;
                            form.setAttribute('aria-busy', 'true');
                            fields.disabled = true;
                            try {
                                const file = form.querySelector('#provider-chain').files[0];
                                if (!file || file.size > 131072) throw new Error('Invalid upload');
                                const payload = {name: form.querySelector('#provider-name').value,
                                    source: form.querySelector('#provider-source').value,
                                    fallback: form.querySelector('#provider-fallback').checked,
                                    pem: await file.text()};
                                const response = await module.ajax('register_ca_provider', payload);
                                if (response?.ok) return {registered: true};
                                window.PDFSealerNotify(response?.message || module.tt('provider_request_failed'), 'error');
                            } catch (_) { window.PDFSealerNotify(module.tt('provider_request_failed'), 'error'); }
                            finally {
                                fields.disabled = false;
                                form.setAttribute('aria-busy', 'false');
                            }
                            return false;
                        });
                        // Native form submission (including Enter) uses the same footer action.
                        submit = event => { event.preventDefault(); if (!busy()) ctx.buttons.trigger('register'); };
                        form.addEventListener('submit', submit);
                    }
                    ctx.on('dialog:beforeClose', () => {
                        if (busy()) return false;
                        launcher.disabled = false; // Allow focus restoration before the dialog promise resolves.
                        return true;
                    });
                    ctx.on('dialog:shown', () => {
                        if (launcher.dataset.providerWorkflow === 'assign') {
                            assignmentCleanup = window.PDFSealerProjectAssignment(module, form);
                        }
                        if (launcher.dataset.providerWorkflow === 'transition') {
                            transitionCleanup = window.PDFSealerProjectTransitions(module, form);
                        }
                        projects.forEach(select => {
                            $(select).select2({width: '100%', minimumResultsForSearch: 0, dropdownParent: ctx.$dlg});
                            initialized.push(select);
                        });
                        updateBusy();
                    });
                    observer = new MutationObserver(updateBusy);
                    observer.observe(form, {attributes: true, attributeFilter: ['aria-busy']});
                    ctx.on('dialog:hidden', cleanup);
                },
            });
            if (result?.registered) {
                const url = new URL(location.href);
                url.searchParams.set('provider_notice', 'registered');
                url.hash = 'providers'; location.assign(url.href);
            }
        } catch (_) { window.PDFSealerNotify(module.tt('provider_workflow_failed'), 'error'); }
        finally { cleanup(); launchers.forEach(button => { button.disabled = false; }); }
    }));
};
