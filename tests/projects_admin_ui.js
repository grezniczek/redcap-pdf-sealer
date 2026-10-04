/* Disposable UI/AJAX doubles exercise the real asset; no live REDCap or PKI writes. */
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/projects-admin.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor(tag, props = {}) {this.tag = tag; this.children = []; this.dataset = {}; this.events = {}; this.style = {}; Object.assign(this, props);}
    append(...nodes) {nodes.forEach(n => {n.parent = this; this.children.push(n);});}
    replaceChildren(...nodes) {this.children = []; this.append(...nodes);}
    addEventListener(name, handler) {this.events[name] = handler;}
    setAttribute(name, value) {this[name] = value;}
    matches(selector) {
        if (selector.startsWith('.')) return (this.className || '').split(' ').includes(selector.slice(1));
        if (selector.startsWith('#')) return this.id === selector.slice(1);
        const m = selector.match(/^\[data-([\w-]+)(?:="([^"]*)")?\]$/);
        if (m) {const k = m[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase()); return k in this.dataset && (m[2] === undefined || this.dataset[k] === m[2]);}
        return this.tag === selector;
    }
    closest(selector) {return selector.split(', ').some(s => this.matches(s)) ? this : this.parent?.closest(selector);}
    querySelectorAll(selector) {return this.children.flatMap(n => [...(n.matches(selector) ? [n] : []), ...n.querySelectorAll(selector)]);}
    querySelector(selector) {return this.querySelectorAll(selector)[0];}
    get options() {return this.children;}
}
const all = n => [n, ...n.children.flatMap(all)];
const blank = pid => ({pid, name: 'Project <' + pid + '>', status: 'production', enabled: true, deleted: false, unavailable: false,
    binding: null, certificate: null, eligible: {assign: true, change: false, cancel: false, renew: false, revoke: false}});
const assigned = (pid, {provider = 'builtin-ca', pending = false, csr = false, disabled = false} = {}) => ({...blank(pid), enabled: !disabled,
    binding: {provider_id: provider, identity_id: 'signer-' + pid, pending_provider_id: pending ? 'external-a' : null, transition_id: pending ? 'transition-' + pid : null, enrollment_id: csr ? 'csr-' + pid : null},
    certificate: {subject: '/CN=Project/O=Example', fingerprint: 'sha256-' + pid, thumbprint: 'sha1-' + pid, valid_from: 1700000000, valid_until: 2000000000, status: 'current'},
    eligible: {assign: false, change: !pending && !csr && !disabled, cancel: pending && !disabled,
        renew: provider === 'builtin-ca' && !pending && !csr && !disabled, revoke: provider === 'builtin-ca'}});
function fixture(views = [blank(1), blank(2)], {failPid, ambiguousPid, stale, previewFail, defer, refreshFail, revocationPending} = {}) {
    let current = structuredClone(views); const requests = [], notifications = [], dialogs = [], filters = [], actions = new Map();
    const host = new Node('div', {id: 'pdf-sealer-project-admin', dataset: {projects: JSON.stringify(views), unavailable: '0', statusUrl: 'https://redcap.test/external_modules/?prefix=pdf_sealer&page=project-status', providers: JSON.stringify([
        {id: 'builtin-ca', name: 'Built-in CA', kind: 'internal', retired: false}, {id: 'external-a', name: 'External <CA>', kind: 'external', retired: false}])}});
    const tableNode = new Node('table'), tbody = new Node('tbody'); tableNode.append(tbody);
    const filter = new Node('select', {id: 'pdf-sealer-project-filter', value: 'all'});
    for (const value of ['all', 'unassigned', 'csr', 'transition', 'builtin', 'external']) filter.append(new Node('option', {value}));
    const refresh = new Node('button', {id: 'pdf-sealer-project-refresh'}), count = new Node('p', {id: 'pdf-sealer-project-selection'});
    const controls = new Node('div', {id: 'pdf-sealer-project-controls'}); controls.append(filter, refresh);
    const pageCheckbox = new Node('input', {id: 'pdf-sealer-project-select-page', type: 'checkbox'});
    const searchBox = new Node('div', {className: 'dataTables_filter'}), container = new Node('div'); container.append(searchBox);
    host.append(tableNode, controls, count, pageCheckbox);
    for (const action of ['assign', 'change', 'cancel', 'renew', 'revoke']) {
        const button = new Node('button', {dataset: {projectAction: action}}); actions.set(action, button);
        host.append(button);
    }
    const module = {tt: (key, ...values) => key + (values.length ? ':' + values.join(',') : ''), ajax: async (action, payload) => {
        requests.push({action, payload: payload && structuredClone(payload)});
        if (action === 'project_admin_overview') {
            if (refreshFail && requests.some(r => r.action.startsWith('revoke_') || r.action === 'assign_ca_provider')) throw Error('Refresh unavailable');
            if (stale) current[0] = assigned(current[0].pid);
            return {ok: true, projects: structuredClone(payload ? current.filter(p => payload.pids.includes(p.pid)) : current)};
        }
        const p = current.find(p => p.pid === payload.pid);
        if (action.startsWith('preview_')) {
            if (previewFail) return {ok: false};
            return {ok: true, pid: p.pid, ...p.binding, certificate: p.certificate, issuer_valid_until: 2100000000, review_hash: 'hash-' + p.pid};
        }
        if (defer) await defer;
        if (payload.pid === failPid) return {ok: false};
        if (action === 'assign_ca_provider') Object.assign(p, assigned(p.pid, {provider: payload.provider}));
        if (action === 'start_provider_transition') {
            if (payload.provider === 'external-a') { p.binding.pending_provider_id = payload.provider; p.binding.transition_id = 'new-transition'; p.eligible.change = p.eligible.renew = false; p.eligible.cancel = true; }
            else Object.assign(p, assigned(p.pid));
        }
        if (action === 'cancel_provider_transition') {p.binding.pending_provider_id = p.binding.transition_id = p.binding.enrollment_id = null; p.eligible.change = true; p.eligible.cancel = false;}
        if (action === 'renew_project_certificate' || action === 'revoke_project_certificate') {p.binding.identity_id = 'replacement'; p.certificate.fingerprint = 'replacement-sha256';}
        if (payload.pid === ambiguousPid) throw Error('Lost response');
        return {ok: true, crl_published: !revocationPending, replacement: revocationPending ? 'pending' : 'renewed'};
    }};
    const table = {options: null, adjustments: 0, draws: [], dataRows: [...tbody.children], pageIndex: 0, drawHandler: null,
        clear() {this.dataRows = []; tbody.replaceChildren(); return this;}, draw(reset) {this.draws.push(reset); this.drawHandler?.(); return this;},
        columns: {adjust() {table.adjustments++;}}};
    table.row = row => ({invalidate: kind => {assert.equal(kind, 'dom'); return table;}});
    table.row.add = row => {table.dataRows.push(row); tbody.append(row); return table;};
    table.table = () => ({container: () => container});
    table.rows = options => {assert.equal(options.page, 'current'); return {nodes: () => ({toArray: () => {
        const visible = table.dataRows.filter((row, index) => filters[0]({nTable: tableNode, aoData: table.dataRows.map(nTr => ({nTr}))}, [], index));
        return visible.slice(table.pageIndex * 5, table.pageIndex * 5 + 5);
    }})};};
    const jquery = () => ({on: (event, handler) => {assert.equal(event, 'draw.dt'); table.drawHandler = handler;}, DataTable: options => {table.options = options; table.dataRows = [...tbody.children]; return table;}});
    jquery.fn = {dataTable: {ext: {search: filters}}};
    const rcDialog = options => {
        let resolve; const promise = new Promise(r => {resolve = r;}); const handlers = {}, controls = {cancel: {}, confirm: {}};
        const ctx = {on: (key, fn) => {handlers[key] = fn;}, buttons: {enable: id => {controls[id].disabled = false;}, disable: id => {controls[id].disabled = true;}}, setCloseButton: value => {ctx.closeButton = value;}};
        options.setup(ctx); const body = options.body(ctx);
        const dialog = {options, body, controls, ctx,
            close(value = null) {if (handlers['dialog:beforeClose']?.() === false) return false; resolve(value); return true;},
            async confirm() {const value = await handlers['button:confirm'](); if (value !== false) this.close(value); return value;}};
        dialogs.push(dialog); return promise;
    };
    const context = {document: {getElementById: id => id === host.id ? host : {hidden: false}, createElement: tag => new Node(tag), createTextNode: text => new Node('#text', {textContent: text}), querySelector: () => new Node('button')},
        URL, location: {href: 'https://redcap.test/external_modules/?prefix=pdf_sealer&page=pki-admin'}, window: {rcDialog, PDFSealerNotify: (text, tone) => notifications.push({text, tone}), addEventListener() {}}, $: jquery};
    vm.runInNewContext(source, context); context.window.PDFSealerProjectsAdmin(module);
    const row = pid => table.dataRows.find(r => Number(r.dataset.projectPid) === pid);
    return {host, actions, table, tableNode, count, filter, refresh, pageCheckbox, searchBox, controls, requests, notifications, dialogs, context, row,
        page(index) {table.pageIndex = index; table.draw(false);},
        selectPage(checked) {pageCheckbox.checked = checked; pageCheckbox.events.change();},
        select(pid) {const input = row(pid).querySelector('input'); input.checked = !input.checked; tableNode.events.change({target: input});},
        async open(action) {const completion = actions.get(action).events.click(); await tick(); return {completion};},
        choose(dialog, value) {const select = all(dialog.body).find(n => n.tag === 'select'); select.value = value; select.events.change();},
        visible() {return table.dataRows.filter((r, i) => filters[0]({nTable: tableNode, aoData: table.dataRows.map(nTr => ({nTr}))}, [], i));}};
}
(async () => {
    let f = fixture(Array.from({length: 8}, (_, index) => blank(index + 1)));
    assert.equal(f.controls.parent, f.searchBox, 'Preset/refresh must follow the DataTables search box');
    assert.equal(f.pageCheckbox.disabled, false); f.page(1); f.select(8); f.page(0);
    f.selectPage(true); assert.equal(f.count.textContent, 'provider_selection_count:6');
    assert.ok([1,2,3,4,5,8].every(pid => f.row(pid).querySelector('input').checked));
    assert.equal(f.row(6).querySelector('input').checked, false); assert.equal(f.pageCheckbox.checked, true);
    f.selectPage(false); assert.equal(f.count.textContent, 'provider_selection_one:1');
    assert.equal(f.row(8).querySelector('input').checked, true, 'Clearing current page must retain other-page checks');
    f.select(1); assert.equal(f.pageCheckbox.indeterminate, true);
    const link = f.row(1).querySelector('a'); assert.equal(new URL(link.href).searchParams.get('pid'), '1');
    assert.equal(new URL(link.href).searchParams.get('page'), 'project-status');
    assert.equal(link.target, '_blank'); assert.equal(link.rel, 'noopener');
    f.tableNode.events.click({target: link}); assert.equal(f.row(1).querySelector('input').checked, true, 'Following a PID must not toggle selection');
    const icon = f.row(1).children[3].querySelector('i'); assert.equal(icon.title, 'provider_project_production');
    assert.equal(f.row(1).children[3].querySelector('span').textContent, 'provider_project_production', 'Status text must remain searchable');
    f = fixture([blank(1), assigned(2, {pending: true, csr: true}), assigned(3, {disabled: true})]);
    assert.equal(f.table.options.pageLength, 5); assert.equal(f.table.options.lengthChange, false);
    f.select(1); assert.equal(f.actions.get('assign').disabled, false); assert.equal(f.actions.get('change').disabled, true);
    f.table.draw(false); assert.equal(f.count.textContent, 'provider_selection_one:1', 'Paging/search must retain selection');
    f.filter.value = 'csr'; f.filter.events.change(); assert.equal(f.count.textContent, 'provider_selection_count:0');
    assert.deepEqual(f.visible().map(r => r.dataset.projectPid), ['2']);
    f.filter.value = 'all'; f.filter.events.change(); f.select(3);
    assert.equal(f.actions.get('revoke').disabled, false); assert.equal(f.actions.get('renew').disabled, true, 'Disabled projects may revoke but not renew');
    f.select(1); assert.equal(f.actions.get('revoke').disabled, true, 'Every selected row must qualify');
    assert.ok(all(f.host).some(n => n.textContent === 'Project <1>'), 'Names should be DOM text');

    f = fixture(); f.select(1); let opened = await f.open('assign'), dialog = f.dialogs[0];
    assert.equal(dialog.body.className, 'pdf-sealer-dialog-body'); assert.equal(all(dialog.body).filter(n => n.tag === 'table').length, 0);
    assert.equal(dialog.controls.confirm.disabled, true); dialog.close(); await opened.completion;
    assert.equal(f.requests.length, 1, 'Cancel must only read overview');

    f = fixture(undefined, {failPid: 2}); f.select(1); f.select(2); opened = await f.open('assign'); dialog = f.dialogs[0]; f.choose(dialog, 'external-a');
    await dialog.confirm(); await opened.completion;
    assert.equal(f.row(1).querySelector('input').checked, false); assert.equal(f.row(2).querySelector('input').checked, true);
    assert.equal(f.notifications[0].tone, 'error'); assert.ok(f.notifications[0].text.includes('projects_failed_pids:2'));
    assert.ok(all(f.row(1)).some(n => n.textContent === 'External <CA>'));

    f = fixture([assigned(1)]); f.select(1); opened = await f.open('change'); dialog = f.dialogs[0];
    assert.equal(all(dialog.body).find(n => n.tag === 'select').options.length, 2, 'Current providers must be excluded');
    f.choose(dialog, 'external-a'); await dialog.confirm(); await opened.completion;
    assert.equal(f.requests.find(r => r.action === 'start_provider_transition').payload.review_hash, 'hash-1');
    assert.ok(all(f.row(1)).some(n => n.textContent === 'transition_waiting_provider:External <CA>'));
    f.select(1); opened = await f.open('cancel'); dialog = f.dialogs.at(-1); await dialog.confirm(); await opened.completion;
    assert.equal(f.requests.find(r => r.action === 'cancel_provider_transition').payload.review_hash, 'hash-1');
    assert.equal(f.actions.get('cancel').disabled, true);

    f = fixture([assigned(1, {pending: true, csr: true})]); f.select(1); opened = await f.open('cancel'); dialog = f.dialogs[0];
    assert.ok(all(dialog.body).some(n => n.textContent === 'transition_cancel_help'));
    await dialog.confirm(); await opened.completion;
    assert.ok(!all(f.row(1)).some(n => n.textContent === 'projects_pending_csr'));

    for (const action of ['renew', 'revoke']) {
        f = fixture([assigned(1)], {revocationPending: action === 'revoke'}); f.select(1); opened = await f.open(action); dialog = f.dialogs[0];
        assert.ok(all(dialog.body).some(n => n.textContent === 'sha256-1'), 'Confirm must identify the reviewed certificate');
        if (action === 'revoke') {const radio = all(dialog.body).find(n => n.value === 'compromise'); radio.checked = true; radio.events.change();}
        await dialog.confirm(); await opened.completion;
        const write = f.requests.find(r => r.action === (action === 'renew' ? 'renew_project_certificate' : 'revoke_project_certificate'));
        assert.equal(write.payload.review_hash, 'hash-1'); if (action === 'revoke') assert.equal(write.payload.reason, 'compromise');
        assert.equal(f.row(1).querySelector('input').checked, false);
        assert.equal(f.notifications[0].tone, action === 'revoke' ? 'warning' : 'success');
    }
    f = fixture([blank(1)], {stale: true}); f.select(1); await (await f.open('assign')).completion;
    assert.equal(f.dialogs.length, 0); assert.ok(f.requests.every(r => r.action === 'project_admin_overview'));
    assert.equal(f.notifications[0].text, 'projects_review_failed');
    f = fixture([assigned(1)], {previewFail: true}); f.select(1); await (await f.open('renew')).completion;
    assert.equal(f.dialogs.length, 0); assert.ok(f.requests.every(r => r.action.startsWith('preview_') || r.action === 'project_admin_overview'));
    f = fixture([blank(1)], {ambiguousPid: 1}); f.select(1); opened = await f.open('assign'); dialog = f.dialogs[0]; f.choose(dialog, 'builtin-ca');
    await dialog.confirm(); await opened.completion; assert.equal(f.requests.filter(r => r.action === 'assign_ca_provider').length, 1);
    assert.equal(f.row(1).querySelector('input').checked, true); assert.equal(f.actions.get('assign').disabled, true, 'An ambiguous result must refresh but never replay');
    f = fixture([blank(1)], {refreshFail: true}); f.select(1); opened = await f.open('assign'); dialog = f.dialogs[0]; f.choose(dialog, 'builtin-ca');
    await dialog.confirm(); await opened.completion; assert.equal(f.row(1).querySelector('input').disabled, true);
    assert.equal(f.notifications[0].tone, 'warning');
    let release; f = fixture([blank(1)], {defer: new Promise(r => {release = r;})}); f.select(1); opened = await f.open('assign'); dialog = f.dialogs[0]; f.choose(dialog, 'builtin-ca');
    const pending = dialog.confirm(); await tick(); assert.equal(dialog.close(), false); await dialog.confirm();
    assert.equal(f.requests.filter(r => r.action === 'assign_ca_provider').length, 1); assert.equal(f.filter.disabled, true);
    release(); await pending; await opened.completion;
    f = fixture([assigned(1)]); f.select(1); f.context.window.PDFSealerProjectsAdmin.providerChanged('builtin-ca', true);
    assert.equal(f.actions.get('renew').disabled, true); assert.equal(f.actions.get('revoke').disabled, false);
    await f.refresh.events.click(); assert.equal(f.count.textContent, 'provider_selection_count:0');
    console.log('Shared Projects UI: presets, selection, eligibility, reviews, all five workflows, partial failures, ambiguity and busy guards passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
