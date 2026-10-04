/* Real workflow launcher asset with disposable dialog/project-picker doubles; no live state. */
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/provider-workflows.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor() { this.events = {}; this.attributes = {}; this.children = []; }
    addEventListener(key, handler) { this.events[key] = handler; }
    removeEventListener(key, handler) { if (this.events[key] === handler) delete this.events[key]; }
    getAttribute(key) { return this.attributes[key] ?? null; }
    setAttribute(key, value) { this.attributes[key] = value; this.observer?.callback(); }
    appendChild(node) {
        if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
        this.children.push(node); node.parent = this;
    }
    get firstElementChild() { return this.children[0]; }
}
function fixture({available = true, broken = false, response = {ok: true}, reject = false} = {}) {
    const ids = ['register', 'assign', 'transition', 'renewal', 'revocation'];
    const launchers = [], hosts = new Map(), forms = new Map(), bodies = new Map(), picks = new Map();
    const notifications = [], dialogs = [], requests = [], redirects = []; let writes = 0;
    const fields = {disabled: false}, inputs = {
        '#provider-name': {value: 'Test CA'}, '#provider-chain': {files: [{size: 1024, text: async () => 'PUBLIC CERT'}]},
        '#provider-source': {value: 'internal'}, '#provider-fallback': {checked: true},
    };
    ids.forEach(id => {
        const launcher = new Node(); launcher.dataset = {providerWorkflow: id}; launcher.textContent = 'Title: ' + id;
        launchers.push(launcher);
        const host = new Node(), body = new Node(), form = new Node(), select = new Node(), resetSelect = new Node();
        body.className = 'pdf-sealer-dialog-body'; host.appendChild(body);
        form.valid = true; form.reportValidity = () => form.valid;
        form.querySelector = selector => selector === 'fieldset' ? fields : inputs[selector];
        form.resets = 0; form.reset = () => { form.resets++; };
        select.initializations = 0; select.destroys = 0; select.changes = 0;
        resetSelect.dispatchEvent = () => { resetSelect.changed = true; };
        body.querySelector = () => form;
        body.querySelectorAll = selector => selector === '[data-workflow-project]' ? (id === 'register' ? [] : [select]) : [resetSelect];
        hosts.set(id, host); forms.set(id, form); bodies.set(id, body); picks.set(id, select);
    });
    const createDialog = options => {
        const handlers = {}, controls = Object.fromEntries(options.buttons.map(b => [typeof b === 'string' ? b : b.id, {}])), container = new Node();
        const ctx = {$dlg: {dialog: true}, on: (key, handler) => { handlers[key] = handler; },
            buttons: {disable: id => { controls[id].disabled = true; }, enable: id => { controls[id].disabled = false; },
                trigger: id => dialog.press(id)},
            setCloseButton: value => { ctx.closeButton = value; }};
        options.setup(ctx); container.appendChild(options.body());
        if (broken) throw Error('Dialog setup failed');
        let resolve; const promise = new Promise(r => { resolve = r; });
        const dialog = {options, ctx, controls, container, handlers,
            closed: false,
            close: (result = null) => { if (handlers['dialog:beforeClose']() === false) return false; dialog.closed = true; handlers['dialog:hidden'](); resolve(result); return true; },
            press: async id => { const result = await handlers['button:' + id](); if (result !== false && result !== undefined) dialog.close(result); return result; }};
        dialogs.push(dialog); handlers['dialog:shown'](); return promise;
    };
    const context = {document: {
        querySelectorAll: () => launchers,
        querySelector: selector => hosts.get(selector.match(/="([^"]+)"/)[1]),
    }, window: {PDFSealerNotify: (text, tone) => notifications.push({text, tone})},
        URL, location: {href: 'https://redcap.test/external_modules/?prefix=pdf_sealer&page=pki-admin', assign: url => redirects.push(url)},
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
    context.window.PDFSealerProviderWorkflows({tt: key => key, ajax: async (action, payload) => { writes++; requests.push({action, payload}); if (reject) throw Error('Request failed'); return await response; }});
    return {launchers, hosts, bodies, forms, picks, dialogs, notifications, inputs, fields, requests, redirects, get writes() { return writes; },
        open: async id => { const completion = launchers.find(l => l.dataset.providerWorkflow === id).events.click(); await tick(); return {completion}; }};
}
(async () => {
    let f = fixture();
    for (const id of ['register', 'assign', 'transition', 'renewal', 'revocation']) {
        const run = await f.open(id), dialog = f.dialogs.at(-1), form = f.forms.get(id), select = f.picks.get(id);
        assert.equal(dialog.options.title, 'Title: ' + id); assert.equal(dialog.options.draggable, true);
        const dismiss = id === 'register' ? 'cancel' : 'close';
        assert.deepEqual(Array.from(dialog.options.buttons, button => typeof button === 'string' ? button : button.id), id === 'register' ? ['cancel', 'register'] : ['close']);
        if (id === 'register') assert.equal(dialog.options.buttons[1].label, 'provider_register');
        assert.equal(dialog.options.closeButton, dismiss);
        assert.equal(dialog.container.firstElementChild, f.bodies.get(id)); assert.equal(f.hosts.get(id).children.length, 0);
        assert.ok(f.launchers.every(button => button.disabled));
        if (id !== 'register') assert.equal(select.config.dropdownParent, dialog.ctx.$dlg);
        form.setAttribute('aria-busy', 'true');
        assert.equal(dialog.controls[dismiss].disabled, true); assert.equal(dialog.ctx.closeButton, false);
        assert.equal(dialog.close(), false); assert.equal(form.resets, 0);
        form.setAttribute('aria-busy', 'false'); assert.equal(dialog.controls[dismiss].disabled, false);
        assert.equal(dialog.close(), true); await run.completion;
        assert.equal(f.hosts.get(id).firstElementChild, f.bodies.get(id));
        assert.equal(form.resets, 1); assert.equal(form.observer, undefined);
        assert.ok(f.launchers.every(button => !button.disabled));
        if (id !== 'register') { assert.equal(select.destroys, 1); assert.equal(select.changes, 1); }
    }
    const run = await f.open('assign'); f.dialogs.at(-1).close(); await run.completion;
    assert.equal(f.picks.get('assign').initializations, 2); assert.equal(f.picks.get('assign').destroys, 2);
    assert.equal(f.writes, 0, 'Opening, canceling and reopening must not mutate');
    // Registration validates before writing, closes only on success, and refreshes after cleanup.
    f = fixture();
    let opened = await f.open('register'), dialog = f.dialogs.at(-1), form = f.forms.get('register');
    form.valid = false; await dialog.press('register');
    assert.equal(f.writes, 0); assert.equal(dialog.closed, false);
    form.valid = true; f.inputs['#provider-chain'].files[0].size = 131073;
    await dialog.press('register'); assert.equal(f.writes, 0); assert.equal(dialog.closed, false);
    assert.equal(form.resets, 0); assert.equal(f.fields.disabled, false);
    f.inputs['#provider-chain'].files[0].size = 1024;
    await dialog.press('register'); await opened.completion;
    assert.equal(dialog.closed, true); assert.equal(form.resets, 1); assert.equal(form.events.submit, undefined);
    assert.equal(f.requests[0].action, 'register_ca_provider');
    assert.deepEqual(JSON.parse(JSON.stringify(f.requests[0].payload)), {name: 'Test CA', source: 'internal', fallback: true, pem: 'PUBLIC CERT'});
    const refreshed = new URL(f.redirects[0]);
    assert.equal(refreshed.searchParams.get('prefix'), 'pdf_sealer');
    assert.equal(refreshed.searchParams.get('provider_notice'), 'registered'); assert.equal(refreshed.hash, '#providers');
    for (const options of [{response: {ok: false, message: 'Safe validation error'}}, {reject: true}]) {
        f = fixture(options); opened = await f.open('register'); dialog = f.dialogs.at(-1); form = f.forms.get('register');
        await dialog.press('register');
        assert.equal(dialog.closed, false); assert.equal(form.resets, 0); assert.equal(f.redirects.length, 0);
        assert.equal(f.inputs['#provider-name'].value, 'Test CA'); assert.equal(f.fields.disabled, false);
        assert.equal(dialog.controls.cancel.disabled, false); assert.equal(dialog.controls.register.disabled, false);
        assert.equal(f.notifications.at(-1).tone, 'error');
        assert.equal(f.notifications.at(-1).text, options.reject ? 'provider_request_failed' : 'Safe validation error');
        dialog.close(); await opened.completion;
    }
    let finishRequest;
    f = fixture({response: new Promise(resolve => { finishRequest = resolve; })});
    opened = await f.open('register'); dialog = f.dialogs.at(-1); form = f.forms.get('register');
    form.events.submit({preventDefault() {}}); await tick();
    assert.equal(f.writes, 1); assert.equal(dialog.controls.register.disabled, true);
    assert.equal(dialog.controls.cancel.disabled, true); assert.equal(dialog.close(), false);
    await dialog.press('register'); assert.equal(f.writes, 1, 'A busy registration must not repeat');
    finishRequest({ok: true}); await opened.completion;
    assert.equal(dialog.closed, true); assert.equal(f.fields.disabled, false); assert.equal(form.events.submit, undefined);
    f = fixture({available: false}); await (await f.open('register')).completion;
    assert.equal(f.notifications[0].text, 'provider_dialog_unavailable'); assert.equal(f.dialogs.length, 0);
    f = fixture({broken: true}); await (await f.open('assign')).completion;
    assert.equal(f.notifications[0].text, 'provider_workflow_failed');
    assert.equal(f.hosts.get('assign').firstElementChild, f.bodies.get('assign'));
    assert.ok(f.launchers.every(button => !button.disabled));
    console.log('Workflow dialogs: launchers/pickers/cleanup and registration validation, footer action, failure recovery, busy guards and close/refresh passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
