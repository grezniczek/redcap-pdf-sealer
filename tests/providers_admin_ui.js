/* Real asset with disposable DOM, DataTables and rcDialog doubles; no live configuration. */
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../assets/providers-admin.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor(tag, attributes = {}) { this.tag = tag; this.children = []; this.events = {}; this.dataset = {}; Object.assign(this, attributes); }
    append(...nodes) { nodes.forEach(n => { n.parent = this; this.children.push(n); }); }
    addEventListener(name, handler) { this.events[name] = handler; }
    dispatchEvent(event) { this.events[event.type]?.(event); }
    setAttribute(name, value) { if (name.startsWith('data-')) this.dataset[name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = value; }
    matches(selector) { return selector.startsWith('[data-') ? selector.slice(6, -1).replace(/-([a-z])/g, (_, c) => c.toUpperCase()) in this.dataset : this.tag === selector; }
    closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector); }
    querySelectorAll(selector) { return this.children.flatMap(n => [...(n.matches(selector) ? [n] : []), ...n.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0]; }
    replaceWith(node) { const i = this.parent.children.indexOf(this); this.parent.children[i] = node; node.parent = this.parent; }
    remove() { this.parent.options.splice(this.parent.options.indexOf(this), 1); }
    add(option) { option.parent = this; this.options.push(option); }
}
const all = node => [node, ...node.children.flatMap(all)];
function fixture({retired = false, required = false, fail = false, previewFail = false, deferred = null} = {}) {
    const ids = {}, calls = [], dialogs = [], tables = [], notifications = [], projectUpdates = [];
    const node = (id, tag, data = {}) => ids[id] = new Node(tag, {dataset: data});
    const catalog = node('pdf-sealer-providers', 'table');
    const row = new Node('tr', {dataset: {providerId: 'builtin-ca', retired: retired ? '1' : '0'}});
    const statusCell = new Node('td'), status = new Node('span', {dataset: {providerStatus: ''}}); statusCell.append(status);
    const name = new Node('td', {dataset: {providerName: ''}, textContent: 'Built-in <CA>'});
    const actions = new Node('td'), launcher = new Node('button', {dataset: {providerManage: ''}}); actions.append(launcher);
    row.append(statusCell, name, actions); catalog.append(row);
    const policy = node('pdf-sealer-assignment-policy', 'div', {required: required ? '1' : '0', projectsUnavailable: '0'});
    const summary = new Node('p', {dataset: {policySummary: ''}}); policy.append(summary);
    const policyButton = node('pdf-sealer-policy-change', 'button');
    node('pki-panel-providers', 'section', {}); const tab = new Node('button');
    ids['pdf-sealer-provider-details-builtin-ca'] = {content: {cloneNode() {
        const body = new Node('div', {className: 'pdf-sealer-dialog-body'});
        body.append(new Node('span', {dataset: {detailsStatus: ''}})); return body;
    }}};
    let currentRetired = retired, currentRequired = required;
    const module = {tt: (key, ...values) => key + (values.length ? ':' + values.join(',') : ''),
        ajax: async (action, payload) => {
            calls.push({action, payload});
            if (action === 'preview_ca_retirement') return {ok: !previewFail, retired: currentRetired,
                is_default: true, assignment_required: currentRequired, review_hash: 'review-' + calls.length,
                projects: [{pid: 524, identity_id: 'signer', enrollment_id: null}]};
            if (deferred) await deferred;
            if (fail) return {ok: false};
            if (action === 'save_assignment_policy') { currentRequired = payload.required; return {ok: true, required: payload.required}; }
            currentRetired = payload.retired; if (payload.enable_assignment_gate) currentRequired = true;
            return {ok: true};
        }};
    const makeDialog = options => {
        let resolve; const promise = new Promise(r => { resolve = r; });
        const handlers = {}, controls = Object.fromEntries(options.buttons.map(b => [typeof b === 'string' ? b : b.id, {}]));
        const ctx = {on: (name, handler) => { handlers[name] = handler; },
            buttons: {disable: id => { controls[id].disabled = true; }, enable: id => { controls[id].disabled = false; },
                setLoading: (id, value) => { controls[id].loading = value; }},
            setCloseButton: value => { ctx.closeButton = value; },
            setFooterStatus: value => { ctx.footer = value; }, clearFooterStatus: () => { ctx.footer = null; },
            close: async value => { if (handlers['dialog:beforeClose']?.() === false) return false; resolve(value); return true; },
        };
        options.setup(ctx);
        const bodies = options.tabs ? options.tabs.map(t => t.body(ctx)) : [options.body(ctx)];
        const dialog = {options, ctx, controls, bodies, handlers,
            press: async id => { const value = await handlers['button:' + id]?.(); if (value !== false) await ctx.close(value); return value; }};
        dialogs.push(dialog); return promise;
    };
    makeDialog.tabbed = makeDialog;
    const jquery = n => ({prop: (key, value) => { n[key] = value; }, DataTable: options => {
        const state = {options, adjustments: 0, invalidations: 0, draws: [], destroyed: false,
            columns: {adjust: () => { state.adjustments++; }},
            row: selected => { assert.equal(selected, row); return {invalidate: kind => {
                assert.equal(kind, 'dom'); state.invalidations++; return {draw: reset => state.draws.push(reset)};
            }}; }, destroy: () => { state.destroyed = true; }};
        tables.push(state); return state;
    }});
    const context = {document: {getElementById: id => ids[id], createElement: tag => new Node(tag),
        createTextNode: text => new Node('#text', {textContent: text}), querySelector: () => tab},
        window: {rcDialog: makeDialog, addEventListener() {}, PDFSealerProjectsAdmin: {providerChanged: (id, retired) => projectUpdates.push({id, retired})}, PDFSealerNotify: (text, tone) => notifications.push({text, tone})}, $: jquery,
        Event: class {constructor(type) {this.type = type;}}, Option: function(text, value) { return option(text, value); }};
    vm.runInNewContext(source, context); context.window.PDFSealerProvidersAdmin(module);
    return {calls, dialogs, tables, notifications, projectUpdates, policy, row, statusCell, ids, context,
        open: async () => { const completion = catalog.events.click({target: launcher}); await tick(); return {completion}; },
        policyOpen: async () => { const completion = policyButton.events.click(); await tick(); return {completion}; }};
}
(async () => {
    let f = fixture(), run = await f.open(), main = f.dialogs[0];
    assert.equal(f.calls.length, 1, 'Manage opening must only read');
    assert.deepEqual(Array.from(main.options.buttons), ['close']);
    assert.deepEqual(Array.from(main.options.tabs, t => t.id), ['details', 'usage']);
    assert.ok(main.bodies.every(body => body.className === 'pdf-sealer-dialog-body'));
    main.handlers['tab:changed']({current: {id: 'usage'}});
    main.handlers['tab:changed']({current: {id: 'usage'}});
    assert.equal(f.tables.length, 2); assert.equal(f.tables[1].options.pageLength, 10);
    await main.ctx.close(null); await run.completion;
    assert.equal(f.calls.length, 1); assert.equal(f.tables[1].destroyed, true);

    f = fixture(); run = await f.open(); main = f.dialogs[0];
    const cancelRun = main.options.footerStatus.events.click(); await tick();
    await f.dialogs[1].ctx.close(null); await cancelRun;
    assert.equal(f.calls.length, 2, 'Canceling confirmation must only read');
    assert.equal(main.controls.close.disabled, false);
    await main.ctx.close(null); await run.completion;

    f = fixture(); run = await f.open(); main = f.dialogs[0];
    const action = main.options.footerStatus;
    const change = action.events.click(); await tick();
    const confirm = f.dialogs[1], gate = all(confirm.bodies[0]).find(n => n.type === 'checkbox');
    assert.equal(f.calls.length, 2, 'Confirmation must get a fresh review');
    assert.equal(confirm.controls.confirm.disabled, true);
    await confirm.press('confirm'); assert.equal(f.calls.length, 2, 'Default retirement requires explicit acknowledgment');
    gate.checked = true; gate.events.change(); assert.equal(confirm.controls.confirm.disabled, false);
    await confirm.press('confirm'); await change; await run.completion;
    assert.equal(f.calls[2].payload.review_hash, 'review-2'); assert.equal(f.calls[2].payload.enable_assignment_gate, true);
    assert.equal(f.row.dataset.retired, '1'); assert.equal(f.policy.dataset.required, '1');
    assert.equal(f.statusCell.children[0].textContent, 'provider_retired');
    assert.deepEqual(f.projectUpdates.at(-1), {id: 'builtin-ca', retired: true});
    assert.ok(f.tables[0].draws.every(reset => reset === false), 'Updates must preserve table page');
    assert.ok(f.notifications.length > 0);

    f = fixture({retired: true, required: true}); run = await f.open(); main = f.dialogs[0];
    let changeRun = main.options.footerStatus.events.click(); await tick();
    await f.dialogs[1].press('confirm'); await changeRun; await run.completion;
    assert.equal(f.row.dataset.retired, '0'); assert.equal(f.policy.dataset.required, '1');
    assert.deepEqual(f.projectUpdates.at(-1), {id: 'builtin-ca', retired: false});
    assert.equal(f.calls[2].payload.enable_assignment_gate, false);

    f = fixture({required: true, fail: true}); run = await f.open(); main = f.dialogs[0];
    changeRun = main.options.footerStatus.events.click(); await tick();
    await f.dialogs[1].press('confirm'); await f.dialogs[1].press('confirm');
    assert.equal(f.calls.length, 3, 'Failed/stale retirement must not replay');
    assert.equal(f.notifications[0].text, 'provider_lifecycle_failed');
    await f.dialogs[1].ctx.close(null); await changeRun; assert.equal(main.controls.close.disabled, false);
    await main.ctx.close(null); await run.completion; assert.equal(f.row.dataset.retired, '0');

    let release; const deferred = new Promise(r => {release = r;});
    f = fixture({required: true, deferred}); run = await f.open(); main = f.dialogs[0];
    changeRun = main.options.footerStatus.events.click(); await tick();
    const child = f.dialogs[1], pending = child.press('confirm'); await tick();
    await child.press('confirm'); assert.equal(f.calls.length, 3);
    assert.equal(await child.ctx.close(null), false); assert.equal(await main.ctx.close(null), false);
    release(); await pending; await changeRun; await run.completion;

    f = fixture(); run = await f.policyOpen(); const policyDialog = f.dialogs[0];
    all(policyDialog.bodies[0]).find(n => n.type === 'checkbox').checked = true;
    await policyDialog.press('save'); await run.completion;
    assert.equal(f.policy.dataset.required, '1'); assert.equal(f.notifications[0].text, 'assignment_policy_saved');
    assert.equal(f.calls[0].action, 'save_assignment_policy');
    f = fixture(); run = await f.policyOpen(); await f.dialogs[0].ctx.close(null); await run.completion;
    assert.equal(f.calls.length, 0, 'Canceling policy editing must not write');
    f = fixture({fail: true}); run = await f.policyOpen(); await f.dialogs[0].press('save'); await f.dialogs[0].press('save');
    assert.equal(f.calls.length, 1); assert.equal(f.notifications[0].text, 'assignment_policy_failed');
    await f.dialogs[0].ctx.close(null); await run.completion;
    f = fixture({previewFail: true}); run = await f.open(); await run.completion;
    assert.equal(f.dialogs.length, 0); assert.equal(f.notifications[0].text, 'provider_review_failed');
    console.log('Provider administration UI checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
