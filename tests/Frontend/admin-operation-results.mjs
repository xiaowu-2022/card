import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import vm from 'node:vm';

function store(pathname = '/platform/tenants') {
    const source = readFileSync(
        'resources/js/components/admin/operation-result.ts',
        'utf8',
    ).replace(
        "import { errorMessage, t } from '@/i18n/admin';",
        'const t = (value: string) => value; const errorMessage = t;',
    );
    const compiled = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const context = vm.createContext({ exports: {}, location: { pathname } });
    vm.runInContext(compiled, context);
    return context.exports;
}

test('field and transport failures deduplicate, but identical retries remain visible', () => {
    const s = store();
    s.showOperationResult('error', ['Required', 'Required']);
    s.showOperationResult('error', 'Required');
    assert.equal(s.getOperationResults().length, 1);
    assert.equal(s.getOperationResults()[0].messages.length, 1);
    s.acknowledgeOperationResult();
    assert.equal(s.getOperationResults().length, 0);
    s.showOperationResult('error', 'Required');
    assert.equal(s.getOperationResults()[0].messages[0], 'Required');
});
test('acknowledging success cannot discard a pending failure', () => {
    const s = store();
    s.showOperationResult('success', 'Saved');
    s.showOperationResult('error', 'Network failed');
    s.acknowledgeOperationResult();
    assert.equal(s.getOperationResults()[0].kind, 'error');
    assert.equal(s.getOperationResults()[0].messages[0], 'Network failed');
});
test('specific success replaces the generic transport fallback in either order', () => {
    for (const sequence of [
        ['Request completed.', 'Saved'],
        ['Saved', 'Request completed.'],
    ]) {
        const s = store();
        sequence.forEach((message) => s.showOperationResult('success', message));
        assert.equal(s.getOperationResults()[0].messages.join(','), 'Saved');
    }
});
test('feedback is scoped to Platform and ignores blank messages', () => {
    const consumer = store('/user/cards');
    consumer.showOperationResult('error', 'Failed');
    assert.equal(consumer.getOperationResults().length, 0);
    const platform = store();
    platform.showOperationResult('error', ['', '  ']);
    assert.equal(platform.getOperationResults().length, 0);
});
test('subscription cleanup prevents notifications after host unmount', () => {
    const s = store();
    let notifications = 0;
    const unsubscribe = s.subscribeOperationResults(() => notifications++);
    s.showOperationResult('error', 'Failed');
    s.showOperationResult('error', 'Failed');
    assert.equal(notifications, 1);
    unsubscribe();
    s.acknowledgeOperationResult();
    assert.equal(notifications, 1);
});
