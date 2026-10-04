/* Real bulk transition controller; disposable paged DOM/AJAX, no live certificates or assignments. */
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), crypto = require('node:crypto');
const source = fs.readFileSync(path.join(__dirname, '../assets/provider-transition.js'), 'utf8');
const labels = Object.fromEntries([...fs.readFileSync(path.join(__dirname, '../lang/English.ini'), 'utf8').matchAll(/^([a-z_0-9]+) = "(.*)"$/gm)].map(m => [m[1], m[2]]));
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor(tag, id) {this.tag = tag; this.id = id; this.children = []; this.dataset = {}; this.events = {}; this.attributes = {};}
    append(...nodes) {nodes.forEach(node => {node.parent?.removeChild(node); this.children.push(node); node.parent = this;});}
    removeChild(node) {this.children.splice(this.children.indexOf(node), 1); node.parent = null;}
    replaceChildren(...nodes) {[...this.children].forEach(node => this.removeChild(node)); this.append(...nodes);}
    matches(selector) {return selector.startsWith('#') ? this.id === selector.slice(1) : selector.startsWith('[data-') ? selector.slice(6, -1).replace(/-([a-z])/g, (_, c) => c.toUpperCase()) in this.dataset : this.tag === selector;}
    querySelectorAll(selector) {return this.children.flatMap(n => [...(n.matches(selector) ? [n] : []), ...n.querySelectorAll(selector)]);}
    querySelector(selector) {return this.querySelectorAll(selector)[0];}
    closest(selector) {return this.matches(selector) ? this : this.parent?.closest(selector);}
    addEventListener(key, handler) {(this.events[key] ??= new Set()).add(handler);}
    removeEventListener(key, handler) {this.events[key]?.delete(handler);}
    async fire(key, event = {}) {for (const handler of this.events[key] ?? []) await handler(event);}
    setAttribute(key, value) {this.attributes[key] = value;}
    getAttribute(key) {return this.attributes[key] ?? null;}
}
const text = node => [node.textContent || '', ...node.children.map(text)].join(' ');
const digest = state => crypto.createHash('sha256').update(JSON.stringify(state)).digest('hex');
function fixture({size = 12, pending = false, csr = false, noProviders = false, unavailable = false, fail = [], reject = [], ambiguous = [], readFail = [], afterReadFail = [], defer = null} = {}) {
    const form = new Node('form', 'pdf-sealer-transition'), fields = new Node('fieldset'); form.append(fields);
    const catalog = {'builtin-ca': {name: 'Built-in CA', kind: 'internal'}, 'external-a': {name: 'CA <A>', kind: 'external'}, 'external-b': {name: 'CA <B>', kind: 'external'}};
    form.dataset.providers = JSON.stringify(catalog); form.dataset.projectsUnavailable = unavailable ? '1' : '0';
    const tableNode = new Node('table', 'pdf-sealer-transition-projects'), tbody = new Node('tbody'); tableNode.append(tbody); fields.append(tableNode);
    const target = new Node('select', 'transition-provider'); target.value = ''; target.options = [{value: ''}];
    if (!noProviders) target.options.push({value: 'builtin-ca'}, {value: 'external-b'}); fields.append(target);
    const button = new Node('button', 'transition-confirm'), cancel = new Node('button', 'transition-cancel-selected');
    const count = new Node('p', 'pdf-sealer-transition-count'), help = new Node('p', 'transition-action-help'); fields.append(button, cancel, count, help);
    const rows = new Map(), views = new Map(), calls = [], notifications = [], draws = [], readCounts = new Map();
    for (let i = 1; i <= size; i++) {
        const pid = String(100 + i), row = new Node('tr'); row.dataset.transitionPid = pid;
        const state = {pid: Number(pid), uuid: 'uuid-' + pid, provider_id: 'external-a', identity_id: 'signer-' + pid,
            pending_provider_id: pending ? 'external-b' : null, transition_id: pending ? 'pending-' + pid : null, enrollment_id: csr ? 'csr-' + pid : null};
        views.set(pid, state); row.dataset.transitionState = JSON.stringify(state);
        const input = new Node('input'); input.dataset.transitionSelect = ''; input.checked = false; const cell = new Node('td'); cell.append(input); row.append(cell);
        row.append(new Node('td'), Object.assign(new Node('td'), {textContent: 'Project ' + String(i).padStart(2, '0')}), new Node('td'));
        const info = new Node('td'); info.dataset.transitionInfo = ''; row.append(info); tbody.append(row); rows.set(pid, row);
    }
    let page = 0, filter = '', options, destroyed = false;
    const render = () => {tbody.replaceChildren(); [...rows.values()].filter(row => row.children[2].textContent.includes(filter)).slice(page * 5, page * 5 + 5).forEach(row => tbody.append(row));};
    const table = {columns: {adjust() {}}, row: row => ({invalidate: kind => {assert.equal(kind, 'dom'); return {draw: reset => {draws.push(reset); render();}};}}),
        destroy: () => {destroyed = true; [...rows.values()].forEach(row => tbody.append(row));}};
    const module = {tt: (key, ...values) => {assert.ok(key in labels, 'Missing label ' + key); return labels[key].replace(/{([0-9]+)}/g, (_, i) => String(values[i]));},
        ajax: async (action, payload) => {
            calls.push({action, payload}); const pid = String(payload.pid), state = views.get(pid);
            if (defer && action !== 'preview_provider_transition') await defer;
            if (action === 'preview_provider_transition') {
                const n = (readCounts.get(pid) || 0) + 1; readCounts.set(pid, n);
                if (readFail.includes(payload.pid) || (n > 1 && afterReadFail.includes(payload.pid))) return {ok: false};
                return {ok: true, ...state, review_hash: digest(state)};
            }
            assert.equal(payload.review_hash, digest(state), 'Mutation must use the fresh matching review hash');
            if (fail.includes(payload.pid)) return {ok: false};
            if (reject.includes(payload.pid)) throw Error('Request rejected');
            if (action === 'cancel_provider_transition') {
                assert.ok(state.transition_id); state.pending_provider_id = null; state.transition_id = null; state.enrollment_id = null;
            } else {
                assert.equal(action, 'start_provider_transition'); assert.equal(state.pending_provider_id, null); assert.equal(state.enrollment_id, null);
                if (payload.provider === 'builtin-ca') {state.provider_id = 'builtin-ca'; state.identity_id = 'new-signer-' + pid;}
                else {state.pending_provider_id = payload.provider; state.transition_id = 'new-transition-' + pid;}
            }
            if (ambiguous.includes(payload.pid)) throw Error('Response lost after commit');
            return {ok: true, state: action === 'cancel_provider_transition' ? 'canceled' : payload.provider === 'builtin-ca' ? 'activated' : 'pending'};
        }};
    const context = {document: {createElement: tag => new Node(tag), getElementById: id => id === form.id ? form : null},
        window: {PDFSealerNotify: (message, tone) => notifications.push({message, tone})},
        $: node => ({DataTable: config => {options = config; render(); return table;}})};
    vm.runInNewContext(source, context); const mount = () => context.window.PDFSealerProjectTransitions(module, form); const cleanup = mount();
    return {form, fields, target, button, cancel, count, help, rows, views, calls, notifications, draws, tableNode, tbody, cleanup, mount,
        add: project => context.window.PDFSealerProjectTransitions.addProject(module, project),
        get options() {return options;}, get destroyed() {return destroyed;},
        page: n => {page = n; render();}, filter: term => {filter = term; page = 0; render();},
        choose: async (pid, checked = true) => {assert.ok(tbody.children.includes(rows.get(pid))); const input = rows.get(pid).querySelector('input'); input.checked = checked; await tableNode.fire('change', {target: input});},
        clickRow: pid => tableNode.fire('click', {target: rows.get(pid).querySelector('[data-transition-info]')}),
        provider: async value => {target.value = value; await target.fire('change');},
        start: () => form.fire('submit', {preventDefault() {}}), cancelSelected: () => cancel.fire('click'),
    };
}
(async () => {
    let f = fixture({fail: [112]}); assert.equal(f.options.pageLength, 5); assert.equal(f.options.lengthChange, false); assert.equal(f.calls.length, 0);
    await f.clickRow('101'); f.page(2); await f.choose('112'); f.filter('Project 05'); await f.choose('105');
    assert.equal(f.count.textContent, '3 projects selected'); await f.provider('external-b'); await f.start();
    assert.deepEqual(f.calls.filter(c => c.action === 'start_provider_transition').map(c => c.payload.pid), [101, 112, 105]);
    assert.equal(f.rows.get('101').querySelector('input').checked, false); assert.equal(f.rows.get('112').querySelector('input').checked, true);
    const info = text(f.rows.get('101').querySelector('[data-transition-info]'));
    assert.ok(info.includes('CA <A>') && info.includes('Pending provider: CA <B>') && info.includes('active signing identity'));
    assert.equal(f.count.textContent, '1 project selected'); assert.equal(f.notifications.length, 1); assert.equal(f.notifications[0].tone, 'error');
    assert.ok(f.notifications[0].message.includes('2 project(s)') && f.notifications[0].message.includes('112'));
    assert.ok(f.draws.every(reset => reset === false)); f.cleanup(); assert.equal(f.destroyed, true);
    assert.equal(f.form.events.submit.size, 0); assert.equal(f.tableNode.events.click.size, 0); assert.equal(f.rows.get('112').querySelector('input').checked, false);
    f.target.value = ''; const cleanup = f.mount(); assert.equal(f.count.textContent, '0 projects selected');
    assert.equal(JSON.parse(f.rows.get('101').dataset.transitionState).pending_provider_id, 'external-b'); cleanup();

    f = fixture({size: 1}); await f.choose('101'); await f.provider('builtin-ca'); await f.start();
    assert.ok(text(f.rows.get('101').querySelector('[data-transition-info]')).includes('Built-in CA'));
    assert.equal(f.views.get('101').identity_id, 'new-signer-101'); assert.equal(f.rows.get('101').querySelector('input').checked, false); f.cleanup();
    f = fixture({size: 1, pending: true, csr: true, noProviders: true}); await f.choose('101');
    assert.equal(f.button.disabled, true); assert.equal(f.cancel.disabled, false); await f.cancelSelected();
    assert.equal(f.views.get('101').provider_id, 'external-a'); assert.equal(f.views.get('101').identity_id, 'signer-101');
    assert.equal(f.views.get('101').enrollment_id, null); assert.equal(f.views.get('101').transition_id, null);
    assert.equal(f.rows.get('101').querySelector('input').checked, false); assert.equal(f.notifications[0].tone, 'success'); f.cleanup();
    f = fixture({size: 2, pending: true, fail: [102]}); await f.choose('101'); await f.choose('102'); await f.cancelSelected();
    assert.equal(f.rows.get('101').querySelector('input').checked, false); assert.equal(f.rows.get('102').querySelector('input').checked, true);
    assert.equal(f.cancel.disabled, false); assert.equal(f.notifications[0].tone, 'error'); f.cleanup();

    f = fixture({size: 1}); await f.choose('101'); await f.provider('builtin-ca'); f.views.get('101').identity_id = 'changed-in-another-tab'; await f.start();
    assert.equal(f.calls.filter(c => c.action !== 'preview_provider_transition').length, 0, 'Unseen state changes must only refresh the row');
    assert.equal(f.rows.get('101').querySelector('input').checked, true); assert.equal(f.notifications[0].tone, 'error');
    await f.start(); assert.equal(f.views.get('101').provider_id, 'builtin-ca'); f.cleanup();
    f = fixture({size: 1, ambiguous: [101]}); await f.choose('101'); await f.provider('external-b'); await f.start();
    assert.equal(f.rows.get('101').querySelector('input').checked, true); assert.equal(f.button.disabled, true);
    assert.ok(text(f.rows.get('101').querySelector('[data-transition-info]')).includes('Pending provider: CA <B>'));
    await f.start(); assert.equal(f.calls.filter(c => c.action === 'start_provider_transition').length, 1, 'Ambiguous committed changes must not replay'); f.cleanup();
    for (const options of [{readFail: [101]}, {afterReadFail: [101]}, {reject: [101]}]) {
        f = fixture({size: 1, ...options}); await f.choose('101'); await f.provider('external-b'); await f.start();
        assert.equal(f.notifications[0].tone, 'error');
        if (options.afterReadFail) {assert.equal(f.rows.get('101').querySelector('input').checked, false); assert.equal(f.rows.get('101').querySelector('input').disabled, true);}
        else assert.equal(f.rows.get('101').querySelector('input').checked, true);
        f.cleanup();
    }
    let release; const defer = new Promise(resolve => {release = resolve;});
    f = fixture({size: 1, defer}); await f.choose('101'); await f.provider('builtin-ca'); const pending = f.start(); await tick();
    assert.equal(f.form.getAttribute('aria-busy'), 'true'); assert.equal(f.fields.disabled, true); await f.choose('101', false);
    assert.equal(f.rows.get('101').querySelector('input').checked, true); await f.start(); await f.cancelSelected();
    assert.equal(f.calls.filter(c => c.action !== 'preview_provider_transition').length, 1); release(); await pending;
    assert.equal(f.form.getAttribute('aria-busy'), 'false'); f.cleanup();
    for (const options of [{size: 0}, {size: 1, csr: true}, {size: 1, unavailable: true}]) {
        f = fixture(options); if (options.size) await f.choose('101'); await f.provider('builtin-ca'); await f.start(); assert.equal(f.calls.length, 0); f.cleanup();
    }
    f = fixture({size: 1}); await f.choose('101'); await f.provider('external-a'); assert.equal(f.button.disabled, true); await f.start(); assert.equal(f.calls.length, 0); f.cleanup();
    f.add({pid: '999', name: '<img onerror=bad>', status: 'Production', provider: 'builtin-ca'});
    const added = f.tbody.children.find(row => row.dataset.transitionPid === '999'); assert.equal(added.children[2].textContent, '<img onerror=bad>');
    assert.equal(JSON.parse(added.dataset.transitionState).provider_id, 'builtin-ca'); assert.equal(added.querySelector('input').getAttribute('aria-label'), 'Select project 999');
    f.add({pid: '999', name: 'Duplicate', status: 'Production', provider: 'builtin-ca'}); assert.equal(f.tbody.children.filter(row => row.dataset.transitionPid === '999').length, 1);
    console.log('Bulk transitions: paging/row selection, pending/active providers, cancellation, partial errors, stale/ambiguous state, busy guards and cleanup passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
