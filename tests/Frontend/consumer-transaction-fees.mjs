import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
function load(path) {
    const exports = {};
    const compiled = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    runInNewContext(compiled, { exports, require: (name) => load(resolve(dirname(path), name + '.ts')) });
    return exports;
}
const { transactionMoney, transactionPage } = load(resolve('resources/js/lib/card-transactions.ts'));
test('provider money preserves exact decimals, signs and independent currencies', () => {
    assert.equal(transactionMoney('-0.12345678', 'USD'), '-$0.12345678');
    assert.equal(transactionMoney('2.01000000', 'CNY'), '￥2.01');
    assert.equal(transactionMoney('2.01000000', 'RMB'), '￥2.01');
    assert.equal(transactionMoney('0.00000000', 'USD'), '$0');
    assert.equal(transactionMoney('2.01000000', 'EUR'), '2.01 EUR');
    assert.equal(transactionMoney('999999999999.12345678', 'USD'), '$999999999999.12345678');
});
test('read DTO rejects incomplete fee pairs and distinguishes absent and zero', () => {
    const item = { id: 'a'.repeat(64), cardId: 'card', last4: '1234', amount: '1.00000000', currency: 'CNY', type: 'purchase', state: 'completed', displayAt: '2026-09-29T09:00:00Z', timeKind: 'recorded', merchant: null };
    const page = (fields) => transactionPage({ page: 1, hasMore: false, items: [{ ...item, ...fields }] }, 'card', 1);
    assert.equal(page({ feeAmount: null, feeCurrency: null }).items[0].feeAmount, null);
    assert.equal(page({ feeAmount: '0.00000000', feeCurrency: 'USD' }).items[0].feeAmount, '0.00000000');
    assert.throws(() => page({ feeAmount: '1.00000000' }));
    assert.throws(() => page({ feeReturnAmount: '0.000000001', feeReturnCurrency: 'USD' }));
});

test('consumer transaction rows render provider fees separately from purchase currency', async () => {
    const React = await import('react');
    const jsx = await import('react/jsx-runtime');
    const { renderToStaticMarkup } = await import('react-dom/server');
    const exports = {};
    const items = [
        { id: 'one', last4: '1234', amount: '25.00000000', currency: 'CNY', type: 'purchase', state: 'completed', merchant: 'Example shop', displayAt: '2026-09-29T09:00:00Z', feeAmount: '-0.12345678', feeCurrency: 'USD', feeReturnAmount: '0.01000000', feeReturnCurrency: 'CNY' },
        { id: 'two', last4: '1234', amount: '2.00000000', currency: 'USD', type: 'purchase', state: 'completed', merchant: null, displayAt: '2026-09-29T09:00:00Z', feeAmount: null, feeCurrency: null },
    ];
    const dependencies = {
        'react/jsx-runtime': jsx,
        'lucide-react': { CreditCard: () => null, LoaderCircle: () => null },
        '@/components/ui/button': { Button: () => null },
        '@/i18n': { t: (key) => key, dateTime: (date) => date, dateLabel: (date) => date.slice(0, 10), useClientTranslation: () => null },
        '@/hooks/useCardTransactions': { useCardTransactions: () => ({ items, failed: 0, loading: false, hasMore: false }) },
        '@/lib/card-transactions': load(resolve('resources/js/lib/card-transactions.ts')),
    };
    const compiled = ts.transpileModule(readFileSync('resources/js/components/user/UserCardTransactions.tsx', 'utf8'), { compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    runInNewContext(compiled, { exports, require: (name) => { assert.ok(dependencies[name], name); return dependencies[name]; } });
    const html = renderToStaticMarkup(React.createElement(exports.UserCardTransactions, { cardIds: ['card'] }));
    assert.ok(html.includes('￥25'));
    assert.ok(html.includes('-$0.12345678'));
    assert.ok(html.includes('￥0.01'));
    assert.ok(html.includes('Transaction fee'));
    assert.ok(html.includes('Fee refund'));
    assert.ok(html.includes('—'));
});

test('fee validation identifies the exact optional field and failure without exposing values', () => {
    const item = { id: 'a'.repeat(64), cardId: 'card', last4: '1234', amount: '1.00000000', currency: 'USD', type: 'purchase', state: 'completed', displayAt: '2026-09-29T09:00:00Z', timeKind: 'recorded', merchant: null };
    assert.throws(() => transactionPage({ page: 1, hasMore: false, items: [{ ...item, feeReturnAmount: null, feeReturnCurrency: 'USD' }] }, 'card', 1), error =>
        error.field === 'feeReturnAmount' && error.detail === 'amount_missing' && error.message === 'Invalid transaction fee');
    assert.throws(() => transactionPage({ page: 1, hasMore: false, items: [{ ...item, feeAmount: '0.00000000', feeCurrency: null }] }, 'card', 1), error =>
        error.field === 'feeAmount' && error.detail === 'currency_missing');
});

test('day sections keep independent records and exact fees across cards and pagination', () => {
    const { transactionSections } = load(resolve('resources/js/lib/card-transactions.ts'));
    const a = { id: 'a', cardId: 'card-a', displayAt: '2026-10-15T10:00:00Z', amount: '-10.00000000', feeAmount: '-0.10000000', feeCurrency: 'USD' };
    const b = { ...a, id: 'b', cardId: 'card-b' };
    const c = { ...a, id: 'c', displayAt: '2026-10-14T10:00:00Z' };
    const groups = transactionSections([c, a, b, a], value => value.slice(0, 10));
    assert.deepEqual(Array.from(groups, group => group.day), ['2026-10-15', '2026-10-14']);
    assert.equal(groups[0].items.length, 2);
    assert.equal(groups[0].items[0].feeAmount, '-0.10000000');
    assert.equal(groups[1].items.length, 1);
});

test('all documented U1 read types pass the shared client contract', () => {
    const row = { id: 'a'.repeat(64), cardId: 'card', last4: '1234', amount: '-1.00000000', currency: 'USD', state: 'completed', displayAt: '2026-10-15T09:00:00Z', timeKind: 'recorded', merchant: null, feeAmount: '-0.03000000', feeCurrency: 'USD' };
    for (const type of ['authorization', 'refund', 'fund_collection', 'atm_deposit', 'reversal', 'verification', 'settlement_debit', 'settlement_credit', 'settlement_reversal', 'chargeback_debit', 'chargeback_credit']) {
        assert.equal(transactionPage({ page: 1, hasMore: false, items: [{ ...row, type }] }, 'card', 1).items[0].type, type);
    }
});
