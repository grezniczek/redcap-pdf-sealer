/* Real bulk controller with paged/filtered DOM rows and disposable AJAX; no live project state. */
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/provider-assignment.js'), 'utf8');
const labels = Object.fromEntries([...fs.readFileSync(path.join(__dirname, '../lang/English.ini'), 'utf8').matchAll(/^([a-z_0-9]+) = "(.*)"$/gm)].map(m => [m[1], m[2]]));
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor(tag, id) { this.tag = tag; this.id = id; this.dataset = {}; this.children = []; this.events = {}; this.attributes = {}; }
    append(node) { node.parent?.removeChild(node); this.children.push(node); node.parent = this; }
    removeChild(node) { this.children.splice(this.children.indexOf(node), 1); node.parent = null; }
    replaceWith(node) { const p = this.parent, i = p.children.indexOf(this); p.children[i] = node; node.parent = p; this.parent = null; }
    matches(selector) { return selector.startsWith('#') ? this.id === selector.slice(1) : selector.startsWith('[data-') ? selector.slice(6, -1).replace(/-([a-z])/g, (_, c) => c.toUpperCase()) in this.dataset : this.tag === selector; }
    querySelectorAll(selector) { return this.children.flatMap(n => [...(n.matches(selector) ? [n] : []), ...n.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0]; }
    closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector); }
    addEventListener(name, handler) { (this.events[name] ??= new Set()).add(handler); }
    removeEventListener(name, handler) { this.events[name]?.delete(handler); }
    async fire(name, event = {}) { for (const handler of this.events[name] ?? []) await handler(event); }
    setAttribute(key, value) { this.attributes[key] = value; }
    getAttribute(key) { return this.attributes[key] ?? null; }
    add(option) { this.options.push(option); }
}
function fixture({size = 12, request = async () => ({ok: true}), unavailable = false, providers = true} = {}) {
    const form = new Node('form'), fields = new Node('fieldset'); form.append(fields);
    form.dataset.projectsUnavailable = unavailable ? '1' : '0';
    const tableNode = new Node('table', 'pdf-sealer-assignment-projects'), tbody = new Node('tbody'); tableNode.append(tbody); fields.append(tableNode);
    const provider = new Node('select', 'provider-selection'); provider.options = [{value: '', textContent: 'Choose'}];
    if (providers) provider.options.push({value: 'external-a', textContent: '<Public CA>'});
    provider.value = ''; Object.defineProperty(provider, 'selectedOptions', {get: () => provider.options.filter(o => o.value === provider.value)});
    const button = new Node('button', 'pdf-sealer-assign-projects'), count = new Node('p', 'pdf-sealer-assignment-count');
    fields.append(provider); fields.append(button); fields.append(count);
    form.valid = true; form.reportValidity = () => form.valid && Boolean(provider.value);
    const rows = new Map(), inputs = new Map();
    for (let i = 1; i <= size; i++) {
        const pid = String(100 + i), row = new Node('tr'); row.dataset.assignmentPid = pid;
        const cell = new Node('td'), input = new Node('input'); input.dataset.assignmentSelect = ''; input.checked = false;
        cell.append(input); row.append(cell);
        const name = new Node('td'); name.dataset.assignmentName = ''; name.textContent = 'Project ' + String(i).padStart(2, '0'); row.append(name);
        tbody.append(row); rows.set(pid, row); inputs.set(pid, input);
    }
    const transitionFields = new Node('fieldset'), transition = new Node('select'); transition.options = [{value: ''}]; transitionFields.append(transition); transitionFields.disabled = true;
    const calls = [], notifications = [], draws = [];
    let tableOptions, page = 0, filter = '', destroyed = false;
    const render = () => {
        [...tbody.children].forEach(row => tbody.removeChild(row));
        const visible = [...rows.values()].filter(row => row.querySelector('[data-assignment-name]').textContent.includes(filter));
        visible.slice(page * 10, page * 10 + 10).forEach(row => tbody.append(row));
    };
    const table = {columns: {adjust() {}}, row: row => ({invalidate: kind => {
        assert.equal(kind, 'dom'); assert.ok([...rows.values()].includes(row));
        return {draw: reset => { draws.push(reset); render(); }};
    }}), destroy() { destroyed = true; [...rows.values()].forEach(row => tbody.append(row)); }};
    const module = {tt: (key, ...values) => {
        assert.ok(key in labels, 'Missing translation ' + key);
        return labels[key].replace(/{([0-9]+)}/g, (_, i) => String(values[i]));
    }, ajax: async (action, payload) => { calls.push({action, payload}); return request(payload, calls.length); }};
    const context = {document: {createElement: tag => new Node(tag), getElementById: id => id === 'transition-pid' ? transition : null},
        window: {PDFSealerNotify: (text, tone) => notifications.push({text, tone})},
        Option: function(textContent, value) {return {textContent, value};},
        $: node => ({prop: (key, value) => {node[key] = value;}, DataTable: options => {tableOptions = options; render(); return table;}})};
    vm.runInNewContext(source, context);
    const mount = () => context.window.PDFSealerProjectAssignment(module, form);
    const destroy = mount();
    return {form, fields, provider, button, count, calls, notifications, rows, inputs, transition, transitionFields, draws, tableNode,
        get options() {return tableOptions;}, get destroyed() {return destroyed;}, destroy, mount,
        page: n => {page = n; render();}, filter: text => {filter = text; page = 0; render();},
        selectProvider: async () => {provider.value = 'external-a'; await provider.fire('change');},
        choose: async (pid, checked = true) => {
            assert.ok(tbody.children.includes(rows.get(pid)), 'Project must be on the visible page to select');
            const input = inputs.get(pid); input.checked = checked;
            await tableNode.fire('change', {target: input});
        },
        submit: () => form.fire('submit', {preventDefault() {}}),
    };
}
(async () => {
    let f = fixture({request: async payload => {
        if (payload.pid === 105) return {ok: false};
        if (payload.pid === 112) throw Error('Connection failed');
        return {ok: true};
    }});
    assert.equal(f.options.pageLength, 10); assert.equal(f.button.disabled, true); assert.equal(f.calls.length, 0);
    await f.choose('101'); assert.equal(f.button.disabled, true, 'A provider must be chosen');
    f.page(1); await f.choose('112'); f.filter('Project 05'); await f.choose('105');
    assert.equal(f.count.textContent, '3 projects selected'); await f.selectProvider(); assert.equal(f.button.disabled, false);
    await f.submit();
    assert.deepEqual(f.calls.map(c => c.payload.pid), [101, 112, 105]);
    assert.ok(f.calls.every(c => c.action === 'assign_ca_provider' && c.payload.provider === 'external-a'));
    assert.equal(f.rows.get('101').querySelector('[data-assignment-select]'), undefined);
    const mark = f.rows.get('101').querySelector('span'); assert.equal(mark.textContent, '✓'); assert.equal(mark.getAttribute('aria-label'), 'Assigned provider <Public CA>');
    assert.equal(f.inputs.get('105').checked, true); assert.equal(f.inputs.get('112').checked, true);
    assert.equal(f.count.textContent, '2 projects selected'); assert.equal(f.button.disabled, false);
    assert.ok(f.draws.every(reset => reset === false), 'Assignment must preserve table paging/filter');
    assert.equal(f.notifications.length, 1); assert.equal(f.notifications[0].tone, 'error');
    assert.ok(f.notifications[0].text.includes('1 project(s)') && f.notifications[0].text.includes('112, 105'));
    assert.equal(f.transition.options[1].value, '101'); assert.equal(f.transitionFields.disabled, false);
    f.destroy(); assert.equal(f.destroyed, true); assert.equal(f.inputs.get('105').checked, false);
    assert.equal(f.form.events.submit.size, 0); assert.equal(f.tableNode.events.change.size, 0);
    f.provider.value = ''; const cleanup = f.mount(); assert.equal(f.form.dataset.remainingProjects, '11');
    assert.equal(f.rows.get('101').querySelector('span'), mark, 'Successful checkmarks survive reopening');
    assert.equal(f.count.textContent, '0 projects selected'); cleanup();

    let release; const deferred = new Promise(resolve => {release = resolve;});
    f = fixture({request: async () => {await deferred; return {ok: true};}});
    await f.selectProvider(); await f.choose('101'); f.page(1); await f.choose('112');
    const pending = f.submit(); await tick(); assert.equal(f.form.getAttribute('aria-busy'), 'true');
    assert.equal(f.button.disabled, true); assert.equal(f.fields.disabled, true);
    assert.equal(f.inputs.get('101').disabled, true, 'Detached checkboxes must also freeze');
    await f.choose('112', false); assert.equal(f.inputs.get('112').checked, true, 'Busy selection cannot change');
    await f.submit(); assert.equal(f.calls.length, 1, 'A second submission must not start another batch');
    release(); await pending;
    assert.equal(f.calls.length, 2); assert.equal(f.form.getAttribute('aria-busy'), 'false');
    assert.equal(f.count.textContent, '0 projects selected'); assert.equal(f.notifications[0].tone, 'success');
    assert.equal(f.button.disabled, true); f.destroy();

    for (const options of [{size: 0}, {unavailable: true}, {providers: false}]) {
        f = fixture(options); await f.submit(); assert.equal(f.calls.length, 0); assert.equal(f.fields.disabled, true); f.destroy();
    }
    f = fixture(); await f.selectProvider(); await f.submit(); assert.equal(f.calls.length, 0);
    await f.choose('101'); f.form.valid = false; await f.submit(); assert.equal(f.calls.length, 0); f.destroy();
    f = fixture({request: async () => ({ok: false})}); await f.selectProvider(); await f.choose('101'); await f.submit();
    assert.equal(f.rows.get('101').querySelector('[data-assignment-select]'), f.inputs.get('101'));
    assert.equal(f.count.textContent, '1 project selected'); assert.equal(f.notifications[0].tone, 'error');
    await f.submit(); assert.equal(f.calls.length, 2, 'Failed projects remain retryable'); f.destroy();
    let retrySucceeds = false;
    f = fixture({request: async payload => ({ok: payload.pid === 101 || retrySucceeds})});
    await f.selectProvider(); await f.choose('101'); await f.choose('102'); await f.submit();
    assert.equal(f.count.textContent, '1 project selected'); retrySucceeds = true; await f.submit();
    assert.deepEqual(f.calls.map(c => c.payload.pid), [101, 102, 102], 'Retry must exclude successful assignments');
    assert.equal(f.rows.get('102').querySelector('[data-assignment-select]'), undefined);
    assert.equal(f.count.textContent, '0 projects selected'); assert.equal(f.notifications.at(-1).tone, 'success'); f.destroy();
    f = fixture({size: 1}); await f.selectProvider(); await f.choose('101'); await f.submit();
    assert.equal(f.form.dataset.remainingProjects, '0'); assert.equal(f.button.disabled, true); f.destroy();
    console.log('Bulk assignment: paging/filter selection, partial errors/retry, checkmarks, busy guards, lifecycle cleanup and empty/invalid states passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
