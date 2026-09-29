import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const compile = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;

test('card setup prefills account email and submits contacts without uploads or hidden defaults', async () => {
    const source = readFileSync('mobile/uni-app/src/components/CardholderMaterials.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    const calls = [], hooks = [], exports = {};
    runInNewContext(compile(source + '\nexport { fields, submit };'), {
        exports, defineProps: () => ({ productId: 'product', formFactor: 'virtual_card' }), defineEmits: () => () => {},
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }) };
            if (id.endsWith('/session')) return { session: { value: { user: { email: 'account@example.test' } } } };
            if (id.endsWith('/sensitive')) return { useSensitiveScreen: fn => hooks.push(fn) };
            if (id.endsWith('/api')) return { request: async (...args) => calls.push(args), ApiError: class extends Error {} };
            if (id.endsWith('/client')) return { requestId: () => 'request', explainError: () => ({ form: 'error' }) };
            return {};
        },
    });
    assert.equal(exports.fields.value.email, 'account@example.test');
    Object.assign(exports.fields.value, { legal_first_name: 'Amy', legal_last_name: 'Chen', mobile: '13800138000' });
    await exports.submit();
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/client/cards/cardholder');
    assert.equal(calls[0][1], 'POST');
    assert.deepEqual(Object.keys(calls[0][2]).sort(), ['legal_first_name', 'legal_last_name', 'email', 'mobile', 'mobile_country_code', 'card_product_id', 'form_factor', 'request_id'].sort());
    hooks[0]();
    assert.ok(Object.values(exports.fields.value).every(value => value === ''));
});

test('card edits include changed names and phone pair but never fixed or document fields', () => {
    const exports = {};
    runInNewContext(compile(readFileSync('resources/js/lib/cardholder-changes.ts', 'utf8')), { exports });
    const result = exports.cardholderChanges({ legal_first_name: 'Amy', mobile: '13800138000', mobile_country_code: 'CN', residential_address: 'tampered', nationality_country_code: 'US', front_url: 'https://example.test/' }, {});
    assert.deepEqual(JSON.parse(JSON.stringify(result)), { legal_first_name: 'Amy', mobile: '13800138000', mobile_country_code: 'CN' });
});
