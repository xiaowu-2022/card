import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const source = readFileSync('mobile/webview-shell/src/pages/index/index.vue', 'utf8');
function extract(name, next) { return source.slice(source.indexOf(name), source.indexOf(next, source.indexOf(name))); }
test('failed native capture keeps its hidden document and retry uses the same identity confirmation', async () => {
    let closed = 0, captures = 0, reconnects = 0;
    const view = { close: () => closed++, setStyle: value => assert.equal(value.opacity, 0) };
    const context = {
        child: view, disposed: false, signedIn: true, sessionSequence: 7, loadSequence: 2,
        detecting: false, loginRecoveryFailed: { value: true }, state: { value: 'ready' }, error: { value: '' },
        finishStartup: undefined, finishDocument: undefined, finishContent: undefined,
        debugLog() {}, stopTimer() {}, trustedLoginPage: v => v === view,
        setTimeout: () => 1, connect: () => reconnects++,
        confirmPageIdentity: async (v, sequence) => { assert.equal(v, view); assert.equal(sequence, 7); captures++; },
    };
    runInNewContext(ts.transpileModule(
        extract('function failure(', 'function contentBounds(') + '\n' + extract('async function retry()', 'function signInAgain('),
        { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
    ).outputText, context);
    context.failure('capture unavailable', true);
    assert.equal(closed, 0); assert.equal(context.child, view); assert.equal(context.state.value, 'error');
    await context.retry();
    assert.equal(captures, 1); assert.equal(reconnects, 0); assert.equal(context.detecting, false);
    context.failure('untrusted page');
    assert.equal(closed, 1); assert.equal(context.child, null);
});
