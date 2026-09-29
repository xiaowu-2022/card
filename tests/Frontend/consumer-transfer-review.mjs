import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

function screen(lookup) {
    const source = readFileSync('mobile/uni-app/src/screens/Transfer.vue', 'utf8');
    const script = source.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1].replace(/^import .*;\s*$/gm, '');
    const code = ts.transpileModule(script + '\nreturn { form, submit, changed, reviewing, recipientEmail };', { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.None } }).outputText;
    let serial = 0;
    const storage = new Map(), sent = [];
    const action = { pending: { value: false }, errors: { value: {} }, failureStatus: { value: null }, submit: async (...args) => sent.push(args) };
    const context = {
        defineProps: () => ({ page: { accountId: '111111111111', receipt: null, transferAvailable: true, assets: [{ asset: 'USDT', amount: '100', available: true }] } }),
        computed: fn => ({ get value() { return fn(); } }), reactive: o => o, ref: value => ({ value }),
        session: { value: { tenant: { id: 'test-company' } } }, useSensitiveScreen: () => {}, useAction: () => action,
        requestId: () => `00000000-0000-4000-8000-${String(++serial).padStart(12, '0')}`, request: lookup,
        uni: { getStorageSync: k => storage.get(k), setStorageSync: (k, v) => storage.set(k, v), removeStorageSync: k => storage.delete(k) },
        t: s => s, explainError: () => ({ form: 'Lookup failed' }),
    };
    const state = new Function(...Object.keys(context), code)(...Object.values(context));
    Object.assign(state.form, { recipient_account_id: '222222222222', amount: '12.34000001' });
    return { ...state, action, sent, source };
}
test('review resolves recipient email and retains exact quantity until explicit confirmation', async () => {
    const s = screen(async path => { assert.match(path, /transfer-recipient/); return { accountId: '222222222222', email: 'recipient@example.test', asset: 'USDT' }; });
    await s.submit();
    assert.equal(s.reviewing.value, true);
    assert.equal(s.recipientEmail.value, 'recipient@example.test');
    assert.equal(s.sent.length, 0);
    await s.submit();
    assert.equal(s.sent.length, 0);
    Object.assign(s.form, { confirmed: true, current_password: 'transient-test-password' });
    await s.submit();
    assert.equal(s.sent[0][1].amount, '12.34000001');
    assert.equal(s.form.current_password, '');
    assert.match(s.source, /<Modal[\s\S]*:open="reviewing"/);
    assert.match(s.source, /Recipient email/);
    assert.match(s.source, /Transfer quantity/);
});
test('lookup failures cannot submit money', async () => {
    const s = screen(async () => { throw Error('unavailable'); });
    await s.submit();
    assert.equal(s.reviewing.value, false);
    assert.equal(s.sent.length, 0);
    assert.equal(s.action.errors.value.form, 'Lookup failed');
});
test('a stale recipient response cannot confirm an edited draft', async () => {
    let resolve;
    const s = screen(() => new Promise(r => { resolve = r; }));
    const pending = s.submit();
    s.form.recipient_account_id = '333333333333';
    s.changed();
    resolve({ accountId: '222222222222', email: 'recipient@example.test', asset: 'USDT' });
    await pending;
    assert.equal(s.reviewing.value, false);
    assert.equal(s.recipientEmail.value, '');
});
