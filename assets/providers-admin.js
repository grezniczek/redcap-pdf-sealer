/* CC provider presentation. Existing authenticated AJAX services own all policy checks and mutations. */
window.PDFSealerProvidersAdmin = module => {
    const tableNode = document.getElementById('pdf-sealer-providers');
    if (!tableNode) return;
    const policy = document.getElementById('pdf-sealer-assignment-policy');
    const policyButton = document.getElementById('pdf-sealer-policy-change');
    const rows = new Map([...tableNode.querySelectorAll('[data-provider-id]')].map(row => [row.dataset.providerId, row]));
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const notify = (key, tone, ...values) => window.PDFSealerNotify(module.tt(key, ...values), tone);
    const language = {
        search: module.tt('table_search'), lengthMenu: module.tt('table_length'),
        info: module.tt('table_info'), infoEmpty: module.tt('table_info_empty'),
        infoFiltered: module.tt('table_info_filtered'), zeroRecords: module.tt('table_zero'),
        paginate: {first: module.tt('table_first'), last: module.tt('table_last'),
            next: module.tt('table_next'), previous: module.tt('table_previous')},
    };
    const table = $(tableNode).DataTable({pageLength: 10, order: [[1, 'asc']], language,
        columnDefs: [{targets: 0, width: '70px'}, {targets: 4, orderable: false, searchable: false}]});
    const adjust = () => { if (!document.getElementById('pki-panel-providers').hidden) table.columns.adjust(); };
    document.querySelector('[data-pki-tab="providers"]').addEventListener('click', adjust);
    window.addEventListener('hashchange', adjust);
    adjust();
    const available = tabbed => {
        if (typeof window.rcDialog === 'function' && (!tabbed || typeof window.rcDialog.tabbed === 'function')) return true;
        notify('provider_dialog_unavailable', 'warning');
        return false;
    };
    const updatePolicy = required => {
        policy.dataset.required = required ? '1' : '0';
        policy.querySelector('[data-policy-summary]').textContent = module.tt(required ? 'assignment_policy_explicit' : 'assignment_policy_automatic');
    };
    const badge = retired => element('span', 'badge ' + (retired ? 'bg-secondary' : 'bg-success'),
        module.tt(retired ? 'provider_retired' : 'provider_active'));
    const updateProvider = (id, retired, required) => {
        const row = rows.get(id);
        row.dataset.retired = retired ? '1' : '0';
        const status = badge(retired); status.setAttribute('data-provider-status', '');
        row.querySelector('[data-provider-status]').replaceWith(status);
        table.row(row).invalidate('dom').draw(false);
        updatePolicy(required);
        window.PDFSealerProjectsAdmin?.providerChanged?.(id, retired);
    };
    policyButton.addEventListener('click', async () => {
        if (policyButton.disabled || !available(false)) return;
        policyButton.disabled = true;
        let busy = false, fields, checkbox;
        try {
            const result = await window.rcDialog({title: module.tt('assignment_policy_change'), draggable: true,
                size: 'md', closeButton: 'cancel', focusAfterClose: policyButton,
                buttons: ['cancel', {id: 'save', label: module.tt('assignment_policy_save'), intent: 'primary'}],
                body() {
                    const body = element('div', 'pdf-sealer-dialog-body');
                    fields = element('fieldset', '');
                    const label = element('label', '');
                    checkbox = element('input', ''); checkbox.type = 'checkbox';
                    checkbox.checked = policy.dataset.required === '1';
                    label.append(checkbox, document.createTextNode(' ' + module.tt('assignment_policy_label')));
                    fields.append(label, element('p', 'small text-muted', module.tt('assignment_policy_help')),
                        element('p', 'alert alert-warning', module.tt('assignment_policy_delivery')));
                    body.append(fields); return body;
                },
                setup(ctx) {
                    ctx.on('dialog:beforeClose', () => !busy);
                    ctx.on('button:save', async () => {
                        if (busy || fields.disabled) return false;
                        busy = true; fields.disabled = true;
                        ctx.buttons.disable('save'); ctx.buttons.disable('cancel');
                        ctx.buttons.setLoading('save', true); ctx.setCloseButton(false); ctx.clearFooterStatus();
                        try {
                            const response = await module.ajax('save_assignment_policy', {required: checkbox.checked});
                            if (!response?.ok) throw new Error('Policy save failed');
                            return {required: response.required};
                        } catch (_) {
                            // An ambiguous save needs a fresh page review; do not replay it from this dialog.
                            notify('assignment_policy_failed', 'error'); return false;
                        } finally {
                            busy = false; ctx.buttons.setLoading('save', false);
                            ctx.buttons.enable('cancel'); ctx.setCloseButton('cancel');
                        }
                    });
                },
            });
            if (typeof result?.required === 'boolean') {
                updatePolicy(result.required);
                notify('assignment_policy_saved', 'success');
            }
        } catch (_) {
            notify('assignment_policy_failed', 'danger');
        } finally { policyButton.disabled = false; }
    });
    const counts = preview => module.tt('provider_retirement_counts', preview.projects.length,
        preview.projects.filter(p => p.identity_id !== null).length,
        preview.projects.filter(p => p.enrollment_id !== null).length);
    const confirmRetirement = async (id, name, focusTarget) => {
        const preview = await module.ajax('preview_ca_retirement', {provider: id});
        if (!preview?.ok) throw new Error('Provider review failed');
        const needsGate = !preview.retired && preview.is_default && !preview.assignment_required;
        const actionKey = preview.retired ? 'provider_reactivate' : 'provider_retire';
        let busy = false, invalid = false, gate;
        return window.rcDialog({title: module.tt(actionKey) + ': ' + name, size: 'md', draggable: true,
            closeButton: 'cancel', focusAfterClose: focusTarget,
            buttons: ['cancel', {id: 'confirm', label: module.tt(actionKey), intent: preview.retired ? 'primary' : 'warning'}],
            body(ctx) {
                const body = element('div', 'pdf-sealer-dialog-body');
                body.append(element('p', '', counts(preview)), element('p', '',
                    module.tt(preview.retired ? 'provider_reactivation_help' : 'provider_retirement_help')));
                gate = element('input', ''); gate.type = 'checkbox';
                if (needsGate) {
                    const label = element('label', 'alert alert-warning');
                    label.append(gate, document.createTextNode(' ' + module.tt('provider_retirement_gate')));
                    body.append(label); ctx.buttons.disable('confirm');
                    gate.addEventListener('change', () => {
                        if (busy || invalid) return;
                        if (gate.checked) ctx.buttons.enable('confirm'); else ctx.buttons.disable('confirm');
                    });
                }
                return body;
            },
            setup(ctx) {
                ctx.on('dialog:beforeClose', () => !busy);
                ctx.on('button:confirm', async () => {
                    if (busy || invalid || (needsGate && !gate.checked)) return false;
                    busy = true; gate.disabled = true;
                    ctx.buttons.disable('confirm'); ctx.buttons.disable('cancel');
                    ctx.buttons.setLoading('confirm', true); ctx.setCloseButton(false); ctx.clearFooterStatus();
                    try {
                        const response = await module.ajax('set_ca_retirement', {provider: id, retired: !preview.retired,
                            review_hash: preview.review_hash, enable_assignment_gate: Boolean(needsGate && gate.checked)});
                        if (!response?.ok) throw new Error('Provider change failed');
                        return {retired: !preview.retired, required: preview.assignment_required || needsGate};
                    } catch (_) {
                        invalid = true; notify('provider_lifecycle_failed', 'error'); return false;
                    } finally {
                        busy = false; ctx.buttons.setLoading('confirm', false);
                        ctx.buttons.enable('cancel'); ctx.setCloseButton('cancel');
                    }
                });
            },
        });
    };
    tableNode.addEventListener('click', async event => {
        const launcher = event.target.closest('[data-provider-manage]');
        if (!launcher || launcher.disabled || !available(true)) return;
        const row = launcher.closest('[data-provider-id]'), id = row.dataset.providerId;
        const name = row.querySelector('[data-provider-name]').textContent;
        launcher.disabled = true;
        let usageTable;
        try {
            const preview = await module.ajax('preview_ca_retirement', {provider: id});
            if (!preview?.ok) throw new Error('Provider review failed');
            updateProvider(id, preview.retired, preview.assignment_required);
            let busy = false, usageNode;
            const action = element('button', 'btn btn-link btn-sm p-0', module.tt(preview.retired ? 'provider_reactivate' : 'provider_retire'));
            action.type = 'button';
            const result = await window.rcDialog.tabbed({title: module.tt('provider_manage_title', name), size: 'lg',
                draggable: true, closeButton: 'close', focusAfterClose: launcher, buttons: ['close'], footerStatus: action,
                tabs: [
                    {id: 'details', label: module.tt('provider_details'), body() {
                        const body = document.getElementById('pdf-sealer-provider-details-' + id).content.cloneNode(true);
                        body.querySelector('[data-details-status]').append(badge(preview.retired));
                        return body;
                    }},
                    {id: 'usage', label: module.tt('provider_usage'), body() {
                        const body = element('div', 'pdf-sealer-dialog-body');
                        body.append(element('p', '', counts(preview)));
                        const wrapper = element('div', 'pdf-sealer-table-wrap');
                        usageNode = element('table', 'table table-sm hover');
                        const header = element('thead', ''), headerRow = element('tr', '');
                        ['provider_pid', 'provider_active_signer', 'provider_pending_enrollment', 'transition_pending_label'].forEach(key => headerRow.append(element('th', '', module.tt(key))));
                        header.append(headerRow); usageNode.append(header);
                        const tbody = element('tbody', '');
                        preview.projects.forEach(project => {
                            const tr = element('tr', '');
                            [project.pid, project.identity_id !== null ? '✓' : '—', project.enrollment_id !== null ? '✓' : '—', project.transition_id ? '✓' : '—']
                                .forEach(value => tr.append(element('td', '', value)));
                            tbody.append(tr);
                        });
                        usageNode.append(tbody); wrapper.append(usageNode); body.append(wrapper); return body;
                    }},
                ],
                setup(ctx) {
                    const initializeUsage = () => {
                        if (!usageTable) usageTable = $(usageNode).DataTable({pageLength: 10, order: [[0, 'asc']],
                            language: {...language, emptyTable: module.tt('provider_usage_empty')}});
                        usageTable.columns.adjust();
                    };
                    ctx.on('dialog:beforeClose', () => !busy);
                    ctx.on('tab:changed', event => { if (event.current.id === 'usage') initializeUsage(); });
                    action.addEventListener('click', async () => {
                        if (busy) return;
                        busy = true; action.disabled = true;
                        ctx.buttons.disable('close'); ctx.setCloseButton(false);
                        try {
                            const changed = await confirmRetirement(id, name, action);
                            if (changed) {
                                busy = false; await ctx.close(changed); return;
                            }
                        } catch (_) {
                            notify('provider_lifecycle_failed', 'error');
                        }
                        finally {
                            busy = false; action.disabled = false; ctx.buttons.enable('close'); ctx.setCloseButton('close');
                        }
                    });
                },
            });
            if (typeof result?.retired === 'boolean') {
                updateProvider(id, result.retired, result.required);
                notify('provider_lifecycle_saved', 'success', name,
                    module.tt(result.retired ? 'provider_retired' : 'provider_active'));
            }
        } catch (_) { notify('provider_review_failed', 'danger'); }
        finally { if (usageTable) usageTable.destroy(); launcher.disabled = false; }
    });
};
