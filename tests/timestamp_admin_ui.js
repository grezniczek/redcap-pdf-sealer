/* Real TSA assets with disposable DOM/dialog/AJAX doubles; no live keys or network. */
const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const tick = () => new Promise(resolve => setImmediate(resolve));
class Node {
    constructor(tag, data = {}) { this.tag = tag; this.dataset = data; this.children = []; this.events = {}; this.style = {}; this.disabled = false; }
    append(...nodes) { nodes.forEach(node => this.appendChild(node)); }
    appendChild(node) { if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1); this.children.push(node); node.parent = this; }
    get firstElementChild() { return this.children[0]; }
    setAttribute(key, value) { this[key] = value; }
    addEventListener(key, handler) { this.events[key] = handler; }
    removeEventListener(key) { delete this.events[key]; }
    matches(selector) { return selector.startsWith('[data-') ? selector.slice(6, -1).replace(/-([a-z])/g, (_, c) => c.toUpperCase()) in this.dataset : this.tag === selector; }
    closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector); }
    querySelectorAll(selector) { return this.children.flatMap(n => [...(n.matches(selector) ? [n] : []), ...n.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0]; }
    reportValidity() { return this.valid !== false; }
    get selectedOptions() { return this.options.filter(option => option.value === this.value); }
}
const all = node => [node, ...node.children.flatMap(all)];
function fixture({reply, snapshot = null, revoked = false, sourceCount = 1, formatTime = date => 'local:' + date.toISOString()} = {}) {
    const ids = {}, calls = [], dialogs = [], notifications = [], redirects = [], busyValues = [], saved = [], tables = [];
    const node = (id, tag, data = {}) => ids[id] = new Node(tag, data);
    const catalog = node('pdf-sealer-tsa-sources', 'table');
    const sourceIds = Array.from({length: sourceCount}, (_, i) => i === 0 ? 'remote-tsa-test' : 'remote-tsa-test-' + i);
    for (const id of ['builtin-tsa', ...sourceIds]) {
        const row = new Node('tr', {tsaId: id}), name = new Node('td', {tsaName: ''}); name.textContent = id;
        row.append(name, new Node('td', {tsaExpiry: ''}), new Node('td', {tsaLastTest: ''}), new Node('button', {tsaManage: ''})); catalog.append(row);
    }
    node('pki-panel-tsa', 'section'); node('pdf-sealer-mode-summary', 'strong'); node('pdf-sealer-tsa-test-all', 'button');
    const registration = node('pdf-sealer-tsa-register', 'button'), host = node('pdf-sealer-tsa-register-host', 'div');
    const registerBody = new Node('div'), form = new Node('form'), fields = new Node('fieldset'); registerBody.append(form); form.append(fields); host.append(registerBody);
    form.payload = {name: 'Service <name>', endpoint: 'https://tsa.test', policy: '', username: '', password: 'secret', pem: 'PUBLIC CHAIN'};
    form.resets = 0; form.reset = () => { form.resets++; };
    let policyControls;
    ids['pdf-sealer-timestamp-policy'] = {content: {cloneNode() {
        const fragment = new Node('fragment'), body = new Node('div'), policyFields = new Node('fieldset'); body.className = 'pdf-sealer-dialog-body';
        const select = data => {
            const n = new Node('select', data); n.options = ['', 'none', 'builtin-tsa', 'remote-tsa-test', 'remote-tsa-other'].map(value => ({value, textContent: value})); return n;
        };
        const source = select({timestampSource: ''}), alternatives = [select({timestampAlternative: ''}), select({timestampAlternative: ''})];
        const fallback = new Node('input', {timestampFallback: ''}), save = new Node('button', {timestampSave: ''});
        policyFields.append(source, ...alternatives, fallback, save); body.append(policyFields); fragment.append(body);
        policyControls = {source, alternatives, fallback, save, fields: policyFields}; return fragment;
    }}};
    ids['pdf-sealer-builtin-tsa-details'] = {content: {cloneNode() {
        const fragment = new Node('fragment'), body = new Node('div'); body.className = 'pdf-sealer-dialog-body'; body.append(new Node('button', {tsaLifecycle: ''})); fragment.append(body); return fragment;
    }}};
    const makeDialog = options => {
        let resolve; const promise = new Promise(r => { resolve = r; });
        const handlers = {}, controls = Object.fromEntries(options.buttons.map(b => [typeof b === 'string' ? b : b.id, {}]));
        const ctx = {on: (key, handler) => { handlers[key] = handler; },
            buttons: {disable: id => { controls[id].disabled = true; }, enable: id => { controls[id].disabled = false; },
                update: (id, value) => Object.assign(controls[id], value), setLoading: (id, value) => { controls[id].loading = value; }, trigger: id => dialog.press(id)},
            setCloseButton: value => { ctx.closeButton = value; },
            close: async value => { if (handlers['dialog:beforeClose']?.() === false) return false; dialog.closed = true; resolve(value); return true; },
        };
        options.setup(ctx); const container = new Node('dialog'); container.append(options.body(ctx));
        const dialog = {options, ctx, controls, handlers, body: container.firstElementChild, closed: false,
            press: async id => { const result = await handlers['button:' + id]?.(); if (result !== false) await ctx.close(result); return result; }};
        dialogs.push(dialog); return promise;
    };
    const policies = [{id: 'builtin-ca', timestamp_source: 'builtin-tsa', timestamp_alternatives: ['remote-tsa-test'], bb_fallback: true}];
    const sources = sourceIds.map(id => ({id, name: 'External <TSA>', policy_oid: '', authenticated: true, diagnostic: snapshot}));
    const module = {tt: (key, ...values) => key + (values.length ? ':' + values.join(',') : ''), ajax: async (action, payload) => {
        calls.push({action, payload});
        if (action === 'preview_tsa_lifecycle') return {ok: true, revoked, review_hash: 'locked-review', certificate: {subject: '/O=Test/OU=Unit/CN=TSA', fingerprint: 'sha256', thumbprint: 'sha1'}};
        return reply ? await reply(action, payload) : {ok: true, diagnostic: {ok: true, checked_at: 1900000000, valid_until: 2000000000, signer_sha256: 'fingerprint', signer_sha1: 'thumbprint'}, replacement: 'renewed', crl_published: true};
    }};
    const context = {document: {getElementById: id => ids[id], createElement: tag => new Node(tag), querySelector: () => new Node('button')},
        window: {rcDialog: makeDialog, addEventListener() {}, PDFSealerNotify: (text, tone) => notifications.push({text, tone})},
        URL, location: {href: 'https://redcap.test/external_modules/?prefix=pdf_sealer&page=pki-admin', assign: value => redirects.push(value)},
        sessionStorage: {getItem() {return null;}, removeItem() {}, setItem() {}}, FormData: class {constructor(form) {this.form = form;} [Symbol.iterator]() {return Object.entries(this.form.payload)[Symbol.iterator]();}},
        $: n => ({DataTable: options => {
            const state = {options, draws: 0, columns: {adjust() {}}, row: row => ({invalidate: kind => { assert.equal(kind, 'dom'); assert.ok(catalog.children.includes(row)); return {draw: () => {state.draws++;}}; }})}; tables.push(state); return state;
        }}),
    };
    for (const asset of ['timestamp-admin.js', 'tsa-lifecycle.js']) vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets', asset), 'utf8'), context);
    const admin = context.window.PDFSealerTimestampAdmin(module, policies, sources, formatTime, context.location.href);
    return {calls, dialogs, notifications, redirects, tables, policies, sources, form, fields, host, registerBody, registration, ids, context, module, busyValues, saved,
        get policyControls() {return policyControls;},
        policy: () => admin.policy('builtin-ca', value => busyValues.push(value), value => saved.push(value)),
        open: async (id = 'remote-tsa-test') => { const row = catalog.children.find(n => n.dataset.tsaId === id); const completion = catalog.events.click({target: row.querySelector('[data-tsa-manage]')}); await tick(); return {completion, row}; },
        register: async () => { const completion = registration.events.click(); await tick(); return {completion}; },
    };
}
(async () => {
    // Exercise the page's actual profile formatter in a client zone that differs from UTC.
    const page = fs.readFileSync(path.join(__dirname, '../pki-admin.php'), 'utf8');
    assert.match(page, /DateTimeRC::get_user_format_full\(\)/);
    const expression = page.match(/const formatDiagnosticTime = (date => \{[\s\S]*?\n    \});/)[1];
    const previousZone = process.env.TZ; process.env.TZ = 'Europe/Berlin';
    for (const [profile, expected] of [['D/M/Y_12', '04/10/2026 3:16:17pm '], ['Y-M-D_24', '2026-10-04 15:16:17 ']]) {
        const formatter = vm.runInNewContext(expression, {diagnosticDateTimeFormat: profile, Intl});
        const date = new Date('2026-10-04T13:16:17Z'); assert.ok(formatter(date).startsWith(expected));
        const local = fixture({formatTime: formatter, reply: () => ({ok: true, diagnostic: {ok: true, checked_at: date.getTime() / 1000, valid_until: 2000000000}})});
        await local.ids['pdf-sealer-tsa-test-all'].events.click();
        const row = local.ids['pdf-sealer-tsa-sources'].children[1];
        assert.ok(row.querySelector('[data-tsa-last-test]').textContent.includes(expected));
        const opened = await local.open(); const dialog = local.dialogs[0];
        assert.ok(all(dialog.body).find(n => n['role'] === 'status').textContent.includes(expected));
        await dialog.ctx.close(); await opened.completion;
    }
    if (previousZone === undefined) delete process.env.TZ; else process.env.TZ = previousZone;
    let f = fixture(), opened = await f.open(), dialog = f.dialogs[0];
    assert.equal(f.calls.length, 0, 'Opening overview/Manage must not probe or mutate');
    assert.equal(f.tables[0].options.pageLength, 10); assert.equal(dialog.body.className, 'pdf-sealer-dialog-body');
    await dialog.body.querySelector('button').events.click();
    assert.equal(f.calls[0].action, 'test_timestamp_source'); assert.equal(f.calls[0].payload.source, 'remote-tsa-test');
    assert.equal(f.tables[0].draws, 1); assert.match(opened.row.querySelector('[data-tsa-last-test]').textContent, /tsa_test_passed.*local:/);
    assert.match(opened.row.querySelector('[data-tsa-expiry]').textContent, /UTC$/);
    assert.match(dialog.body.querySelector('[data-never]')?.textContent || all(dialog.body).find(n => n['role'] === 'status').textContent, /thumbprint/);
    await dialog.ctx.close(); await opened.completion;
    f = fixture({reply: () => ({ok: true, diagnostic: {ok: false, checked_at: 1900000000, valid_until: null}})});
    opened = await f.open(); dialog = f.dialogs[0]; await dialog.body.querySelector('button').events.click();
    assert.equal(f.notifications.at(-1).tone, 'error'); assert.match(opened.row.querySelector('[data-tsa-last-test]').textContent, /tsa_test_failed/);
    assert.equal(opened.row.querySelector('[data-tsa-expiry]').textContent, '—'); await dialog.ctx.close(); await opened.completion;
    let probeFinish;
    f = fixture({reply: () => new Promise(resolve => {probeFinish = resolve;})}); opened = await f.open(); dialog = f.dialogs[0];
    const probe = dialog.body.querySelector('button').events.click(); await tick();
    assert.equal(dialog.controls.close.disabled, true); assert.equal(await dialog.ctx.close(), false);
    await dialog.body.querySelector('button').events.click(); assert.equal(f.calls.length, 1);
    probeFinish({ok: true, diagnostic: {ok: false, checked_at: 1900000000, valid_until: null}}); await probe;
    await dialog.ctx.close(); await opened.completion;
    // Batch covers all registered external sources, continues after failures and preserves incomplete observations.
    f = fixture({sourceCount: 3, reply: (_action, payload) => {
        if (payload.source === 'remote-tsa-test') return {ok: true, diagnostic: {ok: false, checked_at: 1900000000, valid_until: null}};
        if (payload.source === 'remote-tsa-test-1') throw Error('Interrupted request');
        return {ok: true, diagnostic: {ok: true, checked_at: 1900000000, valid_until: 2000000000}};
    }});
    await f.ids['pdf-sealer-tsa-test-all'].events.click();
    assert.deepEqual(f.calls.map(call => call.payload.source), ['remote-tsa-test', 'remote-tsa-test-1', 'remote-tsa-test-2']);
    assert.ok(f.calls.every(call => call.action === 'test_timestamp_source'));
    assert.equal(f.notifications.at(-1).text, 'tsa_test_all_result:1,1,1'); assert.equal(f.notifications.at(-1).tone, 'warning');
    assert.equal(f.tables[0].draws, 2); assert.equal(f.sources[1].diagnostic, null);
    assert.equal(f.ids['pdf-sealer-tsa-test-all'].disabled, false);
    let batchFinish;
    f = fixture({sourceCount: 2, reply: () => new Promise(resolve => {batchFinish = resolve;})});
    const batch = f.ids['pdf-sealer-tsa-test-all'].events.click(); await tick();
    assert.equal(f.calls.length, 1, 'Batch requests must be sequential');
    assert.equal(f.ids['pdf-sealer-tsa-test-all'].disabled, true);
    await f.ids['pdf-sealer-tsa-test-all'].events.click(); await (await f.open()).completion;
    assert.equal(f.calls.length, 1); assert.equal(f.dialogs.length, 0, 'External Manage must wait for its batch test');
    batchFinish({ok: true, diagnostic: {ok: true, checked_at: 1900000000, valid_until: 2000000000}}); await tick();
    assert.equal(f.calls.length, 2); batchFinish({ok: true, diagnostic: {ok: true, checked_at: 1900000000, valid_until: 2000000000}}); await batch;
    assert.equal(f.notifications.at(-1).tone, 'success'); assert.equal(f.ids['pdf-sealer-tsa-test-all'].disabled, false);
    f = fixture({sourceCount: 0}); await f.ids['pdf-sealer-tsa-test-all'].events.click(); assert.equal(f.calls.length, 0); assert.equal(f.ids['pdf-sealer-tsa-test-all'].disabled, true);
    // Registration retains entered values after failure, prevents duplicate writes/dismissal, then closes before reload.
    let finish;
    f = fixture({reply: () => new Promise(resolve => {finish = resolve;})}); opened = await f.register(); dialog = f.dialogs[0];
    f.form.valid = false; await dialog.press('register'); assert.equal(f.calls.length, 0);
    f.form.valid = true; const write = dialog.press('register'); await tick();
    assert.equal(f.fields.disabled, true); assert.equal(dialog.controls.cancel.disabled, true); assert.equal(await dialog.ctx.close(), false);
    await dialog.press('register'); assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0].payload.password, 'secret', 'Capture FormData before disabling the fieldset');
    finish({ok: false, message: 'Safe chain error'}); await write;
    assert.equal(dialog.closed, false); assert.equal(f.form.resets, 0); assert.equal(f.form.payload.name, 'Service <name>');
    assert.equal(f.notifications.at(-1).text, 'Safe chain error');
    const retry = dialog.press('register'); await tick(); finish({ok: true}); await retry; await opened.completion;
    assert.equal(dialog.closed, true); assert.equal(f.form.resets, 1); assert.equal(f.host.firstElementChild, f.registerBody);
    const url = new URL(f.redirects[0]); assert.equal(url.searchParams.get('prefix'), 'pdf_sealer'); assert.equal(url.hash, '#tsa');
    // CA-scoped policy preserves order, clears None/fallback, pins provider and blocks ambiguous replay.
    f = fixture(); f.policy(); let c = f.policyControls;
    assert.equal(c.source.value, 'builtin-tsa'); assert.equal(c.alternatives[0].value, 'remote-tsa-test'); assert.equal(c.fallback.checked, true);
    c.alternatives[1].value = 'remote-tsa-other'; await c.save.events.click();
    assert.deepEqual(JSON.parse(JSON.stringify(f.calls[0].payload)), {provider: 'builtin-ca', source: 'builtin-tsa', alternatives: ['remote-tsa-test', 'remote-tsa-other'], fallback: true});
    assert.equal(f.ids['pdf-sealer-mode-summary'].textContent, 'timestamp_summary_internal');
    assert.deepEqual(f.busyValues, [true, false]); assert.equal(f.saved[0], 'provider_timestamp_internal');
    c.source.value = 'none'; c.source.events.change();
    assert.equal(c.fallback.disabled, true); assert.equal(c.fallback.checked, false); assert.ok(c.alternatives.every(n => n.disabled && n.value === ''));
    await c.save.events.click(); assert.equal(f.calls[1].payload.fallback, false); assert.equal(f.policies[0].timestamp_source, null);
    f = fixture(); f.policy(); c = f.policyControls;
    c.alternatives[1].value = c.alternatives[0].value; await c.save.events.click();
    assert.equal(f.calls.length, 0, 'Duplicate alternatives must not save');
    assert.equal(f.notifications.at(-1).text, 'timestamp_order_invalid');
    let policyFinish;
    f = fixture({reply: () => new Promise(resolve => {policyFinish = resolve;})}); f.policy(); c = f.policyControls;
    const policyWrite = c.save.events.click(); await tick(); await c.save.events.click(); assert.equal(f.calls.length, 1);
    assert.equal(c.fields.disabled, true); policyFinish({ok: true}); await policyWrite;
    f.policy(); assert.equal(f.policyControls.alternatives[0].value, 'remote-tsa-test', 'Reopened policy must use saved values');
    f = fixture({reply: () => ({ok: false})}); f.policy(); c = f.policyControls; await c.save.events.click(); await c.save.events.click();
    assert.equal(f.calls.length, 1); assert.equal(c.fields.disabled, true); assert.equal(f.saved.length, 0);
    // Built-in lifecycle obtains a fresh review, uses its hash and frozen reason, and never replays a failed mutation.
    f = fixture(); opened = await f.open('builtin-tsa'); dialog = f.dialogs[0];
    const lifecycle = dialog.body.querySelector('[data-tsa-lifecycle]').events.click(); await tick(); const confirmation = f.dialogs[1];
    assert.equal(f.calls[0].action, 'preview_tsa_lifecycle');
    const radios = all(confirmation.body).filter(n => n.type === 'radio'); radios[1].events.change();
    assert.equal(confirmation.controls.confirm.intent, 'danger'); await confirmation.press('confirm'); await lifecycle; await opened.completion;
    assert.deepEqual(JSON.parse(JSON.stringify(f.calls[1].payload)), {review_hash: 'locked-review', reason: 'superseded'});
    assert.equal(f.calls[1].action, 'revoke_tsa_certificate'); assert.equal(dialog.closed, true); assert.equal(new URL(f.redirects[0]).hash, '#tsa');
    f = fixture(); const cancel = f.context.window.PDFSealerTsaLifecycle(f.module, new Node('button')); await tick(); dialog = f.dialogs[0];
    await dialog.ctx.close(); await cancel; assert.equal(f.calls.length, 1, 'Lifecycle Cancel must only review');
    let lifecycleFinish;
    f = fixture({reply: () => new Promise(resolve => {lifecycleFinish = resolve;})});
    const pending = f.context.window.PDFSealerTsaLifecycle(f.module, new Node('button')); await tick(); dialog = f.dialogs[0];
    const confirm = dialog.press('confirm'); await tick();
    assert.equal(dialog.controls.cancel.disabled, true); assert.equal(await dialog.ctx.close(), false);
    await dialog.press('confirm'); assert.equal(f.calls.length, 2);
    const pendingRadios = all(dialog.body).filter(n => n.type === 'radio'); pendingRadios[2].events.change();
    lifecycleFinish({ok: true, replacement: 'pending', crl_published: false}); await confirm;
    const outcome = await pending; assert.equal(outcome.receipt.tone, 'warning');
    assert.equal(f.calls[1].payload.reason, 'replace', 'Busy action changes must not alter the reviewed reason');
    f = fixture({revoked: true, reply: () => ({ok: false})});
    const completion = f.context.window.PDFSealerTsaLifecycle(f.module, new Node('button')); await tick(); dialog = f.dialogs[0];
    assert.ok(all(dialog.body).filter(n => n.type === 'radio' && n.value !== 'replace').every(n => n.disabled));
    await dialog.press('confirm'); await dialog.press('confirm'); assert.equal(f.calls.length, 2); assert.equal(f.calls[1].action, 'replace_tsa_certificate');
    await dialog.ctx.close(); await completion;
    console.log('TSA UI: overview, explicit probes, registration errors/busy guards, CA policy order/None/replay and locked lifecycle dialogs passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
