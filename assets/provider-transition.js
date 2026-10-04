/* Provider changes keep the existing locked preview/hash services and enrollment semantics. */
(() => {
    const keys = ['provider_id', 'identity_id', 'pending_provider_id', 'transition_id', 'enrollment_id'];
    const stateOf = view => Object.fromEntries(keys.map(key => [key, view[key] ?? null]));
    const element = (tag, text, className = '') => {
        const node = document.createElement(tag); node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const render = (module, catalog, row, state) => {
        row.dataset.transitionState = JSON.stringify(state);
        const info = row.querySelector('[data-transition-info]'); info.replaceChildren();
        if (!state) { info.append(element('span', module.tt('transition_info_unavailable'), 'small text-warning')); return; }
        const name = id => catalog[id]?.name || id;
        info.append(element('div', name(state.provider_id)),
            element('div', module.tt(state.identity_id ? 'transition_has_signer' : 'transition_no_signer'), 'small text-muted'));
        if (state.pending_provider_id) info.append(element('div', module.tt('transition_waiting_provider', name(state.pending_provider_id)), 'small'));
        if (state.enrollment_id && !state.transition_id) info.append(element('div', module.tt('transition_cancel_csr_first'), 'small text-warning'));
    };
    window.PDFSealerProjectTransitions = (module, form) => {
        const tableNode = form.querySelector('#pdf-sealer-transition-projects');
        const target = form.querySelector('#transition-provider'), fields = form.querySelector('fieldset');
        const button = form.querySelector('#transition-confirm'), cancel = form.querySelector('#transition-cancel-selected');
        const count = form.querySelector('#pdf-sealer-transition-count'), help = form.querySelector('#transition-action-help');
        const catalog = JSON.parse(form.dataset.providers), selected = new Set();
        const projects = new Map([...tableNode.querySelectorAll('[data-transition-pid]')].map(row => [row.dataset.transitionPid,
            {row, input: row.querySelector('[data-transition-select]'), state: JSON.parse(row.dataset.transitionState)}]));
        let busy = false;
        const table = $(tableNode).DataTable({pageLength: 5, lengthChange: false, order: [[2, 'asc']],
            columnDefs: [{targets: 0, orderable: false, searchable: false, width: '45px'}],
            language: {
                search: module.tt('table_search'), lengthMenu: module.tt('table_length'),
                info: module.tt('table_info'), infoEmpty: module.tt('table_info_empty'),
                infoFiltered: module.tt('table_info_filtered'), zeroRecords: module.tt('table_zero'),
                emptyTable: module.tt('transition_choose_project'),
                paginate: {first: module.tt('table_first'), last: module.tt('table_last'),
                    next: module.tt('table_next'), previous: module.tt('table_previous')},
            },
        });
        const update = () => {
            const states = [...selected].map(pid => projects.get(pid).state);
            fields.disabled = busy || form.dataset.projectsUnavailable === '1' || projects.size === 0;
            target.disabled = fields.disabled || target.options.length <= 1;
            button.disabled = fields.disabled || !target.value || states.length === 0
                || states.some(state => !state || state.enrollment_id || state.pending_provider_id || state.provider_id === target.value);
            cancel.disabled = fields.disabled || states.length === 0 || states.some(state => !state?.transition_id);
            count.textContent = module.tt(selected.size === 1 ? 'provider_selection_one' : 'provider_selection_count', selected.size);
            help.textContent = target.value ? module.tt(catalog[target.value]?.kind === 'internal' ? 'transition_builtin_help' : 'transition_external_help') : '';
            projects.forEach((project, pid) => { project.input.disabled = fields.disabled || (!project.state && !selected.has(pid)); });
        };
        const select = (pid, checked) => {
            const project = projects.get(pid);
            if (!project) return;
            if (busy || fields.disabled || project.input.disabled) { project.input.checked = selected.has(pid); return; }
            project.input.checked = checked;
            if (checked) selected.add(pid); else selected.delete(pid);
            update();
        };
        const change = event => {
            if (event.target.matches('[data-transition-select]')) select(event.target.closest('[data-transition-pid]').dataset.transitionPid, event.target.checked);
        };
        const click = event => {
            if (event.target.closest('input')) return;
            const row = event.target.closest('[data-transition-pid]');
            if (row) select(row.dataset.transitionPid, !projects.get(row.dataset.transitionPid).input.checked);
        };
        const refresh = async pid => {
            try {
                const view = await module.ajax('preview_provider_transition', {pid: Number(pid)});
                if (view?.ok && view.pid === Number(pid)) return view;
            } catch (_) { /* Never infer signing state from an unavailable preview. */ }
            return null;
        };
        const show = (pid, view) => {
            const project = projects.get(pid); project.state = view ? stateOf(view) : null;
            render(module, catalog, project.row, project.state);
            table.row(project.row).invalidate('dom').draw(false);
        };
        const run = async action => {
            if (busy || (action === 'start' ? button.disabled : cancel.disabled)) return;
            const pids = [...selected], provider = target.value, internal = catalog[provider]?.kind === 'internal';
            const failed = [], unavailable = []; let successes = 0;
            busy = true; form.setAttribute('aria-busy', 'true'); update();
            try {
                for (const pid of pids) {
                    const project = projects.get(pid), displayed = project.state;
                    const preview = await refresh(pid);
                    if (!preview) { show(pid, null); failed.push(pid); update(); continue; }
                    if (!displayed || keys.some(key => (displayed[key] ?? null) !== (preview[key] ?? null))) {
                        show(pid, preview); failed.push(pid); update(); continue;
                    }
                    const payload = {pid: Number(pid), review_hash: preview.review_hash};
                    if (action === 'start') payload.provider = provider;
                    let response;
                    try { response = await module.ajax(action === 'start' ? 'start_provider_transition' : 'cancel_provider_transition', payload); }
                    catch (_) { /* Refresh ambiguous results; do not replay a provider change automatically. */ }
                    if (response?.ok) {
                        successes++; selected.delete(pid); project.input.checked = false;
                    } else { failed.push(pid); }
                    const current = await refresh(pid);
                    show(pid, current);
                    if (!current) unavailable.push(pid);
                    update();
                }
                const key = action === 'cancel' ? 'transition_bulk_canceled' : internal ? 'transition_bulk_activated' : 'transition_bulk_prepared';
                const messages = [module.tt(key, successes)];
                if (failed.length) messages.push(module.tt('transition_bulk_failed', failed.join(', ')));
                if (unavailable.length) messages.push(module.tt('transition_bulk_refresh_failed', unavailable.join(', ')));
                window.PDFSealerNotify(messages.join('\n'), failed.length || unavailable.length ? 'error' : 'success');
            } finally { busy = false; form.setAttribute('aria-busy', 'false'); update(); }
        };
        const submit = event => { event.preventDefault(); return run('start'); };
        const cancelSelected = () => run('cancel');
        tableNode.addEventListener('change', change); tableNode.addEventListener('click', click);
        target.addEventListener('change', update); form.addEventListener('submit', submit); cancel.addEventListener('click', cancelSelected);
        update(); table.columns.adjust();
        return () => {
            tableNode.removeEventListener('change', change); tableNode.removeEventListener('click', click);
            target.removeEventListener('change', update); form.removeEventListener('submit', submit); cancel.removeEventListener('click', cancelSelected);
            selected.clear(); projects.forEach(project => {project.input.checked = false;}); update(); table.destroy();
        };
    };
    // Initial assignment runs while the change-provider dialog is closed, so its table is plain DOM here.
    window.PDFSealerProjectTransitions.addProject = (module, project) => {
        const form = document.getElementById('pdf-sealer-transition');
        if (!form) return;
        const table = form.querySelector('#pdf-sealer-transition-projects');
        if ([...table.querySelectorAll('[data-transition-pid]')].some(row => row.dataset.transitionPid === project.pid)) return;
        const row = element('tr'); row.dataset.transitionPid = project.pid;
        const cell = element('td'), input = element('input'); input.type = 'checkbox'; input.dataset.transitionSelect = '';
        input.setAttribute('aria-label', module.tt('provider_select_project', project.pid)); cell.append(input); row.append(cell);
        [project.pid, project.name, project.status].forEach(text => row.append(element('td', text)));
        const info = element('td'); info.dataset.transitionInfo = ''; row.append(info);
        render(module, JSON.parse(form.dataset.providers), row,
            {provider_id: project.provider, identity_id: null, pending_provider_id: null, transition_id: null, enrollment_id: null});
        table.querySelector('tbody').append(row);
        form.querySelector('fieldset').disabled = form.dataset.projectsUnavailable === '1';
    };
})();
