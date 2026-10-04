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
        const initialized = [];
        let observer, cleaned = false;
        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            observer?.disconnect();
            initialized.forEach(select => $(select).select2('destroy'));
            form.reset();
            projects.forEach(select => $(select).trigger('change'));
            body.querySelectorAll('[data-workflow-reset-change]').forEach(select => select.dispatchEvent(new Event('change')));
            host.appendChild(body);
        };
        launchers.forEach(button => { button.disabled = true; });
        try {
            await window.rcDialog({title: launcher.textContent.trim(), size: 'lg', draggable: true,
                closeButton: 'close', focusAfterClose: launcher, buttons: ['close'], body: () => body,
                setup(ctx) {
                    const busy = () => form.getAttribute('aria-busy') === 'true';
                    const updateBusy = () => {
                        if (busy()) { ctx.buttons.disable('close'); ctx.setCloseButton(false); }
                        else { ctx.buttons.enable('close'); ctx.setCloseButton('close'); }
                    };
                    ctx.on('dialog:beforeClose', () => {
                        if (busy()) return false;
                        launcher.disabled = false; // Allow focus restoration before the dialog promise resolves.
                        return true;
                    });
                    ctx.on('dialog:shown', () => {
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
        } catch (_) { window.PDFSealerNotify(module.tt('provider_workflow_failed'), 'error'); }
        finally { cleanup(); launchers.forEach(button => { button.disabled = false; }); }
    }));
};
