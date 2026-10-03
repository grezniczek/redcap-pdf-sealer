/* Disposable DOM/dialog doubles: mutation gates and reload outcomes, not visual rendering. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/root-lifecycle.js'), 'utf8');
class Node {
    constructor(tag) { this.tag = tag; this.style = {}; this.children = []; this.events = {}; this.attributes = {}; }
    append(...nodes) { this.children.push(...nodes); }
    addEventListener(name, handler) { this.events[name] = handler; }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { delete this.attributes[name]; }
}
function fixture({revoked = false, fail = false, pending = false, deferred = null, dialogAvailable = true} = {}) {
    const launcher = new Node('button'), message = new Node('div');
    const calls = [], storage = new Map();
    let dialog, reloads = 0, resolveDialog;
    const preview = {ok: true, revoked, review_hash: 'current-review', known_dependent_certificates: 3,
        certificate: {subject: '/O=Example/CN=Root', fingerprint: 'root-sha256', thumbprint: 'root-sha1'}};
    const module = {
        tt: (key, ...values) => key + (values.length ? ':' + values.join(',') : ''),
        ajax: async (action, payload) => {
            calls.push({action, payload});
            if (action === 'preview_root_lifecycle') return preview;
            if (deferred) await deferred;
            return fail ? {ok: false} : {ok: true, replacement: pending ? 'pending' : 'renewed',
                crl_published: !pending, maintenance_status: pending ? 'pending' : 'ok'};
        },
    };
    const wizard = options => {
        const handlers = {}, controls = Object.fromEntries(options.buttons.map(button =>
            typeof button === 'string' ? [button, {}] : [button.id, {...button}]));
        const ctx = {state: options.state, body: null, footer: null,
            buttons: {
                disable: id => { controls[id].disabled = true; },
                enable: id => { controls[id].disabled = false; },
                update: (id, patch) => Object.assign(controls[id], patch),
                setLoading: (id, loading) => { controls[id].loading = loading; },
            },
            on: (name, handler) => { handlers[name] = handler; },
            setCloseButton: id => { ctx.closeButton = id; },
            clearFooterStatus: () => { ctx.footer = null; },
            setFooterStatus: value => { ctx.footer = value; },
        };
        ctx.wizard = {currentPage: options.pages[0], next: async () => {
            if (handlers['wizard:beforePageChange']() === false) return false;
            ctx.wizard.currentPage = options.pages[1];
            ctx.body = options.pages[1].body(ctx); return true;
        }};
        options.setup(ctx); ctx.body = options.pages[0].body(ctx);
        const close = value => { if (handlers['dialog:beforeClose']() !== false) resolveDialog(value); };
        dialog = {options, ctx, controls,
            confirm: async () => { const value = await handlers['button:confirm'](); if (value !== false) close(value); return value; },
            cancel: () => close(null),
        };
        return new Promise(resolve => { resolveDialog = resolve; });
    };
    const context = {document: {getElementById: id => id === 'pdf-sealer-root-lifecycle' ? launcher : message,
        createElement: tag => new Node(tag)},
        location: {pathname: '/module', search: '?prefix=pdf_sealer', reload: () => { ++reloads; }},
        sessionStorage: {getItem: key => storage.get(key) ?? null, removeItem: key => storage.delete(key),
            setItem: (key, value) => storage.set(key, value)}, window: {},
    };
    if (dialogAvailable) context.window.rcDialog = {wizard};
    vm.runInNewContext(source, context);
    context.window.PDFSealerRootLifecycle(module);
    return {launcher, message, calls, storage, context, module,
        get dialog() { return dialog; }, get reloads() { return reloads; },
        launch: async () => { const completion = launcher.events.click(); await new Promise(resolve => setImmediate(resolve)); return {completion}; },
    };
}
const nodes = root => [root, ...root.children.flatMap(nodes)];
const choose = (fixture, reason) => {
    const radio = nodes(fixture.dialog.ctx.body).find(node => node.tag === 'input' && node.value === reason);
    assert.ok(radio); radio.events.change();
};
(async () => {
    let f = fixture(); let run = await f.launch();
    assert.equal(f.dialog.options.draggable, true);
    assert.deepEqual(Array.from(f.dialog.options.buttons, b => typeof b === 'string' ? b : b.id), ['cancel', 'confirm']);
    await f.dialog.confirm();
    assert.equal(f.calls.length, 1, 'Review-to-confirm must not mutate');
    await f.dialog.confirm(); await run.completion;
    assert.equal(f.calls[1].action, 'renew_root_certificate');
    assert.equal(f.calls[1].payload.reason, 'renew');
    assert.equal(f.calls[1].payload.review_hash, 'current-review');
    assert.equal(f.reloads, 1);
    assert.ok([...f.storage.values()][0].includes('root_lifecycle_renewed'));
    f.context.window.PDFSealerRootLifecycle(f.module); // Next page load consumes a text-only outcome.
    assert.ok(f.message.textContent.includes('root_lifecycle_renewed')); assert.equal(f.storage.size, 0);

    f = fixture({pending: true}); run = await f.launch(); choose(f, 'compromise');
    await f.dialog.confirm();
    const gate = nodes(f.dialog.ctx.body).find(node => node.type === 'checkbox');
    assert.ok(gate.required); assert.equal(f.dialog.controls.confirm.disabled, true);
    await f.dialog.confirm(); assert.equal(f.calls.length, 1, 'Unchecked compromise must not mutate');
    gate.checked = true; gate.events.change(); assert.equal(f.dialog.controls.confirm.disabled, false);
    await f.dialog.confirm(); await run.completion;
    assert.equal(f.calls[1].action, 'revoke_root_certificate'); assert.equal(f.calls[1].payload.reason, 'compromise');
    assert.equal(f.reloads, 1);
    const receipt = JSON.parse([...f.storage.values()][0]);
    assert.equal(receipt.tone, 'warning');
    for (const key of ['root_lifecycle_saved', 'revocation_crl_pending', 'root_lifecycle_pending', 'root_lifecycle_projects_pending']) {
        assert.ok(receipt.text.includes(key), 'Recovery/CRL outcomes must survive reload');
    }

    f = fixture(); run = await f.launch(); choose(f, 'superseded'); await f.dialog.confirm();
    assert.equal(nodes(f.dialog.ctx.body).some(node => node.type === 'checkbox'), false);
    await f.dialog.confirm(); await run.completion;
    assert.equal(f.calls[1].payload.reason, 'superseded');

    f = fixture(); run = await f.launch(); await f.dialog.confirm(); f.dialog.cancel(); await run.completion;
    assert.equal(f.calls.length, 1); assert.equal(f.reloads, 0);
    f = fixture({revoked: true}); run = await f.launch(); await f.dialog.confirm();
    assert.equal(f.dialog.ctx.wizard.currentPage.id, 'review'); assert.equal(f.calls.length, 1);
    f.dialog.cancel(); await run.completion;

    f = fixture({fail: true}); run = await f.launch(); await f.dialog.confirm(); await f.dialog.confirm();
    assert.equal(f.reloads, 0); assert.equal(f.dialog.ctx.footer, 'root_lifecycle_failed');
    await f.dialog.confirm(); assert.equal(f.calls.length, 2, 'Failed/ambiguous request must not retry with the same review');
    f.dialog.cancel(); await run.completion;

    let release; const deferred = new Promise(resolve => { release = resolve; });
    f = fixture({deferred}); run = await f.launch(); await f.dialog.confirm();
    const applying = f.dialog.confirm(); f.dialog.cancel(); await f.dialog.confirm();
    assert.equal(f.dialog.controls.cancel.disabled, true); assert.equal(f.dialog.ctx.closeButton, false);
    assert.equal(f.calls.length, 2, 'Repeated confirm must not send another mutation');
    release(); await applying; await run.completion; assert.equal(f.reloads, 1);

    f = fixture({dialogAvailable: false}); run = await f.launch(); await run.completion;
    assert.equal(f.calls.length, 0); assert.equal(f.message.textContent, 'root_lifecycle_dialog_unavailable');
    console.log('Root CA UI: two-step confirmation, compromise gate, cancellation, revoked/stale state, busy guard and reload receipts passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
