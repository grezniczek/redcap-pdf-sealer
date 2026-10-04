/* Real workflow launcher asset with disposable dialog/project-picker doubles; no live state. */
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/provider-workflows.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor() { this.events = {}; this.attributes = {}; this.children = []; }
    addEventListener(key, handler) { this.events[key] = handler; }
    getAttribute(key) { return this.attributes[key] ?? null; }
    setAttribute(key, value) { this.attributes[key] = value; this.observer?.callback(); }
    appendChild(node) {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        this.children.push(node); node.parent = this;
    }
    get firstElementChild() { return this.children[0]; }
}
function fixture({available = true, broken = false} = {}) {
    const ids = ['register', 'assign', 'transition', 'renewal', 'revocation'];
    const launchers = [], hosts = new Map(), forms = new Map(), bodies = new Map(), picks = new Map();
    const notifications = [], dialogs = []; let writes = 0;
    ids.forEach(id => {
        const launcher = new Node(); launcher.dataset = {providerWorkflow: id}; launcher.textContent = 'Title: ' + id;
        launchers.push(launcher);
        const host = new Node(), body = new Node(), form = new Node(), select = new Node(), resetSelect = new Node();
        body.className = 'pdf-sealer-dialog-body'; host.appendChild(body);
        form.resets = 0; form.reset = () => { form.resets++; };
        select.initializations = 0; select.destroys = 0; select.changes = 0;
        resetSelect.dispatchEvent = () => { resetSelect.changed = true; };
        body.querySelector = () => form;
        body.querySelectorAll = selector => selector === '[data-workflow-project]' ? (id === 'register' ? [] : [select]) : [resetSelect];
        hosts.set(id, host); forms.set(id, form); bodies.set(id, body); picks.set(id, select);
    });
    const createDialog = options => {
        const handlers = {}, controls = {close: {}}, container = new Node();
        const ctx = {$dlg: {dialog: true}, on: (key, handler) => { handlers[key] = handler; },
            buttons: {disable: id => { controls[id].disabled = true; }, enable: id => { controls[id].disabled = false; }},
            setCloseButton: value => { ctx.closeButton = value; }};
        options.setup(ctx); container.appendChild(options.body());
        if (broken) throw Error('Dialog setup failed');
        let resolve; const promise = new Promise(r => { resolve = r; });
        const dialog = {options, ctx, controls, container, handlers,
            close: () => { if (handlers['dialog:beforeClose']() === false) return false; handlers['dialog:hidden'](); resolve(null); return true; }};
        dialogs.push(dialog); handlers['dialog:shown'](); return promise;
    };
    const context = {document: {
        querySelectorAll: () => launchers,
        querySelector: selector => hosts.get(selector.match(/="([^"]+)"/)[1]),
    }, window: {PDFSealerNotify: (text, tone) => notifications.push({text, tone})},
        MutationObserver: class {
            constructor(callback) { this.callback = callback; }
            observe(form) { this.form = form; form.observer = this; }
            disconnect() { delete this.form.observer; }
        }, Event: class {constructor(type) {this.type = type;}},
        $: select => ({select2: config => {
            if (config === 'destroy') select.destroys++; else { select.initializations++; select.config = config; }
        }, trigger: () => { select.changes++; }}),
    };
    if (available) context.window.rcDialog = createDialog;
    vm.runInNewContext(source, context);
    context.window.PDFSealerProviderWorkflows({tt: key => key, ajax: () => { writes++; throw Error('Opening must not write'); }});
    return {launchers, hosts, bodies, forms, picks, dialogs, notifications, get writes() { return writes; },
        open: async id => { const completion = launchers.find(l => l.dataset.providerWorkflow === id).events.click(); await tick(); return {completion}; }};
}
(async () => {
    let f = fixture();
    for (const id of ['register', 'assign', 'transition', 'renewal', 'revocation']) {
        const run = await f.open(id), dialog = f.dialogs.at(-1), form = f.forms.get(id), select = f.picks.get(id);
        assert.equal(dialog.options.title, 'Title: ' + id); assert.equal(dialog.options.draggable, true);
        assert.deepEqual(Array.from(dialog.options.buttons), ['close']);
        assert.equal(dialog.container.firstElementChild, f.bodies.get(id)); assert.equal(f.hosts.get(id).children.length, 0);
        assert.ok(f.launchers.every(button => button.disabled));
        if (id !== 'register') assert.equal(select.config.dropdownParent, dialog.ctx.$dlg);
        form.setAttribute('aria-busy', 'true');
        assert.equal(dialog.controls.close.disabled, true); assert.equal(dialog.ctx.closeButton, false);
        assert.equal(dialog.close(), false); assert.equal(form.resets, 0);
        form.setAttribute('aria-busy', 'false'); assert.equal(dialog.controls.close.disabled, false);
        assert.equal(dialog.close(), true); await run.completion;
        assert.equal(f.hosts.get(id).firstElementChild, f.bodies.get(id));
        assert.equal(form.resets, 1); assert.equal(form.observer, undefined);
        assert.ok(f.launchers.every(button => !button.disabled));
        if (id !== 'register') { assert.equal(select.destroys, 1); assert.equal(select.changes, 1); }
    }
    const run = await f.open('assign'); f.dialogs.at(-1).close(); await run.completion;
    assert.equal(f.picks.get('assign').initializations, 2); assert.equal(f.picks.get('assign').destroys, 2);
    assert.equal(f.writes, 0, 'Opening, canceling and reopening must not mutate');
    f = fixture({available: false}); await (await f.open('register')).completion;
    assert.equal(f.notifications[0].text, 'provider_dialog_unavailable'); assert.equal(f.dialogs.length, 0);
    f = fixture({broken: true}); await (await f.open('assign')).completion;
    assert.equal(f.notifications[0].text, 'provider_workflow_failed');
    assert.equal(f.hosts.get('assign').firstElementChild, f.bodies.get('assign'));
    assert.ok(f.launchers.every(button => !button.disabled));
    console.log('Workflow dialogs: five launchers, moved/reused bodies, scoped pickers, busy dismissal and cleanup passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
