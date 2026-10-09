import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const compile = source => ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

test('topup distinguishes malformed amounts from amounts below the configured minimum', () => {
    const exact = {}, exports = {};
    runInNewContext(compile(readFileSync('mobile/uni-app/src/generated/exact-amount.ts', 'utf8')), { exports: exact });
    const component = readFileSync('mobile/uni-app/src/screens/Topup.vue', 'utf8');
    const source = component.split('<script setup lang="ts">')[1].split('</script>')[0];
    runInNewContext(compile(source + '\nexport { amount, valid, amountError };'), {
        exports, defineProps: () => ({ page: { minimum: '20' } }),
        require: name => {
            if (name === 'vue') return { ref: value => ({ value }), computed: fn => ({ get value() { return fn(); } }) };
            if (name === '../generated/exact-amount') return exact;
            return { requestId: () => 'test', useAction: () => ({}), t: value => value };
        },
    });
    assert.equal(exports.amountError.value, '');
    for (const value of ['0', '-1', '20.001', 'abc']) {
        exports.amount.value = value;
        assert.equal(exports.valid.value, false);
        assert.equal(exports.amountError.value, 'Enter a positive amount with at most 2 decimal places.');
    }
    exports.amount.value = '19.99';
    assert.equal(exports.amountError.value, 'The amount is below the minimum deposit.');
    exports.amount.value = '20';
    assert.equal(exports.amountError.value, '');
    assert.equal(exports.valid.value, true);
    assert.match(component, /:error="amountError"/);
});
