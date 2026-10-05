import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function stateFor(kyc) {
    const exports = {};
    const source = readFileSync('mobile/uni-app/src/screens/Kyc.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
    const js = ts.transpileModule(source + '\nexport { state };', {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    runInNewContext(js, {
        exports, defineProps: () => ({ page: { kyc } }), defineEmits: () => () => {},
        require: id => {
            if (id === 'vue') return { ref: value => ({ value }), reactive: value => value, computed: getter => ({ get value() { return getter(); } }) };
            if (id.endsWith('/sensitive')) return { useSensitiveScreen() {} };
            if (id.endsWith('/client')) return { useAction: () => ({}) };
            return {};
        },
    });
    return exports.state.value;
}

test('account-limit failures explain the business restriction for initial and repeat verification', () => {
    for (const status of ['PENDING', 'APPROVED']) {
        const state = stateFor({ status, processingStatus: 'FAILED', processingError: 'IDENTITY_ACCOUNT_LIMIT_REACHED' });
        assert.equal(state.title, 'Identity document account limit reached');
        assert.match(state.description, /Uploading it again will not resolve this/);
        for (const lang of ['en', 'zh-Hans', 'es', 'ms']) {
            const locale = JSON.parse(readFileSync(`mobile/uni-app/src/locale/${lang}.json`));
            assert.ok(locale[state.title]);
            assert.ok(locale[state.description]);
        }
    }
});

test('recognition validation and service outages give distinct next steps', () => {
    const state = error => stateFor({ status: 'PENDING', processingStatus: 'FAILED', processingError: error });
    assert.match(state('KYC_OCR_MISMATCH').description, /upload clear photos/);
    assert.match(state('KYC_OCR_UNAVAILABLE').description, /temporarily unavailable/);
    assert.match(state('KYC_DOCUMENT_STORAGE_FAILED').description, /could not be read/);
    assert.match(state('KYC_DOCUMENT_INTEGRITY_FAILED').description, /could not be verified/);
    assert.match(state('KYC_SUBMISSION_UNAVAILABLE').description, /currently unavailable/);
});

test('unknown errors stay generic and stale failures do not override active or approved states', () => {
    const unknown = stateFor({ status: 'PENDING', processingStatus: 'FAILED', processingError: 'internal-sensitive-error' });
    assert.doesNotMatch(unknown.description, /internal-sensitive-error/);
    assert.match(unknown.description, /contact support/);
    assert.equal(stateFor({ status: 'PENDING', processingStatus: 'QUEUED', processingError: 'IDENTITY_ACCOUNT_LIMIT_REACHED' }).title, 'Processing status');
    assert.equal(stateFor({ status: 'APPROVED', processingStatus: null }).title, 'Identity verified');
});
