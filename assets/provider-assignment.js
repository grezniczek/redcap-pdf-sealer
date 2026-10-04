/* Bulk initial assignment reuses the authenticated, locked per-project service. */
window.PDFSealerProjectAssignment = (module, form) => {
    const tableNode = form.querySelector('#pdf-sealer-assignment-projects');
    const provider = form.querySelector('#provider-selection');
    const fields = form.querySelector('fieldset');
    const button = form.querySelector('#pdf-sealer-assign-projects');
    const count = form.querySelector('#pdf-sealer-assignment-count');
    const selected = new Set();
    const projects = new Map([...tableNode.querySelectorAll('[data-assignment-pid]')].map(row => {
        const input = row.querySelector('[data-assignment-select]');
        return [row.dataset.assignmentPid, {row, input, name: row.querySelector('[data-assignment-name]').textContent}];
    }));
    let busy = false;
    const table = $(tableNode).DataTable({pageLength: 10, order: [[2, 'asc']],
        columnDefs: [{targets: 0, orderable: false, searchable: false, width: '45px'}],
        language: {
            search: module.tt('table_search'), lengthMenu: module.tt('table_length'),
            info: module.tt('table_info'), infoEmpty: module.tt('table_info_empty'),
            infoFiltered: module.tt('table_info_filtered'), zeroRecords: module.tt('table_zero'),
            emptyTable: module.tt('provider_no_unassigned_projects'),
            paginate: {first: module.tt('table_first'), last: module.tt('table_last'),
                next: module.tt('table_next'), previous: module.tt('table_previous')},
        },
    });
    const update = () => {
        form.dataset.remainingProjects = String([...projects.values()].filter(project => project.input).length);
        fields.disabled = busy || form.dataset.projectsUnavailable === '1'
            || Number(form.dataset.remainingProjects) === 0 || provider.options.length <= 1;
        button.disabled = fields.disabled || selected.size === 0 || !provider.value;
        count.textContent = module.tt(selected.size === 1 ? 'provider_selection_one' : 'provider_selection_count', selected.size);
        projects.forEach(project => { if (project.input) project.input.disabled = fields.disabled; });
    };
    const change = event => {
        if (!event.target.matches('[data-assignment-select]')) return;
        const pid = event.target.closest('[data-assignment-pid]').dataset.assignmentPid;
        const project = projects.get(pid);
        if (busy || fields.disabled) { event.target.checked = selected.has(pid); return; }
        if (!project?.input) return;
        if (event.target.checked) selected.add(pid); else selected.delete(pid);
        update();
    };
    const submit = async event => {
        event.preventDefault();
        if (busy || fields.disabled || selected.size === 0 || !form.reportValidity()) return;
        // Capture all selected pages and the provider before freezing the controls.
        const pids = [...selected], providerId = provider.value;
        const providerName = provider.selectedOptions[0].textContent;
        let successes = 0;
        const failed = [];
        busy = true; form.setAttribute('aria-busy', 'true'); update();
        try {
            for (const pid of pids) {
                let response;
                try { response = await module.ajax('assign_ca_provider', {pid: Number(pid), provider: providerId}); }
                catch (_) { /* Ambiguous requests remain selected; the endpoint safely accepts the same binding again. */ }
                if (!response?.ok) { failed.push(pid); continue; }
                successes++;
                const project = projects.get(pid);
                const mark = document.createElement('span');
                mark.className = 'text-success'; mark.textContent = '✓';
                mark.title = module.tt('provider_assignment_mark', providerName);
                mark.setAttribute('role', 'img'); mark.setAttribute('aria-label', mark.title);
                project.input.replaceWith(mark); project.input = null;
                selected.delete(pid);
                table.row(project.row).invalidate('dom').draw(false);
                const transition = document.getElementById('transition-pid');
                if (transition && ![...transition.options].some(option => option.value === pid)) {
                    transition.add(new Option('(' + pid + ') ' + project.name, pid));
                    transition.closest('fieldset').disabled = false;
                    $(transition).prop('disabled', false);
                }
                update();
            }
            window.PDFSealerNotify(module.tt(failed.length ? 'provider_assignment_partial' : 'provider_assignment_done',
                providerName, successes, failed.join(', ')), failed.length ? 'error' : 'success');
        } finally {
            busy = false; form.setAttribute('aria-busy', 'false'); update();
        }
    };
    tableNode.addEventListener('change', change);
    provider.addEventListener('change', update);
    form.addEventListener('submit', submit);
    update(); table.columns.adjust();
    return () => {
        tableNode.removeEventListener('change', change);
        provider.removeEventListener('change', update);
        form.removeEventListener('submit', submit);
        selected.clear();
        projects.forEach(project => { if (project.input) project.input.checked = false; });
        update(); table.destroy();
    };
};
