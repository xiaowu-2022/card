import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function harness() {
    const cache = new Map();
    class ApiError extends Error {
        constructor(status, payload) { super(); this.status = status; this.payload = payload; }
    }
    function load(path) {
        path = resolve(path);
        if (cache.has(path)) return cache.get(path);
        const exports = {};
        cache.set(path, exports);
        const source = ts.transpileModule(readFileSync(path, 'utf8'), {
            compilerOptions: { module: ts.ModuleKind.CommonJS },
        }).outputText;
        runInNewContext(source, {
            exports,
            require: id => {
                if (id === 'vue') return { ref: value => ({ value }) };
                if (id === './api') return { ApiError };
                if (id === './session' || id === './navigation') return {};
                return load(resolve(dirname(path), id + '.ts'));
            },
        });
        return exports;
    }
    const i18n = load('mobile/uni-app/src/lib/i18n.ts');
    i18n.locale.value = 'zh-CN';
    return { i18n, ApiError, explainError: load('mobile/uni-app/src/lib/client.ts').explainError };
}

test('exchange balance errors display the accurate Chinese message through the real client catalog', () => {
    const { ApiError, explainError } = harness();
    const result = explainError(new ApiError(422, {
        error: { code: 'INSUFFICIENT_AVAILABLE_BALANCE', message: 'Your available balance is not enough.' },
    }));
    assert.equal(result.form, '可用余额不足，请先充值。');
});


test('known consumer failures retain specific localized explanations rather than the generic fallback', () => {
    const { ApiError, explainError, i18n } = harness();
    const messages = [
        'Market prices are unavailable. Please try again later.',
        'Enter a positive amount.',
        'Enter a positive amount with at most two decimal places.',
        'The amount exceeds this network precision.',
        'The amount is below the minimum deposit.',
        'Try a different deposit amount.',
        'This network is not available.',
        'Enter a valid destination address.',
        'The withdrawal fee changed. Review the updated amount.',
        'The amount must exceed the withdrawal fee.',
        'This request identifier was already used with different details.',
        'The card balance return is awaiting confirmation.',
        'Complete identity verification and the required security deposit before reloading.',
        'A confirmed recipient is required.',
        'An active USDT wallet is required.',
    ];
    for (const locale of ['zh-CN', 'ms', 'es']) {
        i18n.locale.value = locale;
        const fallback = i18n.t('Unable to complete this request. Check your information and current status.');
        for (const message of messages) {
            const result = explainError(new ApiError(422, { error: { message } }));
            assert.notEqual(result.form, fallback, `${locale}: ${message}`);
            assert.notEqual(result.form, message, `${locale}: ${message}`);
        }
        assert.equal(i18n.errorMessage('untrusted provider internals'), fallback);
    }
    i18n.locale.value = 'zh-CN';
    assert.match(i18n.errorMessage('Market prices are unavailable. Please try again later.'), /汇率/);
    assert.match(i18n.errorMessage('The withdrawal fee changed. Review the updated amount.'), /手续费已变化/);
    assert.match(i18n.errorMessage('The card balance return is awaiting confirmation.'), /待确认.*勿重复提交/);
});

// Restrictions use the same confirmation-error path for withdrawal, refund, card load and transfer.
test('operation restrictions display contact support in every consumer language', () => {
    const { ApiError, explainError, i18n } = harness();
    for (const [locale, text] of [['zh-CN', '请联系客服'], ['en', 'Please contact support.'], ['ms', 'Sila hubungi sokongan.'], ['es', 'Contacta con soporte.']]) {
        i18n.locale.value = locale;
        assert.equal(explainError(new ApiError(403, { error: { code: 'USER_OPERATION_RESTRICTED', message: 'Please contact support.' } })).form, text);
    }
});
