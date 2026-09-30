import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const compile = path => ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;

test('card drafts survive hide and screen lock but clear when their form is destroyed', () => {
    const hooks = {}, exports = {};
    const document = { hidden: false, addEventListener: (name, fn) => hooks[name] = fn, removeEventListener: name => delete hooks[name] };
    runInNewContext(compile('mobile/uni-app/src/lib/sensitive.ts'), { exports, document, require: name => name === 'vue'
        ? { onBeforeUnmount: fn => hooks.unmount = fn } : { onHide: fn => hooks.hide = fn } });
    let draft = 'name, email, phone, current step';
    exports.useSensitiveScreen(() => draft = '', { retainUntilUnmount: true });
    hooks.hide();
    document.hidden = true;
    hooks.visibilitychange();
    hooks.hide();
    document.hidden = false;
    hooks.visibilitychange();
    assert.equal(draft, 'name, email, phone, current step');
    hooks.unmount();
    assert.equal(draft, '');
    assert.equal(hooks.visibilitychange, undefined);
});

test('open application suppresses foreground reload without disabling explicit submission refresh', async () => {
    const hooks = {}, exports = {};
    let editing = true, requests = 0;
    runInNewContext(compile('mobile/uni-app/src/lib/screen.ts'), { exports, uni: {}, require: name => {
        if (name === 'vue') return { ref: value => ({ value }) };
        if (name === '@dcloudio/uni-app') return { onShow: fn => hooks.show = fn };
        if (name === './api') return { ApiError: class extends Error {} };
        return { requireUser: async () => true, clearSession: () => {} };
    } });
    const screen = exports.useScreen(async () => { requests++; }, { refreshOnShow: () => !editing });
    hooks.show();
    await Promise.resolve();
    assert.equal(requests, 0);
    await screen.refresh();
    assert.equal(requests, 1);
    editing = false;
    hooks.show();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests, 2);
});
