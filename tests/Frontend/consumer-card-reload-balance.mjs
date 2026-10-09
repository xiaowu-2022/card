import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const compile = source => ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const component = readFileSync('mobile/uni-app/src/components/CardManagement.vue', 'utf8');
function harness(balance = '0.00000000') {
    const money = {};
    runInNewContext(compile(readFileSync('mobile/uni-app/src/lib/card-types.ts', 'utf8')), { exports: money });
    const props = { card: { id: 'test', balance: '0', minimumReload: '20' }, availableBalance: balance, walletAsset: 'USDT' };
    const exports = {};
    const source = component.split('<script setup lang="ts">')[1].split('</script>')[0];
    runInNewContext(compile(source + '\nexport { active, amount, order, insufficientReloadBalance, amountValid, amountError };'), {
        exports, defineProps: () => props, defineEmits: () => () => {},
        require: name => {
            if (name === 'vue') return { ref: value => ({ value }), computed: fn => ({ get value() { return fn(); } }), onBeforeUnmount() {} };
            if (name === '../lib/card-types') return money;
            return { requestId: () => 'test', useSensitiveScreen() {}, t: value => value };
        },
    });
    exports.active.value = 'load';
    return { ...exports, props };
}

test('reload explains zero or below-minimum funds immediately and clears when enough is available', () => {
    const h = harness();
    assert.equal(h.insufficientReloadBalance.value, true);
    h.props.availableBalance = '19.99999999';
    assert.equal(h.insufficientReloadBalance.value, true);
    h.props.availableBalance = '20';
    assert.equal(h.insufficientReloadBalance.value, false);
    h.amount.value = '20.01';
    assert.equal(h.insufficientReloadBalance.value, true);
    assert.equal(h.amountValid.value, false);
    h.amount.value = '20';
    assert.equal(h.insufficientReloadBalance.value, false);
    assert.equal(h.amountValid.value, true);
    assert.match(component, /:error="amountError"/);
});

test('unknown balances, invalid amounts, other actions and completed operations do not claim insufficient funds', () => {
    const h = harness(null);
    h.amount.value = '20';
    assert.equal(h.insufficientReloadBalance.value, false);
    h.props.availableBalance = '0';
    h.amount.value = 'invalid';
    assert.equal(h.insufficientReloadBalance.value, false);
    h.amount.value = '20';
    h.active.value = 'return';
    assert.equal(h.insufficientReloadBalance.value, false);
    h.active.value = 'load';
    h.order.value = { state: 'completed' };
    assert.equal(h.insufficientReloadBalance.value, false);
});


test('card amount errors identify precision, minimum and return balance without blocking valid amounts', () => {
    const h = harness('100');
    h.amount.value = '0';
    assert.equal(h.amountError.value, 'Enter a positive amount with at most 2 decimal places.');
    h.amount.value = '20.001';
    assert.equal(h.amountError.value, 'Enter a positive amount with at most 2 decimal places.');
    h.amount.value = '19.99';
    assert.equal(h.amountError.value, 'The amount is below this card’s minimum reload.');
    h.amount.value = '101';
    assert.equal(h.amountError.value, 'Your available balance is not enough.');
    h.amount.value = '20';
    assert.equal(h.amountError.value, '');
    h.active.value = 'return';
    h.props.card.balance = '19.99999999';
    assert.equal(h.amountError.value, 'The return amount exceeds the available card balance.');
    h.props.card.balance = '20';
    assert.equal(h.amountError.value, '');
});
