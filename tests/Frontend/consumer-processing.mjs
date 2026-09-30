import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
function harness() {
    let dispose, resolve, reject, progress;
    const calls = [], notices = [], exports = {};
    class ApiError extends Error { constructor(status) { super(); this.status = status; } }
    const request = (...args) => { calls.push(args); return new Promise((yes, no) => { resolve = yes; reject = no; }); };
    const upload = (...args) => { progress = args[3]; return request(...args); };
    const api = { ApiError, request, upload, native: false };
    const source = ts.transpileModule(readFileSync('mobile/uni-app/src/lib/client.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
    runInNewContext(source, { exports, uni: { showToast: o => notices.push(o) }, require: id => id === 'vue' ? { ref: value => ({ value }), onScopeDispose: cb => { dispose = cb; } } : id === './api' ? api : { t: s => s, errorMessage: s => s } });
    const action = exports.useAction();
    return { action, calls, notices, finish: value => resolve(value), timeout: () => reject(new ApiError(0)), dispose: () => dispose(), progress: p => progress(p) };
}
test('pending action blocks duplicates and manual review response finishes without pretending approved', async () => {
    const h = harness();
    const run = h.action.submit('/kyc/applications', { reverify: true }, { files: [{ name: 'front', path: 'local' }], navigate: false });
    assert.equal(h.action.pending.value, true);
    await h.action.submit('/kyc/applications', {}, { navigate: false });
    assert.equal(h.calls.length, 1);
    h.finish({ success: 'Your identity documents were submitted for review.' });
    await run;
    assert.equal(h.action.pending.value, false);
    assert.equal(h.notices[0].title, 'Your identity documents were submitted for review.');
});
test('timeout clears busy state without replaying a card opening', async () => {
    const h = harness();
    const run = h.action.submit('/cards/issues', { request_id: 'same-intent' }, { navigate: false });
    h.timeout(); await run;
    assert.equal(h.action.pending.value, false);
    assert.equal(h.action.failureStatus.value, 0);
    assert.equal(h.calls.length, 1);
    assert.equal(h.notices.length, 0);
});
test('disposed screens ignore late upload progress and completion', async () => {
    const h = harness(); let updates = 0, completed = 0;
    const run = h.action.submit('/kyc/applications', {}, { files: [{ name: 'front', path: 'local' }], onProgress: () => updates++, success: () => completed++, navigate: false });
    h.progress({ stage: 'uploading' }); assert.equal(updates, 1);
    h.dispose(); h.progress({ stage: 'submitting' }); h.finish({ success: 'done' }); await run;
    assert.equal(updates, 1); assert.equal(completed, 0); assert.equal(h.notices.length, 0);
});
test('overlay timer counts wall time, survives foreground delay and cleans up', () => {
    let watched, tick, dispose, now = 0, cleared = 0;
    const refs = [], props = { open: true, message: 'processing' };
    const source = readFileSync('mobile/uni-app/src/components/ProcessingOverlay.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, {
        exports: {}, defineProps: () => props, Date: { now: () => now },
        setInterval: cb => { tick = cb; return 1; }, clearInterval: () => cleared++,
        require: id => id === 'vue' ? { computed: fn => ({ get value() { return fn(); } }), ref: value => { const r = { value }; refs.push(r); return r; }, watch: (_, cb) => { watched = cb; cb(true); }, onBeforeUnmount: cb => { dispose = cb; } } : { t: s => s },
    });
    now = 16000; tick(); assert.equal(refs[0].value, 16);
    now = 65000; tick(); assert.equal(refs[0].value, 65);
    watched(false); assert.equal(refs[0].value, 0); assert.equal(cleared, 1);
    watched(true); dispose(); assert.equal(cleared, 2);
});
