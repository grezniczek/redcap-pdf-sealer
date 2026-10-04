/* CC presentation only. Authenticated AJAX services retain source and policy validation. */
window.PDFSealerTimestampAdmin = (module, policies, sources, formatTime, pageUrl) => {
    const text = key => module.tt(key);
    const notify = (message, tone = 'error') => window.PDFSealerNotify(message, tone);
    const available = () => {
        if (typeof window.rcDialog === 'function') return true;
        notify(text('provider_dialog_unavailable'), 'warning'); return false;
    };
    const element = (tag, className, value) => {
        const node = document.createElement(tag); node.className = className;
        if (value !== undefined) node.textContent = value;
        return node;
    };
    const receiptKey = 'pdf-sealer-tsa-lifecycle:' + pageUrl;
    try {
        const stored = sessionStorage.getItem(receiptKey); sessionStorage.removeItem(receiptKey);
        const receipt = stored ? JSON.parse(stored) : null;
        if (typeof receipt?.text === 'string' && ['success', 'warning'].includes(receipt.tone)) notify(receipt.text, receipt.tone);
    } catch (_) { /* Storage restrictions do not prevent management. */ }
    const reload = notice => {
        const url = new URL(pageUrl, location.href);
        if (notice) url.searchParams.set('tsa_notice', notice);
        url.hash = 'tsa'; location.assign(url.href);
    };
    const utc = seconds => new Date(seconds * 1000).toISOString().replace('T', ' ').replace('.000Z', ' UTC');
    const tableNode = document.getElementById('pdf-sealer-tsa-sources');
    const rows = new Map([...tableNode.querySelectorAll('[data-tsa-id]')].map(row => [row.dataset.tsaId, row]));
    const renderObservation = (source, observation) => {
        const snapshot = source.diagnostic;
        observation.className = 'small ' + (snapshot ? (snapshot.ok ? 'text-success' : 'text-danger') : 'text-muted');
        observation.textContent = snapshot ? text(snapshot.ok ? 'external_tsa_passed' : 'external_tsa_test_failed')
            + ' — ' + formatTime(new Date(snapshot.checked_at * 1000))
            + (snapshot.ok ? '\n' + text('pki_fingerprint') + ': ' + snapshot.signer_sha256 : '')
            + (snapshot.ok && snapshot.signer_sha1 ? '\n' + text('pki_thumbprint') + ': ' + snapshot.signer_sha1 : '')
            + (snapshot.ok && snapshot.valid_until ? '\n' + text('pki_valid_until') + ': ' + utc(snapshot.valid_until) : '')
            : text('diagnostic_never');
        observation.style.overflowWrap = 'anywhere'; observation.style.whiteSpace = 'pre-line';
    };
    const renderStatus = source => {
        const cell = rows.get(source.id).querySelector('[data-tsa-status]');
        cell.replaceChildren(element('span', 'badge ' + (source.retired ? 'bg-secondary' : 'bg-success'),
            text(source.retired ? 'provider_retired' : 'provider_active')));
    };
    const renderRow = source => {
        renderStatus(source);
        const row = rows.get(source.id), snapshot = source.diagnostic;
        const last = row.querySelector('[data-tsa-last-test]'), expiry = row.querySelector('[data-tsa-expiry]');
        last.dataset.order = String(snapshot?.checked_at || 0);
        last.className = 'small ' + (snapshot ? (snapshot.ok ? 'text-success' : 'text-danger') : 'text-muted');
        last.textContent = snapshot ? text(snapshot.ok ? 'tsa_test_passed' : 'tsa_test_failed') + ' — '
            + formatTime(new Date(snapshot.checked_at * 1000)) : text('diagnostic_never');
        expiry.dataset.order = String(snapshot?.valid_until || 0);
        expiry.textContent = snapshot?.valid_until ? utc(snapshot.valid_until) : '—';
    };
    sources.forEach(renderRow);
    const table = $(tableNode).DataTable({pageLength: 10, order: [[2, 'asc']],
        language: {search: text('table_search'), lengthMenu: text('table_length'), info: text('table_info'),
            infoEmpty: text('table_info_empty'), infoFiltered: text('table_info_filtered'), zeroRecords: text('table_zero'),
            paginate: {first: text('table_first'), last: text('table_last'), next: text('table_next'), previous: text('table_previous')}},
        columnDefs: [{targets: [0, 1], width: '70px'}, {targets: 5, orderable: false, searchable: false}]});
    const testAll = document.getElementById('pdf-sealer-tsa-test-all');
    let batchBusy = false, testsRunning = 0;
    const updateTestAll = () => {
        testAll.disabled = batchBusy || testsRunning > 0 || !sources.some(source => !source.retired);
        testAll.textContent = text(batchBusy ? 'tsa_test_all_running' : 'tsa_test_all');
    };
    const testSource = async source => {
        if (source.retired) throw new Error('Source retired');
        testsRunning++; updateTestAll();
        try {
            const response = await module.ajax('test_timestamp_source', {source: source.id});
            if (!response?.ok) throw new Error('Source test failed');
            source.diagnostic = response.diagnostic;
            renderRow(source); table.row(rows.get(source.id)).invalidate('dom').draw(false);
            return source.diagnostic;
        } finally { testsRunning--; updateTestAll(); }
    };
    testAll.addEventListener('click', async () => {
        if (testAll.disabled || batchBusy || testsRunning > 0 || sources.length === 0) return;
        batchBusy = true; updateTestAll();
        const activeSources = sources.filter(source => !source.retired);
        const launchers = activeSources.map(source => rows.get(source.id).querySelector('[data-tsa-manage]'));
        launchers.forEach(button => { button.disabled = true; });
        let passed = 0, failed = 0, incomplete = 0;
        try {
            // Include every active registered source, regardless of DataTables paging/search. Keep requests sequential.
            for (const source of activeSources) {
                try {
                    const snapshot = await testSource(source);
                    if (snapshot.ok) passed++; else failed++;
                } catch (_) { incomplete++; } // Preserve the dated last completed result when the request is interrupted.
            }
            notify(module.tt('tsa_test_all_result', passed, failed, incomplete), failed || incomplete ? 'warning' : 'success');
        } finally {
            batchBusy = false; updateTestAll();
            launchers.forEach(button => { button.disabled = false; });
        }
    });
    updateTestAll();
    const adjust = () => { if (!document.getElementById('pki-panel-tsa').hidden) table.columns.adjust(); };
    document.querySelector('[data-pki-tab="tsa"]').addEventListener('click', adjust);
    window.addEventListener('hashchange', adjust); adjust();
    const confirmRetirement = async (source, launcher) => {
        const preview = await module.ajax('preview_timestamp_retirement', {source: source.id});
        if (!preview?.ok) throw new Error('TSA retirement review failed');
        let busy = false, invalid = false;
        const actionKey = preview.retired ? 'tsa_reactivate' : 'tsa_retire';
        return window.rcDialog({title: module.tt('tsa_retirement_title', text(actionKey), source.name),
            size: 'md', draggable: true, closeButton: 'cancel', focusAfterClose: launcher,
            buttons: ['cancel', {id: 'confirm', label: text(actionKey), intent: preview.retired ? 'primary' : 'warning'}],
            body() {
                const body = element('div', 'pdf-sealer-dialog-body');
                body.append(element('p', '', text(preview.retired ? 'tsa_reactivation_help' : 'tsa_retirement_help')),
                    element('p', '', module.tt('tsa_retirement_usage', preview.providers.length)));
                if (preview.providers.length === 0) body.append(element('p', 'text-muted', text('tsa_retirement_none')));
                else {
                    const list = element('ul', 'pdf-sealer-project-review');
                    preview.providers.forEach(provider => {
                        const role = provider.position === 0 ? text('tsa_retirement_primary') : module.tt('tsa_retirement_alternative', provider.position);
                        const status = provider.retired ? ' · ' + text('provider_retired') : '';
                        list.append(element('li', 'mb-2', (provider.name || text('provider_builtin')) + ' (' + provider.id + ')' + status
                            + ' — ' + role + ' · ' + text(provider.bb_fallback ? 'timestamp_fallback_allow' : 'timestamp_fallback_fail')));
                    });
                    body.append(list);
                }
                return body;
            },
            setup(ctx) {
                ctx.on('dialog:beforeClose', () => !busy);
                ctx.on('button:confirm', async () => {
                    if (busy || invalid) return false;
                    busy = true; ctx.buttons.disable('confirm'); ctx.buttons.disable('cancel');
                    ctx.buttons.setLoading('confirm', true); ctx.setCloseButton(false);
                    try {
                        const response = await module.ajax('set_timestamp_retirement', {source: source.id,
                            retired: !preview.retired, review_hash: preview.review_hash});
                        if (!response?.ok || typeof response.retired !== 'boolean' || response.retired !== !preview.retired) throw new Error('TSA retirement failed');
                        return {retired: response.retired};
                    } catch (_) {
                        invalid = true; notify(text('tsa_retirement_failed')); return false;
                    } finally {
                        busy = false; ctx.buttons.setLoading('confirm', false); ctx.buttons.enable('cancel'); ctx.setCloseButton('cancel');
                    }
                });
            },
        });
    };
    tableNode.addEventListener('click', async event => {
        const launcher = event.target.closest('[data-tsa-manage]');
        if (!launcher || launcher.disabled || !available()) return;
        const row = launcher.closest('[data-tsa-id]'), id = row.dataset.tsaId;
        const source = sources.find(item => item.id === id);
        if (source && batchBusy) return;
        launcher.disabled = true;
        let busy = false;
        try {
            if (source) {
                const preview = await module.ajax('preview_timestamp_retirement', {source: id});
                if (!preview?.ok) throw new Error('TSA source review failed');
                source.retired = preview.retired;
                renderStatus(source); table.row(row).invalidate('dom').draw(false); updateTestAll();
            }
            const body = id === 'builtin-tsa'
                ? document.getElementById('pdf-sealer-builtin-tsa-details').content.cloneNode(true).firstElementChild
                : element('div', 'pdf-sealer-dialog-body');
            let action, observation, retirementAction;
            if (source) {
                body.append(element('p', 'small', source.id));
                const details = element('dl', 'pdf-sealer-certificate');
                details.append(element('dt', '', text('tsa_source_status')), element('dd', '', text(source.retired ? 'provider_retired' : 'provider_active')),
                    element('dt', '', text('external_tsa_policy')), element('dd', '', source.policy_oid || text('external_tsa_default_policy')),
                    element('dt', '', text('tsa_authentication')), element('dd', '', text(source.authenticated ? 'external_tsa_basic' : 'external_tsa_anonymous')));
                body.append(details, element('h6', '', text('tsa_last_test')));
                observation = element('p', 'small'); observation.setAttribute('role', 'status'); renderObservation(source, observation);
                action = element('button', 'btn btn-outline-secondary btn-sm', text('external_tsa_test')); action.type = 'button'; action.disabled = !!source.retired;
                retirementAction = element('button', 'btn btn-link btn-sm pdf-sealer-workflow-link', text(source.retired ? 'tsa_reactivate' : 'tsa_retire')); retirementAction.type = 'button';
                body.append(observation, action, element('p', 'small text-muted mt-2', text('tsa_observation_help')));
            } else action = body.querySelector('[data-tsa-lifecycle]');
            const result = await window.rcDialog({title: module.tt('tsa_manage_title', row.querySelector('[data-tsa-name]').textContent),
                size: 'lg', draggable: true, closeButton: 'close', focusAfterClose: launcher, buttons: ['close'], footerStatus: retirementAction, body: () => body,
                setup(ctx) {
                    ctx.on('dialog:beforeClose', () => {
                        if (busy) return false;
                        launcher.disabled = false; return true;
                    });
                    if (retirementAction) retirementAction.addEventListener('click', async () => {
                        if (busy || batchBusy) return;
                        busy = true; action.disabled = true; retirementAction.disabled = true;
                        ctx.buttons.disable('close'); ctx.setCloseButton(false);
                        try {
                            const changed = await confirmRetirement(source, retirementAction);
                            if (changed) { busy = false; await ctx.close(changed); }
                        } catch (_) { notify(text('tsa_retirement_failed')); }
                        finally {
                            busy = false; action.disabled = !!source.retired; retirementAction.disabled = false;
                            ctx.buttons.enable('close'); ctx.setCloseButton('close');
                        }
                    });
                    action.addEventListener('click', async () => {
                        if (busy || (source && (batchBusy || source.retired))) return;
                        busy = true; action.disabled = true; if (retirementAction) retirementAction.disabled = true; ctx.buttons.disable('close'); ctx.setCloseButton(false);
                        try {
                            if (!source) {
                                const changed = await window.PDFSealerTsaLifecycle(module, action);
                                if (changed) { busy = false; await ctx.close(changed); }
                            } else {
                                observation.textContent = text('external_tsa_testing');
                                await testSource(source);
                                renderObservation(source, observation);
                                notify(text(source.diagnostic.ok ? 'external_tsa_passed' : 'external_tsa_test_failed'), source.diagnostic.ok ? 'success' : 'error');
                            }
                        } catch (_) {
                            notify(text(source ? 'external_tsa_failed' : 'tsa_lifecycle_failed'));
                            if (source) renderObservation(source, observation); // Retain the dated last completed observation.
                        } finally {
                            busy = false; action.disabled = !!source?.retired; if (retirementAction) retirementAction.disabled = false; ctx.buttons.enable('close'); ctx.setCloseButton('close');
                        }
                    });
                },
            });
            if (source && typeof result?.retired === 'boolean') {
                source.retired = result.retired; renderStatus(source); table.row(row).invalidate('dom').draw(false); updateTestAll();
                // Registration always starts a new assignment; exclude retired sources from its dropdown.
                const registrationSource = document.getElementById('provider-source');
                if (registrationSource) [...registrationSource.options].filter(option => option.value === id).forEach(option => {
                    option.disabled = source.retired;
                    option.textContent = source.retired ? module.tt('tsa_retired_name', source.name) : source.name;
                });
                notify(module.tt('tsa_retirement_saved', source.name, text(source.retired ? 'provider_retired' : 'provider_active')), 'success');
            }
            if (result?.applied) {
                try { sessionStorage.setItem(receiptKey, JSON.stringify(result.receipt)); } catch (_) { /* Refresh still proceeds. */ }
                reload();
            }
        } catch (_) { notify(text('external_tsa_failed')); }
        finally { launcher.disabled = false; }
    });
    const registration = document.getElementById('pdf-sealer-tsa-register');
    registration.addEventListener('click', async () => {
        if (registration.disabled || !available()) return;
        registration.disabled = true;
        const host = document.getElementById('pdf-sealer-tsa-register-host'), body = host.firstElementChild;
        const form = body.querySelector('form'), fields = form.querySelector('fieldset');
        let busy = false, submit;
        try {
            const result = await window.rcDialog({title: text('external_tsa_register'), size: 'lg', draggable: true,
                closeButton: 'cancel', focusAfterClose: registration,
                buttons: ['cancel', {use: 'save', id: 'register', label: text('external_tsa_register')}], body: () => body,
                setup(ctx) {
                    ctx.on('dialog:beforeClose', () => {
                        if (busy) return false;
                        registration.disabled = false; return true;
                    });
                    ctx.on('button:register', async () => {
                        if (busy || fields.disabled || !form.reportValidity()) return false;
                        const payload = Object.fromEntries(new FormData(form));
                        busy = true; fields.disabled = true;
                        ctx.buttons.disable('register'); ctx.buttons.disable('cancel'); ctx.buttons.setLoading('register', true); ctx.setCloseButton(false);
                        try {
                            const response = await module.ajax('register_timestamp_source', payload);
                            if (response?.ok) return {registered: true};
                            notify(response?.message || text('external_tsa_failed'));
                        } catch (_) { notify(text('external_tsa_register_ajax')); }
                        finally {
                            busy = false; fields.disabled = false; ctx.buttons.setLoading('register', false);
                            ctx.buttons.enable('register'); ctx.buttons.enable('cancel'); ctx.setCloseButton('cancel');
                        }
                        return false;
                    });
                    submit = event => { event.preventDefault(); if (!busy) ctx.buttons.trigger('register'); };
                    form.addEventListener('submit', submit);
                },
            });
            if (result?.registered) reload('registered');
        } catch (_) { notify(text('external_tsa_failed')); }
        finally {
            if (submit) form.removeEventListener('submit', submit);
            form.reset(); host.appendChild(body); registration.disabled = false;
        }
    });
    // Each CA dialog owns its controls; the provider ID is pinned to the dialog, never selected in the form.
    return {
        policy(id, setBusy, saved) {
            const body = document.getElementById('pdf-sealer-timestamp-policy').content.cloneNode(true).firstElementChild;
            const fields = body.querySelector('fieldset'), source = body.querySelector('[data-timestamp-source]');
            const fallback = body.querySelector('[data-timestamp-fallback]'), save = body.querySelector('[data-timestamp-save]');
            const alternatives = [...body.querySelectorAll('[data-timestamp-alternative]')];
            const policy = policies.find(item => item.id === id);
            let busy = false, invalid = false;
            const sync = () => {
                [source, ...alternatives].forEach((select, position) => {
                    [...select.options].forEach(option => {
                        const external = sources.find(item => item.id === option.value);
                        if (!external) return;
                        option.textContent = external.retired ? module.tt('tsa_retired_name', external.name) : external.name;
                        const previous = position === 0 ? policy?.timestamp_source : policy?.timestamp_alternatives?.[position - 1];
                        option.disabled = !!external.retired && option.value !== previous;
                    });
                });
                fallback.disabled = source.value === 'none'; if (fallback.disabled) fallback.checked = false;
                alternatives.forEach((select, index) => {
                    select.disabled = source.value === 'none' || (index === 1 && !alternatives[0].value);
                    if (select.disabled) select.value = '';
                });
                alternatives.forEach((select, index) => [...select.options].forEach(option => {
                    const external = sources.find(item => item.id === option.value);
                    const retiredChoice = external?.retired && option.value !== policy?.timestamp_alternatives?.[index];
                    option.disabled = !!retiredChoice || (!!option.value && (option.value === source.value
                        || alternatives.some((other, otherIndex) => otherIndex !== index && other.value === option.value)));
                }));
            };
            source.value = policy?.timestamp_source || 'none'; fallback.checked = !!policy?.bb_fallback;
            alternatives.forEach((select, index) => { select.value = policy?.timestamp_alternatives?.[index] || ''; });
            source.addEventListener('change', () => { fallback.checked = false; alternatives.forEach(select => { select.value = ''; }); sync(); });
            alternatives.forEach(select => select.addEventListener('change', sync)); sync();
            save.addEventListener('click', async () => {
                if (busy || invalid || save.disabled || fields.disabled || !source.reportValidity()) return;
                const order = alternatives.filter(select => !select.disabled && select.value).map(select => select.value);
                if (new Set([source.value, ...order]).size !== order.length + 1) { notify(text('timestamp_order_invalid')); return; }
                const payload = {provider: id, source: source.value, alternatives: order, fallback: !fallback.disabled && fallback.checked};
                busy = true; fields.disabled = true; setBusy(true);
                try {
                    const response = await module.ajax('save_provider_timestamp', payload);
                    if (!response?.ok) throw new Error('Policy save failed');
                    Object.assign(policy, {timestamp_source: payload.source === 'none' ? null : payload.source,
                        timestamp_alternatives: [...order], bb_fallback: payload.fallback});
                    if (id === 'builtin-ca') {
                        const summary = document.getElementById('pdf-sealer-mode-summary');
                        if (summary) summary.textContent = text('timestamp_summary_' + (payload.source === 'none' ? 'none' : (payload.source === 'builtin-tsa' ? 'internal' : 'external')));
                    }
                    const name = sources.find(item => item.id === payload.source)?.name || source.selectedOptions[0].textContent;
                    saved(payload.source === 'none' ? text('provider_timestamp_none')
                        : (payload.source === 'builtin-tsa' ? text('provider_timestamp_internal') : name));
                    notify(text('external_tsa_saved'), 'success');
                } catch (_) {
                    invalid = true; notify(text('external_tsa_failed')); // Close and refresh before retrying an ambiguous save.
                } finally { busy = false; fields.disabled = invalid; setBusy(false); }
            });
            return body;
        },
    };
};
