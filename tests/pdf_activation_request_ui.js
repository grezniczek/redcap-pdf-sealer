'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const frameworkRoot = process.env.PDF_FINALIZE_FRAMEWORK_ROOT || path.resolve(__dirname, '../../../external_modules');
const source = fs.readFileSync(path.join(frameworkRoot, 'manager/js/project.js'), 'utf8');

function approval(inFrame) {
    const state = {disabled: false, closedFrames: 0, alerts: [], request: null, dialog: null};
    const context = {
        $: value => {
            if (typeof value === 'function') { return; }
            assert.equal(value, '.external-module-activation-request .enable-button');
            return {prop: (name, value) => {
                assert.equal(name, 'disabled');
                state.disabled = value;
            }};
        },
        ExternalModules: {enableModule: (...args) => { state.request = args; }},
        inIframe: () => inFrame,
        closeToDoListFrame: () => { state.closedFrames++; },
        simpleDialog: (...args) => { state.dialog = args; },
        alert: message => { state.alerts.push(message); },
        console: {log() {}},
        window: {location: {href: ''}},
        pid: '533'
    };
    vm.runInNewContext(source, context, {filename: 'project.js'});
    context.ExternalModules.adminActivateModule('pdf_sealer', 'v9.9.9', '14');
    assert.equal(state.disabled, true);
    assert.equal(state.request[0], 'pdf_sealer');
    assert.equal(state.request[1], 'v9.9.9');
    assert.equal(state.request[2], false);
    assert.equal(state.request[3], '14');
    assert.equal(state.request[6], null);
    return {state, context, succeeded: state.request[4], failed: state.request[5], canceled: state.request[7]};
}

test('canceling Core placement closes the outer To-Do frame without running success', () => {
    const view = approval(true);
    view.canceled();
    assert.equal(view.state.closedFrames, 1);
    assert.equal(view.state.dialog, null);
    assert.deepEqual(view.state.alerts, []);
});

test('canceling a directly opened approval page leaves its Enable button usable', () => {
    const view = approval(false);
    view.canceled();
    assert.equal(view.state.closedFrames, 0);
    assert.equal(view.state.disabled, false);
    assert.equal(view.state.dialog, null);
});

test('enablement errors retain the frame and restore the Enable button', () => {
    const view = approval(true);
    view.failed('save failed');
    assert.equal(view.state.closedFrames, 0);
    assert.equal(view.state.disabled, false);
    assert.deepEqual(view.state.alerts, ['ERROR: save failed']);
});

test('successful approval closes the To-Do frame', () => {
    const view = approval(true);
    view.succeeded();
    assert.equal(view.state.closedFrames, 1);
});

test('successful direct approval redirects after acknowledging success', () => {
    const view = approval(false);
    view.succeeded();
    assert.equal(view.state.closedFrames, 0);
    assert.equal(view.context.window.location.href, '');
    view.state.dialog[4]();
    assert.equal(view.context.window.location.href, 'project.php?pid=533');
});
