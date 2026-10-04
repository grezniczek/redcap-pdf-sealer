/* One CC project overview. All changes use the existing authenticated, locked services. */
window.PDFSealerProjectsAdmin = module => {
    const host = document.getElementById('pdf-sealer-project-admin');
    if (!host) return;
    const node = (tag, text, className = '') => {
        const result = document.createElement(tag); result.className = className;
        if (text !== undefined) result.textContent = text;
        return result;
    };
    const tt = (key, ...values) => module.tt(key, ...values);
    const notify = (key, tone, ...values) => window.PDFSealerNotify(tt(key, ...values), tone);
    const tableNode = host.querySelector('table'), filter = host.querySelector('#pdf-sealer-project-filter');
    const refreshButton = host.querySelector('#pdf-sealer-project-refresh'), count = host.querySelector('#pdf-sealer-project-selection');
    const pageCheckbox = host.querySelector('#pdf-sealer-project-select-page');
    const actions = [...host.querySelectorAll('[data-project-action]')];
    const catalog = Object.fromEntries(JSON.parse(host.dataset.providers).map(p => [p.id, p]));
    const projects = new Map(), selected = new Set();
    let busy = false, unavailable = host.dataset.unavailable === '1';
    const name = id => catalog[id]?.name || id;
    const utc = epoch => new Date(epoch * 1000).toISOString().replace('T', ' ').replace('.000Z', ' UTC');
    const presets = {
        all: () => true,
        unassigned: p => !p.unavailable && !p.binding,
        csr: p => Boolean(p.binding?.enrollment_id),
        transition: p => Boolean(p.binding?.transition_id),
        builtin: p => p.binding?.provider_id === 'builtin-ca' && Boolean(p.certificate),
        external: p => Boolean(p.binding) && catalog[p.binding.pending_provider_id || p.binding.provider_id]?.kind === 'external'
            && (!p.binding.identity_id || Boolean(p.binding.pending_provider_id) || Boolean(p.binding.enrollment_id)),
    };
    const stateKeys = ['provider_id', 'identity_id', 'pending_provider_id', 'transition_id', 'enrollment_id'];
    const sameBinding = (a, b) => stateKeys.every(key => (a?.[key] ?? null) === (b?.[key] ?? null));
    const add = project => {
        const row = node('tr'); row.dataset.projectPid = String(project.pid);
        const input = node('input'); input.type = 'checkbox'; input.dataset.projectSelect = '';
        input.setAttribute('aria-label', tt('provider_select_project', project.pid));
        const link = node('a', project.pid), url = new URL(host.dataset.statusUrl, location.href);
        url.searchParams.set('pid', String(project.pid)); link.href = url.href;
        link.target = '_blank'; link.rel = 'noopener';
        const pidCell = node('td'); pidCell.append(link);
        const selection = node('td'); selection.append(input); row.append(selection, pidCell);
        for (let i = 0; i < 4; i++) row.append(node('td'));
        const record = {view: project, row, input}; projects.set(project.pid, record);
        render(record); return row;
    };
    const render = record => {
        const p = record.view, cells = record.row.children;
        record.input.checked = selected.has(p.pid);
        cells[2].replaceChildren(node('div', p.name));
        if (!p.enabled) cells[2].append(node('div', tt('projects_disabled'), 'small text-muted'));
        if (p.deleted) cells[2].append(node('div', tt('projects_deleted'), 'small text-warning'));
        const status = p.deleted ? tt('projects_deleted') : tt('provider_project_' + p.status);
        // Match REDCap Classes/RenderProjectList.php, including deleted-project precedence.
        const icons = {development: ['fas fa-wrench', '#444'], production: ['far fa-check-square', '#00A000'],
            analysis: ['fas fa-minus-circle', '#A00000'], completed: ['fa fa-archive fs11', '#C00000'],
            deleted: ['fas fa-times', '#C00000'], unknown: ['fas fa-question-circle', '#6c757d']};
        const [iconClass, color] = icons[p.deleted ? 'deleted' : p.status] || icons.unknown;
        const icon = node('i', undefined, iconClass);
        icon.style.color = color; icon.style.fontSize = '14px';
        icon.title = status; icon.setAttribute('aria-hidden', 'true');
        cells[3].replaceChildren(icon, node('span', status, 'pdf-sealer-status-text'));
        cells[4].replaceChildren(); cells[5].replaceChildren();
        if (p.unavailable) {
            cells[4].append(node('span', tt('transition_info_unavailable'), 'small text-warning'));
            cells[5].append(node('span', tt('projects_unknown'), 'small text-warning')); return;
        }
        const binding = p.binding;
        cells[4].append(node('div', binding ? name(binding.provider_id) : tt('projects_unassigned')));
        if (binding) {
            cells[4].append(node('div', tt(binding.identity_id ? 'transition_has_signer' : 'transition_no_signer'), 'small text-muted'));
            if (binding.pending_provider_id) cells[4].append(node('div', tt('transition_waiting_provider', name(binding.pending_provider_id)), 'small'));
            if (binding.enrollment_id) cells[4].append(node('div', tt('projects_pending_csr'), 'small text-warning'));
        }
        if (!p.certificate) { cells[5].append(node('span', tt('projects_no_certificate'), 'small text-muted')); return; }
        const certificate = p.certificate;
        cells[5].append(node('div', tt('projects_cert_' + certificate.status), certificate.status === 'current' ? 'small' : 'small text-warning'),
            node('div', utc(certificate.valid_until), 'small text-muted'));
    };
    [...JSON.parse(host.dataset.projects)].forEach(project => tableNode.querySelector('tbody').append(add(project)));
    const scopedFilter = (settings, _data, index) => {
        if (settings.nTable !== tableNode) return true;
        const row = settings.aoData[index].nTr;
        return presets[filter.value](projects.get(Number(row.dataset.projectPid)).view);
    };
    $.fn.dataTable.ext.search.push(scopedFilter);
    const table = $(tableNode).DataTable({autoWidth: false, pageLength: 5, lengthChange: false, order: [[2, 'asc']],
        columnDefs: [{targets: 0, orderable: false, searchable: false}, {targets: 3, orderable: false}],
        language: {search: tt('table_search'), info: tt('table_info'), infoEmpty: tt('table_info_empty'),
            infoFiltered: tt('table_info_filtered'), zeroRecords: tt('table_zero'), emptyTable: tt('projects_empty'),
            paginate: {first: tt('table_first'), last: tt('table_last'), next: tt('table_next'), previous: tt('table_previous')}},
    });
    const searchBox = table.table().container().querySelector('.dataTables_filter');
    searchBox.append(host.querySelector('#pdf-sealer-project-controls'));
    const pageRecords = () => table.rows({page: 'current'}).nodes().toArray()
        .map(row => projects.get(Number(row.dataset.projectPid))).filter(record => record && !record.input.disabled);
    const updatePageCheckbox = () => {
        const records = pageRecords(), checked = records.filter(record => selected.has(record.view.pid)).length;
        pageCheckbox.disabled = busy || unavailable || records.length === 0;
        pageCheckbox.checked = records.length > 0 && checked === records.length;
        pageCheckbox.indeterminate = checked > 0 && checked < records.length;
    };
    $(tableNode).on('draw.dt', updatePageCheckbox);
    const qualifying = (p, action) => p.eligible[action] && (action !== 'renew' || !catalog['builtin-ca']?.retired);
    const update = () => {
        const views = [...selected].map(pid => projects.get(pid)?.view);
        actions.forEach(button => {
            const action = button.dataset.projectAction;
            const eligible = views.length > 0 && views.every(p => p && qualifying(p, action));
            const targetAvailable = !['assign', 'change'].includes(action) || Object.values(catalog).some(provider => !provider.retired
                && (action !== 'change' || views.every(p => p?.binding?.provider_id !== provider.id)));
            const ready = eligible && targetAvailable;
            button.disabled = busy || unavailable || !ready;
        });
        count.textContent = tt(selected.size === 1 ? 'provider_selection_one' : 'provider_selection_count', selected.size);
        projects.forEach(record => { record.input.disabled = busy || unavailable || record.view.unavailable; });
        filter.disabled = busy || unavailable; refreshButton.disabled = busy;
        for (const option of filter.options) {
            option.textContent = tt('projects_filter_count', tt('projects_filter_' + option.value),
                [...projects.values()].filter(r => presets[option.value](r.view)).length);
        }
        updatePageCheckbox();
        host.setAttribute('aria-busy', String(busy));
    };
    const select = (pid, checked) => {
        const record = projects.get(pid);
        if (!record || record.input.disabled) return;
        if (checked) selected.add(pid); else selected.delete(pid);
        record.input.checked = checked; update();
    };
    pageCheckbox.addEventListener('change', () => {
        if (pageCheckbox.disabled) return;
        const checked = pageCheckbox.checked;
        pageRecords().forEach(record => {
            if (checked) selected.add(record.view.pid); else selected.delete(record.view.pid);
            record.input.checked = checked;
        });
        update();
    });
    tableNode.addEventListener('change', event => {
        if (event.target.matches('[data-project-select]')) select(Number(event.target.closest('[data-project-pid]').dataset.projectPid), event.target.checked);
    });
    tableNode.addEventListener('click', event => {
        if (event.target.closest('input, button, a, label')) return;
        const row = event.target.closest('[data-project-pid]');
        if (row) { const pid = Number(row.dataset.projectPid); select(pid, !selected.has(pid)); }
    });
    filter.addEventListener('change', () => {
        if (busy) return;
        selected.clear(); projects.forEach(record => {record.input.checked = false;}); table.draw(); update();
    });
    const refresh = async pids => {
        const response = await module.ajax('project_admin_overview', pids ? {pids} : null);
        if (!response?.ok || !Array.isArray(response.projects)) throw new Error('Overview unavailable');
        if (!pids) {
            table.clear(); projects.clear(); selected.clear();
            response.projects.forEach(project => table.row.add(add(project))); unavailable = false;
        } else {
            for (const pid of pids) {
                const record = projects.get(pid), current = response.projects.find(p => p.pid === pid);
                if (!record) continue;
                if (current) record.view = current;
                else { record.view = {...record.view, unavailable: true, eligible: {}}; }
                render(record); table.row(record.row).invalidate('dom');
            }
        }
        table.draw(false); update();
    };
    const refreshSelected = async pids => {
        for (let offset = 0; offset < pids.length; offset += 50) await refresh(pids.slice(offset, offset + 50));
    };
    refreshButton.addEventListener('click', async () => {
        if (busy) return;
        busy = true; update();
        try { await refresh(); }
        catch (_) { unavailable = true; notify('projects_refresh_failed', 'error'); }
        finally { busy = false; update(); }
    });
    const adjust = () => { if (!document.getElementById('pki-panel-projects').hidden) table.columns.adjust(); };
    document.querySelector('[data-pki-tab="projects"]').addEventListener('click', adjust);
    window.addEventListener('hashchange', adjust);
    // Provider retirement/reactivation changes option availability without adding duplicate selectors.
    window.PDFSealerProjectsAdmin.providerChanged = (id, retired) => {
        if (catalog[id]) catalog[id].retired = retired;
        // Reactivation needs a refreshed eligibility snapshot; do not infer server readiness.
        update();
    };
    const specs = {
        assign: {title: 'provider_assign', help: 'provider_assign_summary', confirm: 'provider_assign_selected', endpoint: 'assign_ca_provider'},
        change: {title: 'transition_title', help: 'provider_transition_summary', confirm: 'transition_bulk_change', preview: 'preview_provider_transition', endpoint: 'start_provider_transition'},
        cancel: {title: 'transition_cancel', help: 'transition_cancel_help', confirm: 'transition_bulk_cancel', preview: 'preview_provider_transition', endpoint: 'cancel_provider_transition'},
        renew: {title: 'renewal_title', help: 'renewal_confirm_help', confirm: 'projects_renew_confirm', preview: 'preview_project_renewal', endpoint: 'renew_project_certificate'},
        revoke: {title: 'revocation_title', help: 'revocation_warning', confirm: 'revocation_confirm', preview: 'preview_project_revocation', endpoint: 'revoke_project_certificate'},
    };
    actions.forEach(button => button.addEventListener('click', async () => {
        if (button.disabled || busy) return;
        if (typeof window.rcDialog !== 'function') { notify('provider_dialog_unavailable', 'warning'); return; }
        const action = button.dataset.projectAction, spec = specs[action], pids = [...selected];
        const displayed = new Map(pids.map(pid => [pid, projects.get(pid).view])), reviews = new Map();
        busy = true; update();
        try {
            // Refresh public eligibility, but never silently approve a different signer or binding.
            await refreshSelected(pids);
            for (const pid of pids) {
                const before = displayed.get(pid), current = projects.get(pid).view;
                if (!qualifying(current, action) || !sameBinding(before.binding, current.binding)
                    || before.certificate?.fingerprint !== current.certificate?.fingerprint) throw new Error('Selection changed');
                if (spec.preview) {
                    const preview = await module.ajax(spec.preview, {pid});
                    if (!preview?.ok || preview.pid !== pid || typeof preview.review_hash !== 'string') throw new Error('Review unavailable');
                    if ((action === 'change' || action === 'cancel') && !sameBinding(current.binding, preview)) throw new Error('Binding changed');
                    if ((action === 'renew' || action === 'revoke') && (preview.identity_id !== current.binding.identity_id
                        || preview.certificate?.fingerprint !== current.certificate?.fingerprint || preview.revoked === true)) throw new Error('Signer changed');
                    reviews.set(pid, preview);
                }
            }
            let fields, provider, reason, help, executing = false;
            await window.rcDialog({title: tt(spec.title), size: 'lg', draggable: true, closeButton: 'cancel', focusAfterClose: button,
                buttons: ['cancel', {id: 'confirm', label: tt(spec.confirm), intent: action === 'revoke' ? 'danger' : 'primary'}],
                body(ctx) {
                    const body = node('div', undefined, 'pdf-sealer-dialog-body'); fields = node('fieldset');
                    fields.append(node('p', tt(spec.help), action === 'revoke' ? 'alert alert-warning' : 'small text-muted'));
                    const list = node('ul', undefined, 'pdf-sealer-project-review');
                    for (const pid of pids) {
                        const p = projects.get(pid).view, item = node('li', '(' + pid + ') ' + p.name);
                        if (action === 'renew' || action === 'revoke') {
                            const certificate = reviews.get(pid).certificate;
                            const details = node('dl', undefined, 'pdf-sealer-certificate');
                            const subject = node('dd', certificate.subject.replace(/(?<!\\)(?=\/[A-Za-z0-9.]+=)/g, '\n').trim()); subject.style.whiteSpace = 'pre-line';
                            details.append(node('dt', tt('pki_subject')), subject,
                                node('dt', tt('trust_valid_from')), node('dd', utc(certificate.valid_from)),
                                node('dt', tt('pki_valid_until')), node('dd', utc(certificate.valid_until)),
                                node('dt', tt('pki_fingerprint')), node('dd', certificate.fingerprint, 'pdf-sealer-fingerprint'),
                                node('dt', tt('pki_thumbprint')), node('dd', certificate.thumbprint, 'pdf-sealer-fingerprint'));
                            item.append(details);
                            if (action === 'renew') item.append(node('p', tt('renewal_issuer_expiry', utc(reviews.get(pid).issuer_valid_until)), 'small text-muted'));
                        } else if (p.binding) {
                            item.append(node('div', name(p.binding.provider_id), 'small text-muted'));
                            if (p.binding.pending_provider_id) item.append(node('div', tt('transition_waiting_provider', name(p.binding.pending_provider_id)), 'small'));
                            if (p.binding.enrollment_id) item.append(node('div', tt('projects_pending_csr'), 'small text-warning'));
                        }
                        list.append(item);
                    }
                    fields.append(list);
                    if (action === 'assign' || action === 'change') {
                        const label = node('label', tt(action === 'assign' ? 'provider_name' : 'transition_target_label')); label.htmlFor = 'project-workflow-provider';
                        provider = node('select', undefined, 'form-select form-select-sm mb-2'); provider.id = label.htmlFor;
                        const empty = node('option', tt('timestamp_settings_choose')); empty.value = ''; provider.append(empty);
                        Object.values(catalog).filter(p => !p.retired && (action !== 'change' || pids.every(pid => projects.get(pid).view.binding.provider_id !== p.id)))
                            .forEach(p => { const option = node('option', p.name); option.value = p.id; provider.append(option); });
                        help = node('p', '', 'small text-muted'); fields.append(label, provider, help);
                        const changed = () => {
                            help.textContent = action === 'change' && provider.value ? tt(catalog[provider.value].kind === 'internal' ? 'transition_builtin_help' : 'transition_external_help') : '';
                            ctx.buttons[provider.value ? 'enable' : 'disable']('confirm');
                        };
                        provider.addEventListener('change', changed); changed();
                    }
                    if (action === 'revoke') {
                        fields.append(node('h6', tt('revocation_reason')));
                        for (const value of ['superseded', 'compromise']) {
                            const label = node('label', undefined, 'd-block'); const radio = node('input');
                            radio.type = 'radio'; radio.name = 'project-revocation-reason'; radio.value = value; radio.checked = value === 'superseded';
                            if (radio.checked) reason = value;
                            radio.addEventListener('change', () => { if (radio.checked) reason = value; });
                            label.append(radio, document.createTextNode(' ' + tt('revocation_' + value))); fields.append(label);
                        }
                    }
                    body.append(fields); return body;
                },
                setup(ctx) {
                    ctx.on('dialog:beforeClose', () => {
                        if (executing) return false;
                        button.disabled = false; // Let rcDialog restore focus before its promise resolves.
                        return true;
                    });
                    ctx.on('button:confirm', async () => {
                        if (executing || ((action === 'assign' || action === 'change') && (!provider.value || catalog[provider.value]?.retired))) return false;
                        executing = true; fields.disabled = true;
                        ctx.buttons.disable('confirm'); ctx.buttons.disable('cancel'); ctx.setCloseButton(false);
                        const target = provider?.value, failed = [], warnings = []; let successes = 0;
                        try {
                            for (const pid of pids) {
                                const payload = {pid}; if (target) payload.provider = target;
                                if (reviews.has(pid)) payload.review_hash = reviews.get(pid).review_hash;
                                if (action === 'revoke') payload.reason = reason;
                                let result;
                                try { result = await module.ajax(spec.endpoint, payload); } catch (_) { /* Never replay an ambiguous mutation. */ }
                                if (result?.ok) {
                                    successes++; selected.delete(pid); projects.get(pid).input.checked = false;
                                    if (action === 'revoke') {
                                        if (!result.crl_published) warnings.push(tt('projects_revocation_crl_pending', pid));
                                        if (result.replacement !== 'renewed') warnings.push(tt(result.replacement === 'skipped' ? 'projects_revocation_changed' : 'projects_revocation_replacement_pending', pid));
                                    }
                                } else failed.push(pid);
                            }
                            try { await refreshSelected(pids); }
                            catch (_) {
                                pids.forEach(pid => { const record = projects.get(pid); record.view.unavailable = true; render(record); table.row(record.row).invalidate('dom'); });
                                table.draw(false); warnings.push(tt('projects_refresh_failed'));
                            }
                            const successKey = action === 'change' ? catalog[target].kind === 'internal' ? 'transition_bulk_activated' : 'transition_bulk_prepared'
                                : 'projects_done_' + action;
                            const messages = [tt(successKey, successes), ...(failed.length ? [tt('projects_failed_pids', failed.join(', '))] : []), ...warnings];
                            window.PDFSealerNotify(messages.join('\n'), failed.length ? 'error' : warnings.length ? 'warning' : 'success');
                            return {completed: true};
                        } finally { executing = false; }
                    });
                },
            });
        } catch (_) {
            // Reviews may have raced with enrollment or maintenance. Refresh for the next explicit review.
            try { await refreshSelected(pids); } catch (_) { unavailable = true; }
            notify('projects_review_failed', 'error');
        } finally { busy = false; update(); }
    }));
    update(); adjust();
};
