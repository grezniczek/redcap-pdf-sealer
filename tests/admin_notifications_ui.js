/* Core's toast API renders HTML: verify that module feedback stays text and errors use its error type. */
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const calls = [];
const context = {window: {showToast: (...args) => calls.push(args)}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/admin-notifications.js'), 'utf8'), context);
context.window.PDFSealerNotify("<img src=x onerror=\"alert(1)\"> & 'CA'\nSecond line", 'danger');
assert.deepEqual(calls[0], ['PDF Sealer', '&lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; &#39;CA&#39;<br>Second line', 'error', 6000]);
context.window.PDFSealerNotify('Saved'); assert.equal(calls[1][2], 'success');
context.window.PDFSealerNotify('Pending recovery', 'warning'); assert.equal(calls[2][3], 12000);
context.window.PDFSealerNotify('Unavailable', 'error'); assert.equal(calls[3][2], 'error');
console.log('Administration toast: escaped names/content, newlines, default success and persistent-error type passed');
