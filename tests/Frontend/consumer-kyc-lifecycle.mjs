import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

test('KYC keeps draft values during photo picker background and clears on navigation or unmount', () => {
    const hooks = {};
    const doc = { hidden: false, addEventListener: (event, fn) => hooks[event] = fn, removeEventListener: event => delete hooks[event] };
    const exports = {};
    runInNewContext(ts.transpileModule(readFileSync('mobile/uni-app/src/lib/sensitive.ts', 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS },
    }).outputText, { exports, document: doc, require: id => id === 'vue'
        ? { onBeforeUnmount: fn => hooks.unmount = fn } : { onHide: fn => hooks.hide = fn } });
    let draft = 'number and front image';
    exports.useSensitiveScreen(() => draft = '', { retainOnBackground: true });
    doc.hidden = true;
    hooks.hide();
    hooks.visibilitychange();
    assert.equal(draft, 'number and front image');
    doc.hidden = false;
    hooks.visibilitychange();
    assert.equal(draft, 'number and front image');
    hooks.hide();
    assert.equal(draft, '');
    draft = 'another image';
    hooks.unmount();
    assert.equal(draft, '');
    assert.equal(hooks.visibilitychange, undefined);

    exports.useSensitiveScreen(() => draft = '');
    draft = 'PIN';
    doc.hidden = true;
    hooks.hide();
    assert.equal(draft, '');
});

test('KYC foreground return does not refetch or discard an in-flight page response', async () => {
    const hooks = {};
    const doc = { hidden: false };
    let requests = 0;
    let resolvePage;
    const source = readFileSync('mobile/uni-app/src/pages/screen/index.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    const js = ts.transpileModule(source + '\nexport { data, load };', { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    const exports = {};
    runInNewContext(js, { exports, document: doc, uni: {}, require: id => {
        if (id === 'vue') return { ref: value => ({ value }), nextTick: async () => {}, onErrorCaptured() {} };
        if (id === '@dcloudio/uni-app') return { onPageScroll() {}, onLoad: fn => hooks.load = fn, onShow: fn => hooks.show = fn, onHide: fn => hooks.hide = fn };
        if (id.endsWith('/client')) return {
            ensureBootstrap: async () => {}, explainError: () => ({}),
            getPage: () => { requests++; return new Promise(resolve => resolvePage = resolve); },
        };
        if (id.endsWith('/api')) return { setCurrentPage() {}, ApiError: class extends Error {} };
        return {};
    } });
    hooks.load({ path: '/kyc?from=account-security' });
    const initial = exports.load();
    await new Promise(setImmediate);
    assert.equal(requests, 1);
    doc.hidden = true; hooks.hide();
    resolvePage({ component: 'user/Kyc', props: { canSubmit: true } });
    await initial;
    assert.equal(exports.data.value.component, 'user/Kyc');
    doc.hidden = false; hooks.show();
    await new Promise(setImmediate);
    assert.equal(requests, 1);
    hooks.hide(); hooks.show();
    await new Promise(setImmediate);
    assert.equal(requests, 2);
});
