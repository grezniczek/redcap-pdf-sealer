/* Exercise the actual project revocation asset with disposable CC/dialog doubles. */
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/project-revocation.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor() { this.events = {}; this.attributes = {}; }
    addEventListener(name, handler) { this.events[name] = handler; }
    setAttribute(name, value) { this.attributes[name] = value; }
}
function fixture({revoked = false, fail = false, deferred = null} = {}) {
    const calls = [], dialogs = [], notifications = [], nodes = {};
    ['pdf-sealer-revocation', 'revocation-review', 'revocation-choice', 'revocation-reason', 'revocation-confirm',
        'revocation-subject', 'revocation-fingerprint', 'revocation-thumbprint', 'revocation-already'].forEach(id => { nodes[id] = new Node(); });
    const form = nodes['pdf-sealer-revocation'], fields = new Node(); fields.disabled = false;
    form.querySelector = () => fields; form.reset = () => {};
    const projectNode = new Node(); projectNode.selectedOptions = [{textContent: '(524) Project <name>'}];
    const reason = nodes['revocation-reason']; reason.selectedOptions = [{textContent: 'Key compromise'}];
    const project = {0: projectNode, prop: (key, value) => { projectNode[key] = value; return project; },
        on: (name, handler) => { projectNode.events[name] = handler; }, val: () => '524',
        trigger: name => { projectNode.events[name]?.(); }};
    const module = {tt: (key, ...values) => key + (values.length ? ':' + values.join(',') : ''),
        ajax: async (action, payload) => {
            calls.push({action, payload});
            if (action === 'preview_project_revocation') return {ok: true, pid: 524, revoked, review_hash: 'fresh-review',
                certificate: {subject: '/O=Example/CN=Project', fingerprint: 'public-sha256', thumbprint: 'public-sha1'}};
            if (deferred) await deferred;
            return fail ? {ok: false} : {ok: true, crl_published: true, replacement: 'renewed'};
        }};
    const createDialog = options => {
        const handlers = {}, ctx = {on: (name, handler) => { handlers[name] = handler; }};
        options.setup(ctx); const body = options.body(ctx);
        let resolve; const promise = new Promise(r => {resolve = r;});
        dialogs.push({options, body, confirm: () => resolve(handlers['button:confirm']()), cancel: () => resolve(null)});
        return promise;
    };
    const context = {document: {getElementById: id => nodes[id], createElement: () => new Node()}, $: () => project,
        window: {rcDialog: createDialog, PDFSealerNotify: (text, tone) => notifications.push({text, tone})}};
    vm.runInNewContext(source, context); context.window.PDFSealerProjectRevocation(module);
    return {calls, dialogs, notifications, form, fields, nodes, reason,
        review: () => form.events.submit({preventDefault() {}}),
        revoke: async () => {const completion = nodes['revocation-confirm'].events.click(); await tick(); return {completion};}};
}
(async () => {
    let f = fixture(); await f.review(); assert.equal(f.calls.length, 1);
    let run = await f.revoke(); assert.equal(f.calls.length, 1, 'Confirmation opening must not revoke');
    assert.equal(f.fields.disabled, true); assert.equal(f.form.attributes['aria-busy'], 'true');
    assert.equal(f.dialogs[0].body.className, 'pdf-sealer-dialog-body');
    assert.ok(f.dialogs[0].body.textContent.includes('Project <name>'));
    assert.equal(f.dialogs[0].options.buttons[1].intent, 'danger');
    f.dialogs[0].cancel(); await run.completion;
    assert.equal(f.calls.length, 1); assert.equal(f.fields.disabled, false);
    run = await f.revoke(); f.dialogs[1].confirm(); await run.completion;
    assert.equal(f.calls[1].action, 'revoke_project_certificate'); assert.equal(f.calls[1].payload.review_hash, 'fresh-review');
    assert.equal(f.notifications[0].tone, 'success'); assert.equal(f.nodes['revocation-review'].hidden, true);

    let release; const deferred = new Promise(r => {release = r;});
    f = fixture({deferred}); await f.review(); f.reason.value = 'compromise';
    run = await f.revoke(); f.dialogs[0].confirm(); await tick();
    assert.equal(f.calls[1].payload.reason, 'compromise'); assert.equal(f.form.attributes['aria-busy'], 'true');
    await (await f.revoke()).completion; assert.equal(f.calls.length, 2, 'In-flight revocation must not repeat');
    release(); await run.completion; assert.equal(f.form.attributes['aria-busy'], 'false');

    f = fixture({fail: true}); await f.review(); run = await f.revoke(); f.dialogs[0].confirm(); await run.completion;
    assert.equal(f.notifications[0].tone, 'error'); await (await f.revoke()).completion;
    assert.equal(f.calls.length, 2, 'Failed revocation requires another review');
    f = fixture({revoked: true}); await f.review(); await (await f.revoke()).completion;
    assert.equal(f.dialogs.length, 0); assert.equal(f.calls.length, 1);
    console.log('Project revocation rcDialog: cancel/confirm, captured reason/hash, frozen request and failed/revoked guards passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
